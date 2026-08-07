<?php

function asc_session_start(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Harden the session engine itself (best-effort; not always configurable at runtime).
    if (PHP_SESSION_ACTIVE !== session_status()) {
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_only_cookies', '1');
        @ini_set('session.cookie_httponly', '1');
        if ($secure) {
            @ini_set('session.cookie_secure', '1');
        }
    }

    session_start();
}

/**
 * Number of seconds an admin session may stay idle before being invalidated.
 */
function admin_session_idle_limit(): int
{
    return 1800; // 30 minutes
}

/**
 * Whether the admin_sessions table exists (migration 063).
 */
function admin_sessions_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $res = $conn->query("SHOW TABLES LIKE 'admin_sessions'");
    $ready = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }

    return $ready;
}

/**
 * Stable per-session identifier: sha256 of the PHP session id, so the raw
 * session id is never persisted.
 */
function admin_session_token(): string
{
    return hash('sha256', session_id());
}

/**
 * Record the current session in admin_sessions (called at login).
 */
function admin_sessions_record(mysqli $conn, int $admin_id): void
{
    if (!admin_sessions_schema_ready($conn)) {
        return;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // Store the normalized device label (OS · browser) rather than the raw
    // UA string, so new-device detection survives browser upgrades.
    $device = admin_session_ua_label((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $token = admin_session_token();
    $stmt = $conn->prepare(
        'INSERT INTO admin_sessions (admin_id, session_token, ip_address, user_agent)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE last_activity = CURRENT_TIMESTAMP, ip_address = VALUES(ip_address), user_agent = VALUES(user_agent)'
    );
    if ($stmt) {
        $stmt->bind_param('isss', $admin_id, $token, $ip, $device);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Refresh last_activity for the current session (guarded against writing on
 * every request — at most once per minute per session).
 */
function admin_sessions_touch(mysqli $conn, int $admin_id): void
{
    if (!admin_sessions_schema_ready($conn)) {
        return;
    }
    $last = (int) ($_SESSION['admin_sessions_touched_at'] ?? 0);
    $now = time();
    if ($now - $last < 60) {
        return;
    }
    $_SESSION['admin_sessions_touched_at'] = $now;
    $token = admin_session_token();
    $stmt = $conn->prepare('UPDATE admin_sessions SET last_activity = CURRENT_TIMESTAMP WHERE session_token = ? AND admin_id = ?');
    if ($stmt) {
        $stmt->bind_param('si', $token, $admin_id);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Whether the current session has been individually revoked.
 */
function admin_sessions_is_revoked(mysqli $conn, int $admin_id): bool
{
    if (!admin_sessions_schema_ready($conn)) {
        return false;
    }
    $token = admin_session_token();
    $stmt = $conn->prepare('SELECT revoked_at FROM admin_sessions WHERE session_token = ? AND admin_id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('si', $token, $admin_id);
    $stmt->execute();
    $stmt->bind_result($revokedAt);
    $revoked = $stmt->fetch() && $revokedAt !== null;
    $stmt->close();

    return $revoked;
}

/**
 * List this admin's active sessions (most recent first).
 */
function admin_sessions_list(mysqli $conn, int $admin_id): array
{
    if (!admin_sessions_schema_ready($conn)) {
        return [];
    }
    $token = admin_session_token();
    $stmt = $conn->prepare(
        'SELECT id, session_token, ip_address, user_agent, created_at, last_activity, revoked_at,
                (session_token = ?) AS is_current
         FROM admin_sessions
         WHERE admin_id = ? AND revoked_at IS NULL
         ORDER BY last_activity DESC
         LIMIT 20'
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('si', $token, $admin_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows ?: [];
}

/**
 * Revoke a specific session (the caller must be the owner).
 */
function admin_sessions_revoke(mysqli $conn, int $admin_id, int $sessionId): bool
{
    if (!admin_sessions_schema_ready($conn)) {
        return false;
    }
    $stmt = $conn->prepare('UPDATE admin_sessions SET revoked_at = CURRENT_TIMESTAMP WHERE id = ? AND admin_id = ? AND revoked_at IS NULL');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $sessionId, $admin_id);
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();

    return $ok;
}

/**
 * Short human label for a user agent (best effort).
 */
function admin_session_ua_label(string $ua): string
{
    if ($ua === '') {
        return 'Unknown device';
    }
    $lower = strtolower($ua);
    // strpos !== false keeps these helpers compatible with the declared PHP >= 7.4 floor.
    if (strpos($lower, 'android') !== false) {
        $os = 'Android';
    } elseif (strpos($lower, 'iphone') !== false || strpos($lower, 'ipad') !== false || strpos($lower, 'ios') !== false) {
        $os = 'iOS';
    } elseif (strpos($lower, 'mac os') !== false) {
        $os = 'macOS';
    } elseif (strpos($lower, 'windows') !== false) {
        $os = 'Windows';
    } elseif (strpos($lower, 'linux') !== false) {
        $os = 'Linux';
    } else {
        $os = 'Device';
    }
    $browser = 'Browser';
    if (strpos($lower, 'edg/') !== false || strpos($lower, 'edge/') !== false) {
        $browser = 'Edge';
    } elseif (strpos($lower, 'firefox/') !== false) {
        $browser = 'Firefox';
    } elseif (strpos($lower, 'chrome/') !== false || strpos($lower, 'chromium') !== false) {
        $browser = 'Chrome';
    } elseif (strpos($lower, 'safari/') !== false) {
        $browser = 'Safari';
    }

    return $os . ' · ' . $browser;
}

/**
 * "2 minutes ago"-style label for a timestamp.
 */
function admin_session_time_ago(string $ts): string
{
    if ($ts === '') {
        return '—';
    }
    $diff = time() - strtotime($ts);
    if ($diff < 60) {
        return 'just now';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . ' min ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' hr ago';
    }

    return (int) floor($diff / 86400) . ' days ago';
}

/**
 * Age of a session since creation, in a compact "Xd Yh" style.
 */
function admin_session_age(string $ts): string
{
    if ($ts === '') {
        return '—';
    }
    $diff = time() - strtotime($ts);
    if ($diff < 3600) {
        return (int) max(1, floor($diff / 60)) . ' min';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' hr';
    }
    $days = (int) floor($diff / 86400);
    $hours = (int) floor(($diff % 86400) / 3600);

    return $days . 'd ' . $hours . 'h';
}

/**
 * Best-effort geographic hint for an IP address.
 *
 * Tries a local MaxMind GeoLite2 database (GeoIp2\Database\Reader from the
 * geoip2/geoip2 composer package against a bundled .mmdb file) first. Falls
 * back to labelling private/reserved ranges as "Local network" so no
 * external API call is ever made and the panel degrades gracefully.
 *
 * @param string $ip          IPv4/IPv6 address
 * @param string $mmdbPath    Optional path to a GeoLite2-Country.mmdb file
 */
function admin_session_geo_hint(string $ip, string $mmdbPath = ''): string
{
    $ip = trim($ip);
    if ($ip === '') {
        return 'Unknown';
    }

    // 1) Local / private / reserved ranges — no lookup needed.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return 'Local network';
    }
    if ($ip === '::1' || $ip === '127.0.0.1') {
        return 'Local network';
    }

    // 2) Optional local GeoLite2 mmdb (only if the reader class is installed
    //    AND a database file is provided/exists — never make network calls).
    if ($mmdbPath === '') {
        $mmdbPath = (string) (getenv('ASC_GEOIP_DB') ?: (__DIR__ . '/../data/GeoLite2-Country.mmdb'));
    }
    if (class_exists('GeoIp2\Database\Reader') && is_file($mmdbPath)) {
        try {
            $reader = new GeoIp2\Database\Reader($mmdbPath);
            $record = $reader->country($ip);
            $country = $record->country->name;

            return is_string($country) && $country !== '' ? $country : 'Unknown';
        } catch (\Throwable $e) {
            // Unreadable DB or address not in range — fall through.
        }
    }

    return '—';
}

/**
 * Returns true when the current device (IP + user agent) has never been seen
 * for this admin before AND the account has prior session history (so the
 * very first login after enabling tracking is not flagged).
 */
function admin_sessions_is_new_device(mysqli $conn, int $admin_id): bool
{
    if (!admin_sessions_schema_ready($conn)) {
        return false;
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // Normalize the device key to OS + browser family so a browser upgrade
    // (which changes the UA string) doesn't masquerade as a new device.
    $device = admin_session_ua_label((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $token = admin_session_token();

    // Total sessions (excluding the just-inserted current one).
    $totalStmt = $conn->prepare('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = ? AND session_token <> ?');
    if (!$totalStmt) {
        return false;
    }
    $totalStmt->bind_param('is', $admin_id, $token);
    $totalStmt->execute();
    $totalStmt->bind_result($totalOther);
    $totalStmt->fetch();
    $totalStmt->close();

    if ($totalOther < 1) {
        return false; // first-ever recorded session, not a "new device"
    }

    // Has this exact device (IP + device label) appeared before?
    $seenStmt = $conn->prepare('SELECT COUNT(*) FROM admin_sessions WHERE admin_id = ? AND ip_address = ? AND user_agent = ? AND session_token <> ?');
    if (!$seenStmt) {
        return false;
    }
    $seenStmt->bind_param('isss', $admin_id, $ip, $device, $token);
    $seenStmt->execute();
    $seenStmt->bind_result($seenBefore);
    $seenStmt->fetch();
    $seenStmt->close();

    return $seenBefore < 1;
}

/**
 * Sends a "new device signed in" security email to the admin account holder
 * and records an activity-log entry. Best-effort: never throws.
 */
function admin_sessions_alert_new_device(mysqli $conn, int $admin_id, string $admin_email): void
{
    if (!admin_sessions_is_new_device($conn, $admin_id)) {
        return;
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $device = admin_session_ua_label($ua);
    $geo = admin_session_geo_hint($ip);

    try {
        if (!function_exists('log_activity')) {
            require_once __DIR__ . '/activity_log.php';
        }
        if (function_exists('log_activity')) {
            log_activity($conn, 'New device admin login detected', 'Auth', $admin_id, 'IP ' . $ip . ' Device ' . $device);
        }

        if (!function_exists('sendEmail')) {
            require_once __DIR__ . '/send_email.php';
        }
        if (!function_exists('sendEmail')) {
            return;
        }

        $subject = '🔐 New device signed in to your Apex admin account';
        $body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">'
            . '<div style="background:#1d5c8f;padding:20px 24px;"><h1 style="color:#fff;margin:0;font-size:17px;">New device sign-in detected</h1></div>'
            . '<div style="padding:24px;">'
            . '<p style="font-size:14px;color:#334155;">Your Apex Sports Club <strong>admin</strong> account was just used to sign in from a device we haven\'t seen before:</p>'
            . '<table style="width:100%;border-collapse:collapse;margin-top:12px;">'
            . '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;color:#64748b;">Date &amp; time</td><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;">' . date('d M Y, H:i') . '</td></tr>'
            . '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;color:#64748b;">IP address</td><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;"><code>' . htmlspecialchars($ip) . '</code></td></tr>'
            . '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;color:#64748b;">Location</td><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;">' . htmlspecialchars($geo) . '</td></tr>'
            . '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;color:#64748b;">Device</td><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;">' . htmlspecialchars($device) . '</td></tr>'
            . '</table>'
            . '<p style="font-size:13px;color:#334155;margin-top:16px;">If this was you, you can ignore this email. If you don\'t recognise the sign-in, <strong>change your password immediately</strong> from the admin profile page and review your active sessions.</p>'
            . '</div>'
            . '<div style="background:#f8fafc;padding:12px 24px;color:#94a3b8;font-size:12px;">Apex Sports Club — Admin Security</div>'
            . '</div>';
        sendEmail($admin_email, 'Club Admin', $subject, $body);
    } catch (\Throwable $e) {
        error_log('[admin_sessions] new-device alert failed: ' . $e->getMessage());
    }
}

/**
 * Whether the admins table has the auth_epoch column (migration 062).
 */
function admin_auth_epoch_column_exists(mysqli $conn): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $res = $conn->query("SHOW COLUMNS FROM admins LIKE 'auth_epoch'");
    $exists = $res && $res->num_rows > 0;
    if ($res) {
        $res->free();
    }

    return $exists;
}

/**
 * Store the admin's current auth_epoch in the session. Called at login and
 * after "log out other sessions" so the current session keeps a fresh token.
 */
function admin_auth_epoch_store(mysqli $conn, int $admin_id): void
{
    if (!admin_auth_epoch_column_exists($conn)) {
        return;
    }
    $stmt = $conn->prepare('SELECT auth_epoch FROM admins WHERE admin_id = ? LIMIT 1');
    if (!$stmt) {
        return;
    }
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $stmt->bind_result($epoch);
    if ($stmt->fetch()) {
        $_SESSION['admin_auth_epoch'] = (int) $epoch;
    }
    $stmt->close();
}
