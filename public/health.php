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

// ── 6. Database table count ─────────────────────────────────────────
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