<?php
/**
 * Fine Escalation Cron
 * Finds overdue fines (past due_date) still in 'pending' and escalates them.
 * Each escalation: +10% amount, status → 'escalated', email sent.
 * Run daily: php cron/cron_fine_escalation.php
 * Or HTTP: GET /cron/cron_fine_escalation.php?secret=YOUR_CRON_SECRET
 */

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/../config/api_config.php';
    $secret = defined('CRON_SECRET') ? (string)CRON_SECRET : '';
    if ($secret === '' || ($_GET['secret'] ?? '') !== $secret) {
        http_response_code(403); exit('Forbidden');
    }
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/send_email.php';

$ESCALATION_PCT    = 10;   // % increase per escalation
$MAX_ESCALATIONS   = 3;    // stop escalating after this many
$logDir   = __DIR__ . '/logs';
$logFile  = $logDir . '/fine_escalation_' . date('Y-m-d') . '.log';

if (!is_dir($logDir)) mkdir($logDir, 0775, true);

function esc_log(string $msg): void {
    global $logFile;
    $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    if (php_sapi_name() === 'cli') echo $line;
}

esc_log('=== Fine Escalation Cron Started ===');

// Check table exists
$chk = $conn->query("SHOW TABLES LIKE 'member_fines'");
if (!$chk || $chk->num_rows === 0) {
    esc_log('ERROR: member_fines table not found. Run scripts/migrate_features.php first.');
    exit;
}

// Fetch overdue fines
$sql = "
    SELECT mf.id, mf.member_id, mf.reason, mf.amount, mf.due_date, mf.status, mf.escalation_count,
           m.first_name, m.last_name, m.email
    FROM member_fines mf
    JOIN members m ON m.member_id = mf.member_id
    WHERE mf.status IN ('pending', 'escalated')
      AND mf.due_date IS NOT NULL
      AND mf.due_date < CURDATE()
      AND mf.escalation_count < ?
";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $MAX_ESCALATIONS);
$stmt->execute();
$fines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$escalated = 0;

// Batch update: increment all eligible fines in one query
$batchUpdate = $conn->prepare("
    UPDATE member_fines
    SET status = 'escalated',
        amount = ROUND(amount * (1 + ? / 100), 2),
        escalation_count = escalation_count + 1,
        escalated_at = NOW()
    WHERE status IN ('pending', 'escalated')
      AND due_date IS NOT NULL
      AND due_date < CURDATE()
      AND escalation_count < ?
");
$batchPct = $ESCALATION_PCT;
$batchUpdate->bind_param('ii', $batchPct, $MAX_ESCALATIONS);
$batchUpdate->execute();
$escalated = $batchUpdate->affected_rows;
$batchUpdate->close();

// Fetch updated fines for email notifications
$fetchUpdated = $conn->prepare("
    SELECT mf.id, mf.member_id, mf.reason, mf.amount, mf.due_date, mf.status, mf.escalation_count,
           m.first_name, m.last_name, m.email
    FROM member_fines mf
    JOIN members m ON m.member_id = mf.member_id
    WHERE mf.status = 'escalated'
      AND mf.escalated_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
      AND mf.escalation_count <= ?
");
$fetchUpdated->bind_param('i', $MAX_ESCALATIONS);
$fetchUpdated->execute();
$updatedFines = $fetchUpdated->get_result()->fetch_all(MYSQLI_ASSOC);
$fetchUpdated->close();

foreach ($updatedFines as $fine) {
    esc_log("⬆️  Fine #{$fine['id']} for {$fine['first_name']} {$fine['last_name']} escalated to KES {$fine['amount']} (escalation #{$fine['escalation_count']})");

    $subject = "⚠️ Outstanding Fine Escalation — Apex Sports Club";
    $body    = "
<p>Dear <strong>{$fine['first_name']}</strong>,</p>
<p>This is a notice that your outstanding fine has been <strong>escalated</strong> due to non-payment.</p>
<table style='border-collapse:collapse;width:100%;max-width:400px;'>
    <tr><td style='padding:.5rem;border:1px solid #e2e8f0;'><strong>Reason</strong></td><td style='padding:.5rem;border:1px solid #e2e8f0;'>" . htmlspecialchars($fine['reason']) . "</td></tr>
    <tr><td style='padding:.5rem;border:1px solid #e2e8f0;'><strong>Original Due</strong></td><td style='padding:.5rem;border:1px solid #e2e8f0;'>" . date('d M Y', strtotime($fine['due_date'])) . "</td></tr>
    <tr><td style='padding:.5rem;border:1px solid #e2e8f0;'><strong>Updated Amount</strong></td><td style='padding:.5rem;border:1px solid #e2e8f0;color:#ef4444;font-weight:700;'>KES " . number_format($fine['amount'], 2) . "</td></tr>
    <tr><td style='padding:.5rem;border:1px solid #e2e8f0;'><strong>Escalation</strong></td><td style='padding:.5rem;border:1px solid #e2e8f0;'>#{$fine['escalation_count']} of " . $MAX_ESCALATIONS . "</td></tr>
</table>
<p style='margin-top:1rem;'>Please settle this fine immediately to avoid further escalation or membership suspension.</p>
<p>Contact the club admin if you believe this is an error.</p>
<p>— Apex Sports Club Administration</p>
";

    if (function_exists('send_club_email')) {
        $sent = send_club_email($fine['email'], $fine['first_name'] . ' ' . $fine['last_name'], $subject, $body);
        esc_log($sent ? "  ✅ Email sent to {$fine['email']}" : "  ❌ Email failed for {$fine['email']}");
    }
}

// Also flag maxed-out fines as final notice
$maxSql = "SELECT mf.id, m.first_name, m.last_name, m.email, mf.amount, mf.reason
    FROM member_fines mf JOIN members m ON m.member_id=mf.member_id
    WHERE mf.escalation_count >= ? AND mf.status='escalated' AND mf.due_date < CURDATE()";
$stmt2 = $conn->prepare($maxSql);
$stmt2->bind_param('i', $MAX_ESCALATIONS);
$stmt2->execute();
$maxed = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

foreach ($maxed as $f) {
    esc_log("🔴 Fine #{$f['id']} for {$f['first_name']} {$f['last_name']} has reached max escalations. Admin action required.");
}

$conn->close();
esc_log("=== Done. {$escalated} fines escalated. " . count($maxed) . " at max escalation. ===");

if (!$isCli) {
    header('Content-Type: text/plain');
    echo "Done. {$escalated} fines escalated.";
}


