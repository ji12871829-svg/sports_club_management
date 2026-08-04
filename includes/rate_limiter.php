<?php
// ============================================================
//  includes/rate_limiter.php
//  Reusable helpers for authentication + endpoint rate limiting.
// ============================================================

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/security_events.php';

/**
 * Ensure the login_attempts table has the columns needed by the rate limiter.
 */
function ensure_login_attempts_schema(mysqli $conn): void
{
    $conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(100) NOT NULL,
        ip_address VARCHAR(45) NOT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        action_type VARCHAR(20) NOT NULL DEFAULT 'login',
        INDEX idx_email_time (email, attempted_at),
        INDEX idx_ip_time (ip_address, attempted_at)
    )");

    $res = $conn->query("SHOW COLUMNS FROM login_attempts LIKE 'action_type'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE login_attempts ADD COLUMN action_type VARCHAR(20) NOT NULL DEFAULT 'login'");
    }

    $res = $conn->query("SHOW COLUMNS FROM login_attempts LIKE 'attempted_at'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE login_attempts ADD COLUMN attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    }
}

/**
 * Safely prepare statements even when the schema is being repaired.
 */
function safe_prepare(mysqli $conn, string $query)
{
    ensure_login_attempts_schema($conn);

    try {
        return $conn->prepare($query);
    } catch (mysqli_sql_exception $e) {
        ensure_login_attempts_schema($conn);
        return $conn->prepare($query);
    }
}

/**
 * Checks the number of failed attempts by email and IP within 15 minutes.
 * Returns lockout state.
 */
function check_login_attempts(mysqli $conn, string $email): array
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    // Occasionally perform garbage collection of old attempts
    if (rand(1, 100) === 1) {
        $conn->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }
    
    // Only count actual login failures — the table also holds rows written by
    // other limiters (action_type 'api', 'register', 'password_reset'), which
    // must not contribute to the login lockout. ('login' is the column default,
    // so legacy rows from register_login_attempt are covered.)
    $stmt = safe_prepare($conn, "SELECT COUNT(*) FROM login_attempts WHERE (email = ? OR ip_address = ?) AND action_type = 'login' AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    if (!$stmt) {
        ensure_login_attempts_schema($conn);
        $stmt = safe_prepare($conn, "SELECT COUNT(*) FROM login_attempts WHERE (email = ? OR ip_address = ?) AND action_type = 'login' AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    }
    
    if ($stmt) {
        $stmt->bind_param("ss", $email, $ip);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();
        
        if ($count >= 5) {
            // Lockout reached — record as a throttled security event for the
            // digest (at most one per account per minute under a brute-force
            // flood, so the telemetry never amplifies the DB load).
            log_security_event_throttled('auth_lockout', 'warning', 'Login rate limit reached for account', $email);
            return ['allowed' => false, 'remaining' => 0];
        }
        return ['allowed' => true, 'remaining' => 5 - $count];
    }
    return ['allowed' => true, 'remaining' => 5];
}

/**
 * Log a failed login attempt in the database.
 */
function register_login_attempt(mysqli $conn, string $email): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = safe_prepare($conn, "INSERT INTO login_attempts (email, ip_address) VALUES (?, ?)");
    if ($stmt) {
        $stmt->bind_param("ss", $email, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Clear failed login attempts after successful authentication.
 */
function clear_login_attempts(mysqli $conn, string $email): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = safe_prepare($conn, "DELETE FROM login_attempts WHERE email = ? OR ip_address = ?");
    if ($stmt) {
        $stmt->bind_param("ss", $email, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Check if an IP has exceeded registration rate limits.
 * Allows max 3 registrations per IP within 1 hour.
 */
function check_registration_rate(mysqli $conn): array
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Garbage collection
    if (rand(1, 100) === 1) {
        $conn->query("DELETE FROM login_attempts WHERE action_type = 'register' AND attempted_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)");
    }

    $stmt = safe_prepare($conn, "SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND action_type = 'register' AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    if (!$stmt) {
        ensure_login_attempts_schema($conn);
        $stmt = safe_prepare($conn, "SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND action_type = 'register' AND attempted_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }

    if ($stmt) {
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count >= 3) {
            return ['allowed' => false, 'remaining' => 0];
        }
        return ['allowed' => true, 'remaining' => 3 - $count];
    }
    return ['allowed' => true, 'remaining' => 3];
}

/**
 * Log a registration attempt for rate limiting.
 */
function register_registration_attempt(mysqli $conn): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = safe_prepare($conn, "INSERT INTO login_attempts (email, ip_address, action_type) VALUES (?, ?, 'register')");
    if ($stmt) {
        $dummy = 'register_' . $ip;
        $stmt->bind_param("ss", $dummy, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Check if an IP has exceeded password-reset request rate limits.
 * Allows max 5 requests per IP within 15 minutes.
 */
function check_password_reset_rate(mysqli $conn): array
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $stmt = safe_prepare($conn, "SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND action_type = 'password_reset' AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    if (!$stmt) {
        ensure_login_attempts_schema($conn);
        $stmt = safe_prepare($conn, "SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND action_type = 'password_reset' AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    }

    if ($stmt) {
        $stmt->bind_param("s", $ip);
        $stmt->execute();
        $stmt->bind_result($count);
        $stmt->fetch();
        $stmt->close();

        if ($count >= 5) {
            // Lockout reached — record as a throttled security event for the
            // digest (at most one per account per minute under a brute-force
            // flood, so the telemetry never amplifies the DB load).
            log_security_event_throttled('auth_lockout', 'warning', 'Login rate limit reached for account', $email);
            return ['allowed' => false, 'remaining' => 0];
        }
        return ['allowed' => true, 'remaining' => 5 - $count];
    }
    return ['allowed' => true, 'remaining' => 5];
}

/**
 * Log a password-reset request for rate limiting.
 */
function register_password_reset_attempt(mysqli $conn): void
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt = safe_prepare($conn, "INSERT INTO login_attempts (email, ip_address, action_type) VALUES (?, ?, 'password_reset')");
    if ($stmt) {
        $dummy = 'pwdreset_' . $ip;
        $stmt->bind_param("ss", $dummy, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Generic per-key endpoint rate limiter.
 *
 * Allows up to $max requests per $windowSec for a given bucket key. The
 * bucket should include a client identifier (e.g. 'chatbot_' . md5($ip))
 * so limits apply per caller. Reuses the login_attempts table with
 * action_type = 'api' — no extra schema required.
 *
 * Returns true when the request is allowed, false when the limit is hit
 * (caller should respond 429). Fails open if the DB is unavailable so a
 * database hiccup does not take down legitimate traffic.
 */
function rate_limit_check(string $bucket, int $max = 10, int $windowSec = 60): bool
{
    $conn = $GLOBALS['conn'] ?? null;
    if (!$conn instanceof mysqli) {
        return true;
    }

    // Opportunistic garbage collection of old API rows.
    if (rand(1, 100) === 1) {
        $conn->query("DELETE FROM login_attempts WHERE action_type = 'api' AND attempted_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    }

    // Drop hits outside the window for this bucket, then count recent ones.
    $prune = safe_prepare($conn, "DELETE FROM login_attempts WHERE email = ? AND action_type = 'api' AND attempted_at < DATE_SUB(NOW(), INTERVAL ? SECOND)");
    if ($prune) {
        $prune->bind_param('si', $bucket, $windowSec);
        $prune->execute();
        $prune->close();
    }

    $count_stmt = safe_prepare($conn, "SELECT COUNT(*) FROM login_attempts WHERE email = ? AND action_type = 'api'");
    if (!$count_stmt) {
        return true;
    }
    $count_stmt->bind_param('s', $bucket);
    $count_stmt->execute();
    $count_stmt->bind_result($count);
    $count_stmt->fetch();
    $count_stmt->close();

    if ($count >= $max) {
        // Limit hit — record as a throttled security event (once per bucket
        // per minute), so an attacker flooding the endpoint cannot turn the
        // telemetry into a DB-write amplifier.
        log_security_event_throttled('rate_limit', 'warning', 'Endpoint rate limit exceeded (' . $max . '/min bucket)', $bucket);
        return false;
    }

    // Record this hit.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ins = safe_prepare($conn, "INSERT INTO login_attempts (email, ip_address, action_type) VALUES (?, ?, 'api')");
    if ($ins) {
        $ins->bind_param('ss', $bucket, $ip);
        $ins->execute();
        $ins->close();
    }
    return true;
}

/**
 * Best-effort client IP resolution, hashed into a stable per-client key.
 *
 * X-Forwarded-For is only trusted when the app is explicitly behind a
 * reverse proxy that sanitizes it (ASC_TRUST_PROXY=1 in .env). Otherwise
 * an attacker could rotate the header on every request to evade per-IP
 * limits — defeating the AI-quota protection entirely.
 */
function client_rate_key(string $prefix): string
{
    $trustProxy = getenv('ASC_TRUST_PROXY') === '1';
    $ip = $trustProxy
        ? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown')
        : ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    // Take the first hop if a proxy chain is present.
    $ip = trim(explode(',', $ip)[0] ?? $ip);
    return $prefix . '_' . md5($ip);
}
