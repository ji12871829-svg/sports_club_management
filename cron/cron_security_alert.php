<?php
/**
 * cron/cron_security_alert.php
 * Daily admin security digest email.
 *
 * Aggregates the previous 24 hours into one branded report:
 *   - unacknowledged security_events (rate limits, CSRF rejects, lockouts, …)
 *   - new device admin logins (admin_sessions)
 *   - revoked sessions (admin_sessions.revoked_at)
 *   - failed login attempts (login_attempts)
 *
 * Sent to every admin account. Acked events are excluded, so the digest only
 * surfaces what still needs attention.
 *
 * Schedule daily at 08:00:
 *   Windows Task Scheduler:
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: "C:\xampp\htdocs\Apex Sports Club\cron\cron_security_alert.php"
 *
 *   Linux cron: 0 8 * * * php /path/to/cron/cron_security_alert.php
 */

define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/feature_helpers.php';

$log = [];
$log[] = '[' . date('Y-m-d H:i:s') . '] Security digest job started.';

// ── 1. Unacknowledged security events (24h) ─────────────────────────────
$events = [];
if (db_table_exists($conn, 'security_events')) {
    $events = $conn->query(
        "SELECT event_type, severity, COUNT(*) c
         FROM security_events
         WHERE created_at >= NOW() - INTERVAL 24 HOUR
           AND acknowledged = 0
         GROUP BY event_type, severity
         ORDER BY c DESC
         LIMIT 15"
    )->fetch_all(MYSQLI_ASSOC);
}
$total_events = array_sum(array_column($events, 'c'));
$log[] = '  [security_events] ' . $total_events . ' unacknowledged event(s) in 24h';

// ── 2. New device logins (24h) ──────────────────────────────────────────
$new_devices = 0;
if (db_table_exists($conn, 'admin_sessions')) {
    $new_devices = (int) ($conn->query(
        "SELECT COUNT(*) FROM admin_sessions
         WHERE created_at >= NOW() - INTERVAL 24 HOUR"
    )->fetch_row()[0] ?? 0);
}
$log[] = '  [admin_sessions] ' . $new_devices . ' session(s) started in 24h';

// ── 3. Revoked sessions (24h) ───────────────────────────────────────────
$revoked = 0;
if (db_table_exists($conn, 'admin_sessions')) {
    $revoked = (int) ($conn->query(
        "SELECT COUNT(*) FROM admin_sessions
         WHERE revoked_at >= NOW() - INTERVAL 24 HOUR"
    )->fetch_row()[0] ?? 0);
}
$log[] = '  [admin_sessions] ' . $revoked . ' session(s) revoked in 24h';

// ── 4. Failed login attempts (24h) ──────────────────────────────────────
$failed_logins = 0;
if (db_table_exists($conn, 'login_attempts')) {
    $failed_logins = (int) ($conn->query(
        "SELECT COUNT(*) FROM login_attempts
         WHERE action_type = 'login' AND attempted_at >= NOW() - INTERVAL 24 HOUR"
    )->fetch_row()[0] ?? 0);
}
$log[] = '  [login_attempts] ' . $failed_logins . ' failed login(s) in 24h';

$log[] = '[' . date('Y-m-d H:i:s') . '] Security digest finished.';
echo implode("\n", $log) . "\n";

// ── Send the digest only when there is something to report ──────────────
if ($total_events === 0 && $new_devices === 0 && $revoked === 0 && $failed_logins === 0) {
    echo "Nothing to report — skipping email.\n";
    if (!defined('CRON_MANUAL_RUN')) {
        exit(0);
    }
}

$admins = $conn->query('SELECT email FROM admins WHERE email IS NOT NULL AND email <> ""')->fetch_all(MYSQLI_ASSOC);
if (!$admins) {
    echo "No admin emails found — skipping email.\n";
    if (!defined('CRON_MANUAL_RUN')) {
        exit(0);
    }
}

// Build the HTML report.
$rows = '';
$rows .= '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;color:#64748b;">Security events (unacknowledged)</td>'
    . '<td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;font-weight:600;">' . $total_events . '</td></tr>';
$rows .= '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;color:#64748b;">New device logins</td>'
    . '<td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;font-weight:600;">' . $new_devices . '</td></tr>';
$rows .= '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;color:#64748b;">Sessions revoked</td>'
    . '<td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;font-weight:600;">' . $revoked . '</td></tr>';
$rows .= '<tr><td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;color:#64748b;">Failed admin logins</td>'
    . '<td style="padding:8px 12px;border:1px solid #e2e8f0;font-size:13px;font-weight:600;">' . $failed_logins . '</td></tr>';

$event_detail = '';
if ($events) {
    $event_detail = '<div style="margin-top:14px;"><p style="font-size:13px;color:#334155;font-weight:600;">Event breakdown</p>';
    foreach ($events as $ev) {
        $badge = $ev['severity'] === 'critical'
            ? '<span style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">' . htmlspecialchars($ev['severity']) . '</span>'
            : ($ev['severity'] === 'warning'
                ? '<span style="background:#fffbeb;color:#b45309;border:1px solid #fde68a;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">warning</span>'
                : '<span style="background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;border-radius:999px;padding:2px 8px;font-size:11px;font-weight:700;">info</span>');
        $event_detail .= '<p style="font-size:13px;color:#475569;margin:4px 0;">'
            . htmlspecialchars($ev['event_type']) . ' × ' . $ev['c']
            . ' &nbsp;' . $badge . '</p>';
    }
    $event_detail .= '</div>';
}

$subject = '🔐 Daily security digest — Apex Sports Club';
$body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;">'
    . '<div style="background:#0f172a;padding:20px 24px;"><h1 style="color:#fff;margin:0;font-size:17px;">🔐 Daily security digest</h1>'
    . '<p style="color:#94a3b8;margin:4px 0 0;font-size:12px;">' . date('d M Y') . ' — last 24 hours</p></div>'
    . '<div style="padding:24px;">'
    . '<p style="font-size:14px;color:#334155;">Here is what happened across your club system in the last 24 hours:</p>'
    . '<table style="width:100%;border-collapse:collapse;margin-top:12px;">' . $rows . '</table>'
    . $event_detail
    . '<p style="font-size:13px;color:#475569;margin-top:16px;">Review details and acknowledge events in the '
    . '<a href="' . (defined('APP_URL') ? APP_URL . '/admin/security_events.php' : '/admin/security_events.php') . '" style="color:#1d5c8f;">Security Events</a> page.</p>'
    . '</div>'
    . '<div style="background:#f8fafc;padding:12px 24px;color:#94a3b8;font-size:12px;">Apex Sports Club — Admin Security</div>'
    . '</div>';

$sent = 0;
foreach ($admins as $admin) {
    $email = (string) $admin['email'];
    if ($email === '') {
        continue;
    }
    if (sendEmail($email, 'Club Admin', $subject, $body)) {
        $sent++;
    }
}
$log[] = '  Sent digest to ' . $sent . '/' . count($admins) . ' admin(s).';
echo implode("\n", $log) . "\n";
