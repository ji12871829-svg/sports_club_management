<?php

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/admin_2fa.php';

asc_session_start();
csrf_ensure('admin_csrf');

// Mid-login: password OK, awaiting TOTP
if (admin_2fa_pending_valid()) {
    if (!admin_2fa_is_public_page()) {
        header('Location: admin_verify_2fa.php');
        exit;
    }
    return;
}

if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    if (!admin_2fa_is_public_page()) {
        header('Location: admin_login.php');
        exit;
    }
    return;
}

// ── Authenticated: enforce idle timeout ─────────────────────────────────
$now = time();
$lastActivity = (int) ($_SESSION['admin_last_activity'] ?? 0);
if ($lastActivity > 0 && ($now - $lastActivity) > admin_session_idle_limit()) {
    require_once __DIR__ . '/../config/db_connect.php';
    require_once __DIR__ . '/activity_log.php';
    log_activity($conn, 'Admin session expired (idle timeout)', 'Auth', (int) ($_SESSION['admin_id'] ?? 0));
    $_SESSION = [];
    session_destroy();
    header('Location: admin_login.php');
    exit;
}
$_SESSION['admin_last_activity'] = $now;

// ── Authenticated: enforce auth epoch ("log out other sessions") ────────
if (isset($_SESSION['admin_id'], $_SESSION['admin_auth_epoch'])) {
    require_once __DIR__ . '/../config/db_connect.php';
    if (admin_auth_epoch_column_exists($conn)) {
        $stmt = $conn->prepare('SELECT auth_epoch FROM admins WHERE admin_id = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('i', $_SESSION['admin_id']);
            $stmt->execute();
            $stmt->bind_result($dbEpoch);
            // Missing admin row or changed epoch ⇒ revoke the stale session.
            if (!$stmt->fetch() || (int) $dbEpoch !== (int) $_SESSION['admin_auth_epoch']) {
                $stmt->close();
                require_once __DIR__ . '/activity_log.php';
                log_activity($conn, 'Admin session revoked (auth epoch changed)', 'Auth', (int) $_SESSION['admin_id']);
                $_SESSION = [];
                session_destroy();
                header('Location: admin_login.php');
                exit;
            }
            $stmt->close();
        }
    }
}
