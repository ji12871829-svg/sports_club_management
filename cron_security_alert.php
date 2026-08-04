<?php
/**
 * cron_security_alert.php
 * Daily security-events digest. Summarizes the last 24 hours of recorded
 * security events (rate-limit hits, CSRF rejections, callback failures,
 * auth lockouts) and emails the report via includes/send_email.php.
 *
 * DISABLED unless .env sets:  ASC_SECURITY_EMAIL_TO=you@example.com
 * Threshold override:         ASC_SECURITY_MIN_CRITICAL=0  (email even with 0 events)
 *
 * Schedule (Windows Task Scheduler or cron), e.g. daily at 6 AM:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Apex Sports Club\cron_security_alert.php
 *   /c/xampp/php/php.exe /c/xampp/htdocs/Apex Sports Club/cron_security_alert.php
 *
 * Run manually:  php cron_security_alert.php
 */

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/send_email.php';
require_once __DIR__ . '/includes/activity_log.php';

// ── Retention cleanup (runs regardless of digest enablement) ────────────────
// Keep events for ASC_SECURITY_RETENTION_DAYS (default 30). Runs once per week
// (Sunday, day 0) deterministically instead of probabilistically, so every
// deployment purges on a predictable schedule. Executed BEFORE the early exits
// below so the table can never grow unbounded when the digest is disabled or
// below its threshold.
$retentionDays = getenv('ASC_SECURITY_RETENTION_DAYS');
$retentionDays = ($retentionDays !== false && is_numeric($retentionDays) && (int) $retentionDays >= 7)
    ? (int) $retentionDays
    : 30;

if ((int) date('w') === 0) {
    $deleted = 0;
    $stmt = $conn->prepare("DELETE FROM security_events WHERE created_at < NOW() - INTERVAL ? DAY");
    if ($stmt) {
        $stmt->bind_param('i', $retentionDays);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();
    }
    $stmt2 = $conn->prepare("DELETE FROM security_alert_log WHERE sent_at < NOW() - INTERVAL ? DAY");
    if ($stmt2) {
        $stmt2->bind_param('i', $retentionDays);
        $stmt2->execute();
        $stmt2->close();
    }
    echo "Retention cleanup: purged {$deleted} event(s) older than {$retentionDays} days.\n";

    // Also log acknowledged events that were up for cleanup (visibility for
    // the admin review log).
    $ackStmt = $conn->prepare("SELECT COUNT(*) FROM security_events WHERE acknowledged = 1 AND created_at < NOW() - INTERVAL ? DAY");
    if ($ackStmt) {
        $ackStmt->bind_param('i', $retentionDays);
        $ackStmt->execute();
        $ackStmt->bind_result($ackCount);
        $ackStmt->fetch();
        $ackStmt->close();
    }
    $ackCount = $ackCount ?? 0;
    log_activity($conn, 'retention_cleanup', 'security_events', null,
        "Purged {$deleted} events older than {$retentionDays} days; {$ackCount} acknowledged events still retained.");
} else {
    echo "Retention cleanup: skipped (today is not Sunday).\n";
}

$to = getenv('ASC_SECURITY_EMAIL_TO');
if ($to === false || trim($to) === '') {
    fwrite(STDERR, "ASC_SECURITY_EMAIL_TO not set in .env — security digest disabled. Nothing to do.\n");
    exit(0);
}

$minCritical = getenv('ASC_SECURITY_MIN_CRITICAL');
$minCritical = ($minCritical !== false && is_numeric($minCritical)) ? (int) $minCritical : 0;

$rows = [];
// Only surface events that still need attention — acknowledged (reviewed)
// events are excluded so the digest stays a triage list.
$r = $conn->query(
    "SELECT event_type, severity, ip_address, actor, details, created_at
     FROM security_events
     WHERE created_at >= NOW() - INTERVAL 24 HOUR
       AND acknowledged = 0
     ORDER BY created_at DESC
     LIMIT 200"
);
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $rows[] = $row;
    }
    $r->free();
}

if (count($rows) < $minCritical) {
    echo count($rows) . " security event(s) in the last 24 hours — below ASC_SECURITY_MIN_CRITICAL={$minCritical}. No email sent.\n";
    $conn->close();
    exit(0);
}

// Aggregate counts per event_type for the summary header.
$byType = [];
$bySeverity = ['info' => 0, 'warning' => 0, 'critical' => 0];
foreach ($rows as $row) {
    $byType[$row['event_type']] = ($byType[$row['event_type']] ?? 0) + 1;
    $sev = $row['severity'] ?? 'warning';
    if (isset($bySeverity[$sev])) { $bySeverity[$sev]++; }
}
arsort($byType);

$typeRows = '';
foreach ($byType as $type => $count) {
    $typeRows .= '<tr>'
        . '<td style="padding:6px 8px;border:1px solid #e5e7eb;font-family:monospace;font-size:12px;">' . htmlspecialchars($type) . '</td>'
        . '<td style="padding:6px 8px;border:1px solid #e5e7eb;text-align:center;font-weight:700;">' . (int) $count . '</td>'
        . '</tr>';
}

$detailRows = '';
foreach ($rows as $row) {
    $sevColor = match ($row['severity'] ?? 'warning') {
        'critical' => '#dc2626',
        'warning'  => '#d97706',
        default    => '#64748b',
    };
    $detailRows .= '<tr>'
        . '<td style="padding:6px 8px;border:1px solid #e5e7eb;font-family:monospace;font-size:11px;color:' . $sevColor . ';font-weight:700;">' . htmlspecialchars($row['event_type']) . '</td>'
        . '<td style="padding:6px 8px;border:1px solid #e5e7eb;font-size:12px;">' . htmlspecialchars($row['details'] ?? '') . '</td>'
        . '<td style="padding:6px 8px;border:1px solid #e5e7eb;font-family:monospace;font-size:11px;">' . htmlspecialchars($row['ip_address'] ?? '') . '</td>'
        . '<td style="padding:6px 8px;border:1px solid #e5e7eb;font-size:11px;">' . htmlspecialchars(date('d M H:i', strtotime($row['created_at']))) . '</td>'
        . '</tr>';
}

$body = '
<div style="font-family:Arial,sans-serif;max-width:680px;margin:auto;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
  <div style="background:#7f1d1d;padding:20px 24px;">
    <h1 style="color:#fff;margin:0;font-size:18px;">🛡 Apex Sports Club — Security Digest</h1>
  </div>
  <div style="padding:24px;">
    <p style="font-size:14px;color:#334155;">' . count($rows) . ' security event(s) in the last 24 hours
      (<strong>' . (int) $bySeverity['critical'] . '</strong> critical, <strong>' . (int) $bySeverity['warning'] . '</strong> warning, <strong>' . (int) $bySeverity['info'] . '</strong> info).</p>
    <h3 style="font-size:14px;margin:16px 0 8px;">By type</h3>
    <table style="width:100%;border-collapse:collapse;">
      <thead><tr>
        <th style="padding:6px 8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">Event type</th>
        <th style="padding:6px 8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">Count</th>
      </tr></thead>
      <tbody>' . $typeRows . '</tbody>
    </table>
    <h3 style="font-size:14px;margin:16px 0 8px;">Latest events</h3>
    <table style="width:100%;border-collapse:collapse;">
      <thead><tr>
        <th style="padding:6px 8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">Type</th>
        <th style="padding:6px 8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">Details</th>
        <th style="padding:6px 8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">IP</th>
        <th style="padding:6px 8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">When</th>
      </tr></thead>
      <tbody>' . $detailRows . '</tbody>
    </table>
    <p style="font-size:12px;color:#94a3b8;margin-top:16px;">Sustained spikes in rate-limit hits, CSRF rejections or callback failures usually indicate probing or abuse — investigate if this is not a one-off.</p>
  </div>
</div>';

$sent = sendEmail($to, 'Club Admin', '🛡 Security digest — Apex Sports Club', $body);
if ($sent) {
    echo count($rows) . " security event(s) in the last 24 hours. Digest emailed to {$to}.\n";
} else {
    fwrite(STDERR, "Security digest email FAILED to send to {$to} (check BREVO_API_KEY / CLUB_EMAIL_FROM).\n");
    exit(1);
}

$conn->close();
