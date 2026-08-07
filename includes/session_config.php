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
