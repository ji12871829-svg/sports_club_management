<?php

/**
 * public/health.php
 * JSON health check endpoint — returns system status for CI/monitoring.
 *
 * Usage:
 *   curl http://localhost/health.php
 *   curl http://localhost/health.php?check=db
 *
 * Response:
 *   {
 *     "status": "ok" | "degraded" | "error",
 *     "version": "1.0.0",
 *     "timestamp": "2026-08-04T12:00:00+03:00",
 *     "checks": { ... }
 *   }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── Config ──────────────────────────────────────────────────────────
$startTime = microtime(true);
$results   = [];
$overall   = 'ok';

// ── Helper: run a check and record result ────────────────────────────
function runCheck(string $name, string $type, callable $fn): array
{
    $start = microtime(true);
    try {
        $data = $fn();
        $durationMs = round((microtime(true) - $start) * 1000, 1);
        return [
            'status'   => 'pass',
            'type'     => $type,
            'duration_ms' => $durationMs,
            'data'     => $data,
        ];
    } catch (\Throwable $e) {
        $durationMs = round((microtime(true) - $start) * 1000, 1);
        return [
            'status'   => 'fail',
            'type'     => $type,
            'duration_ms' => $durationMs,
            'error'    => $e->getMessage(),
        ];
    }
}

// ── Load DB connection once (shared between database and schema checks) ──
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/mpesa.php'; // for mpesa_callback_url_error()

// ── 1. Database connectivity ────────────────────────────────────────
$results['database'] = runCheck('database', 'connectivity', function () use ($conn) {
    $ping = $conn->ping();
    if (!$ping) {
        throw new \RuntimeException('MySQL ping failed');
    }

    return [
        'server_version' => $conn->server_info,
        'ping_ok'        => true,
    ];
});

// ── 2. File upload directory ────────────────────────────────────────
$results['uploads'] = runCheck('uploads', 'filesystem', function () {
    $uploadDir = __DIR__ . '/../uploads';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Uploads directory does not exist and could not be created');
        }
    }
    if (!is_writable($uploadDir)) {
        throw new \RuntimeException('Uploads directory is not writable');
    }
    return [
        'path'      => realpath($uploadDir),
        'writable'  => true,
        'free_bytes' => disk_free_space($uploadDir) ?: 0,
    ];
});

// ── 2b. Backup directory (DR readiness) ─────────────────────────────
$results['backups'] = runCheck('backups', 'filesystem', function () {
    $backupDir = __DIR__ . '/../backups/db';
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
            throw new \RuntimeException('Backup directory does not exist and could not be created');
        }
    }
    if (!is_writable($backupDir)) {
        throw new \RuntimeException('Backup directory is not writable');
    }
    $files = glob($backupDir . DIRECTORY_SEPARATOR . 'backup_*.sql') ?: [];
    $files = array_filter($files, 'is_readable');
    $newest = null;
    if ($files) {
        usort($files, static fn ($a, $b) => @filemtime($b) <=> @filemtime($a));
        $newest = basename($files[0]);
    }
    return [
        'path'       => realpath($backupDir),
        'writable'   => true,
        'free_bytes' => disk_free_space($backupDir) ?: 0,
        'backup_count' => count($files),
        'newest_backup' => $newest,
    ];
});

// ── 2c. Redis session store ─────────────────────────────────────────
// When REDIS_HOST is configured the operator WANTS Redis — a silent fallback
// to files is exactly what monitoring should alert on, so configured-but-
// unreachable reports fail (degrading the endpoint) while unconfigured is a
// clean informational pass. AUTH is issued before PING when REDIS_PASSWORD
// is set (mirrors AscRedisSessionHandler::connect()) so password-protected
// servers are not falsely reported as down.
$results['redis_sessions'] = runCheck('redis_sessions', 'sessions', function () {
    $host = getenv('REDIS_HOST');
    if ($host === false || trim($host) === '') {
        return ['configured' => false, 'mode' => 'files'];
    }
    $port     = (int) (getenv('REDIS_PORT') ?: 6379);
    $password = (string) (getenv('REDIS_PASSWORD') ?: '');
    $errno = 0;
    $errstr = '';
    $ctx = stream_context_create(['socket' => ['timeout' => 1]]);
    $socket = @stream_socket_client('tcp://' . trim($host) . ':' . $port, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $ctx);
    if (!is_resource($socket)) {
        throw new \RuntimeException('Redis configured but unreachable at ' . trim($host) . ':' . $port);
    }
    stream_set_timeout($socket, 1);
    if ($password !== '') {
        $authCmd = "*2\r\n\$4\r\nAUTH\r\n\$" . strlen($password) . "\r\n" . $password . "\r\n";
        @fwrite($socket, $authCmd);
        @fgets($socket); // consume AUTH reply; an error surfaces on the PING below
    }
    @fwrite($socket, "*1\r\n\$4\r\nPING\r\n");
    $reply = @fgets($socket);
    @fclose($socket);
    if ($reply === false || stripos((string) $reply, 'PONG') === false) {
        throw new \RuntimeException('Redis PING failed' . ($reply !== false ? ': ' . trim((string) $reply) : ''));
    }
    return [
        'configured' => true,
        'reachable'  => true,
        'mode'       => 'redis',
    ];
});

// ── 3. PHP extensions ───────────────────────────────────────────────
$results['php_extensions'] = runCheck('php_extensions', 'config', function () {
    $required = ['mysqli', 'mbstring', 'pdo_mysql', 'xml', 'gd', 'zip', 'json', 'curl'];
    $missing  = [];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    if (!empty($missing)) {
        throw new \RuntimeException('Missing PHP extensions: ' . implode(', ', $missing));
    }
    return [
        'php_version' => PHP_VERSION,
        'all_loaded'  => true,
    ];
});

// ── 4. Config file existence ─────────────────────────────────────────
$results['config'] = runCheck('config', 'filesystem', function () {
    $files = [
        '.env'              => __DIR__ . '/../.env',
        'migrations'        => __DIR__ . '/../migrations',
        'config/db_connect' => __DIR__ . '/../config/db_connect.php',
        'config/api_config' => __DIR__ . '/../config/api_config.php',
    ];
    $missing = [];
    foreach ($files as $label => $path) {
        if (!file_exists($path)) {
            $missing[] = $label;
        }
    }
    if (!empty($missing)) {
        if (count($missing) === 1 && $missing[0] === '.env') {
            return ['env_file_missing' => true, 'config_files_present' => true];
        }
        throw new \RuntimeException('Missing files: ' . implode(', ', $missing));
    }
    return ['env_file_present' => file_exists($files['.env']), 'config_files_present' => true];
});

// ── 5. API key presence (non-sensitive) ──────────────────────────────
$results['api_keys'] = runCheck('api_keys', 'config', function () {
    $keys = [
        'paystack_public' => defined('PAYSTACK_PUBLIC_KEY') && PAYSTACK_PUBLIC_KEY !== '' && PAYSTACK_PUBLIC_KEY !== 'pk_test_local',
        'paystack_secret' => defined('PAYSTACK_SECRET_KEY') && PAYSTACK_SECRET_KEY !== '' && PAYSTACK_SECRET_KEY !== 'sk_test_local',
        'mpesa_consumer'  => defined('MPESA_CONSUMER_KEY') && MPESA_CONSUMER_KEY !== '' && MPESA_CONSUMER_KEY !== 'test',
        'gemini'          => defined('GEMINI_API_KEY') && GEMINI_API_KEY !== '' && GEMINI_API_KEY !== 'test',
        'brevo'           => defined('BREVO_API_KEY') && BREVO_API_KEY !== '' && BREVO_API_KEY !== 'test',
        'api_sports'      => defined('API_SPORTS_KEY') && API_SPORTS_KEY !== '' && API_SPORTS_KEY !== 'test',
    ];
    $configured = array_filter($keys);
    return [
        'configured_count' => count($configured),
        'total_keys'       => count($keys),
        'keys'             => $keys,
    ];
});

// ── 5b. Payment provider configuration ───────────────────────────────
// Catches the most common payment-breaking misconfigurations before users
// hit them: http:// callback URLs (Safaricom rejects these with 400.002.02),
// placeholder/example domains, and missing provider keys.
$results['payment_config'] = runCheck('payment_config', 'config', function () {
    $mpesaCb       = defined('MPESA_CALLBACK_URL') ? trim(MPESA_CALLBACK_URL) : '';
    $paystackCb    = defined('PAYSTACK_CALLBACK_URL') ? trim(PAYSTACK_CALLBACK_URL) : '';
    $mpesaKey      = defined('MPESA_CONSUMER_KEY') ? trim(MPESA_CONSUMER_KEY) : '';
    $paystackKey   = defined('PAYSTACK_SECRET_KEY') ? trim(PAYSTACK_SECRET_KEY) : '';
    $mpesaHttps    = str_starts_with(strtolower($mpesaCb), 'https://');
    $placeholderRe = '/your-ngrok-domain|example\.com|placeholder/i';

    $problems = [];
    // M-Pesa: Safaricom's servers call back server-to-server, so the URL must
    // be https:// and publicly reachable (not localhost).
    if ($mpesaCb === '') {
        $problems[] = 'MPESA_CALLBACK_URL is empty';
    } else {
        $cbError = function_exists('mpesa_callback_url_error') ? mpesa_callback_url_error($mpesaCb) : null;
        if ($cbError !== null) {
            $problems[] = $cbError;
        }
    }

    // Paystack: the callback is a browser redirect (the user's own browser
    // follows it), so http://localhost works for local dev. Only flag
    // empty or placeholder domains.
    if ($paystackCb === '') {
        $problems[] = 'PAYSTACK_CALLBACK_URL is empty';
    } elseif (preg_match($placeholderRe, $paystackCb)) {
        $problems[] = 'PAYSTACK_CALLBACK_URL is still a placeholder domain';
    }

    if ($mpesaKey === '' || $mpesaKey === 'test') {
        $problems[] = 'MPESA_CONSUMER_KEY is not configured';
    }
    if ($paystackKey === '' || $paystackKey === 'sk_test_local') {
        $problems[] = 'PAYSTACK_SECRET_KEY is not configured';
    }

    if (!empty($problems)) {
        throw new \RuntimeException(implode('; ', $problems));
    }

    return [
        'mpesa_callback_url'     => $mpesaCb,
        'mpesa_callback_valid'   => $mpesaHttps,
        'paystack_callback_url'  => $paystackCb,
        'paystack_callback_valid' => true,
        'keys_configured'        => true,
    ];
});

// ── 6. Migration version (latest applied migration number) ──────────
$results['migration_version'] = runCheck('migration_version', 'database', function () use ($conn) {
    // Scan migration files for the highest numbered migration
    $files = glob(__DIR__ . '/../migrations/*.sql');
    $max = 0;
    $total = 0;
    foreach ($files as $f) {
        $total++;
        if (preg_match('/(\d{3})_/', basename($f), $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return ['latest_migration_ver' => $max, 'migration_files' => $total];
});

// ── 7. Rate-limit state (last 5 min of login_attempts) ──────────────
$results['rate_limit_state'] = runCheck('rate_limit_state', 'database', function () use ($conn) {
    $r = $conn->query('SELECT action_type, COUNT(*) c FROM login_attempts WHERE attempted_at > NOW() - INTERVAL 5 MINUTE GROUP BY action_type ORDER BY c DESC LIMIT 5');
    if (!$r) {
        return ['counts' => []];
    }
    $counts = [];
    while ($row = $r->fetch_assoc()) {
        $counts[$row['action_type']] = (int) $row['c'];
    }
    $r->free();
    return ['last_5min_counts' => $counts];
});

// ── 8. Last 10 security events ──────────────────────────────────────
$results['last_security_events'] = runCheck('last_security_events', 'database', function () use ($conn) {
    $r = $conn->query('SELECT event_type, severity, details, created_at FROM security_events ORDER BY id DESC LIMIT 10');
    if (!$r) {
        return ['events' => []];
    }
    $events = $r->fetch_all(MYSQLI_ASSOC);
    $r->free();
    return ['recent_events' => $events];
});

// ── 10. Slow pages (last 7 days) ────────────────────────────────────
$results['slow_pages'] = runCheck('slow_pages', 'database', function () use ($conn) {
    $r = $conn->query('SELECT COUNT(*) FROM page_timings WHERE created_at >= NOW() - INTERVAL 7 DAY');
    if (!$r) {
        return ['count_7day' => 0];
    }
    $c = (int) $r->fetch_row()[0];
    $r->free();
    return ['count_7day' => $c];
});

// ── 11. Database table count ────────────────────────────────────────
$results['schema'] = runCheck('schema', 'database', function () use ($conn) {
    $r = $conn->query('SHOW TABLES');
    if (!$r) {
        throw new \RuntimeException('Cannot query SHOW TABLES');
    }
    $tables = [];
    while ($row = $r->fetch_row()) {
        $tables[] = $row[0];
    }
    $r->free();
    return [
        'table_count' => count($tables),
        'tables'      => $tables,
    ];
});

$conn->close();

// ── Determine overall status ────────────────────────────────────────
$failCount = 0;
foreach ($results as $name => $check) {
    if ($check['status'] === 'fail') {
        $failCount++;
    }
}
if ($failCount > 2) {
    $overall = 'error';
} elseif ($failCount > 0) {
    $overall = 'degraded';
}

$durationMs = round((microtime(true) - $startTime) * 1000, 1);

// ── Response ─────────────────────────────────────────────────────────
$response = [
    'status'    => $overall,
    'version'   => '1.0.0',
    'timestamp' => date('c'),
    'duration_ms' => $durationMs,
    'checks'    => $results,
];

http_response_code($overall === 'error' ? 500 : ($overall === 'degraded' ? 200 : 200));
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
