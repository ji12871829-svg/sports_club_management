<?php
/**
 * cron/cron_session_cleanup.php
 * Prunes stale auth/session rows so the tables stay bounded:
 *   - admin_sessions older than 7 days (or revoked more than 1 day ago)
 *   - login_attempts older than 24 hours
 *
 * Schedule daily at 03:00:
 *   Windows Task Scheduler:
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: "C:\xampp\htdocs\Apex Sports Club\cron\cron_session_cleanup.php"
 *
 *   Linux cron: 0 3 * * * php /path/to/cron/cron_session_cleanup.php
 */

define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/feature_helpers.php';

$log = [];
$log[] = '[' . date('Y-m-d H:i:s') . '] Session cleanup job started.';

$pruned = [];

// ── 1. admin_sessions: expired (no activity for 7 days) or long-revoked ──
if (db_table_exists($conn, 'admin_sessions')) {
    $sql = "DELETE FROM admin_sessions
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL 7 DAY)
               OR (revoked_at IS NOT NULL AND revoked_at < DATE_SUB(NOW(), INTERVAL 1 DAY))";
    $conn->query($sql);
    $pruned['admin_sessions'] = (int) $conn->affected_rows;
    $log[] = '  [admin_sessions] deleted ' . $pruned['admin_sessions'] . ' stale row(s)';
}

// ── 2. login_attempts: anything older than 24h (all action types) ────────
if (db_table_exists($conn, 'login_attempts')) {
    $conn->query("DELETE FROM login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $pruned['login_attempts'] = (int) $conn->affected_rows;
    $log[] = '  [login_attempts] deleted ' . $pruned['login_attempts'] . ' stale row(s)';
}

$log[] = '[' . date('Y-m-d H:i:s') . '] Session cleanup job finished.';
echo implode("\n", $log) . "\n";
