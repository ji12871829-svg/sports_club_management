<?php
/**
 * cron_payment_health.php
 * Daily payment-configuration health check. Catches the most common
 * payment-breaking misconfigurations before users hit them:
 *
 *   - MPESA_CALLBACK_URL must be https:// and publicly reachable
 *     (Safaricom rejects http:// with 400.002.02 "Invalid CallBackURL")
 *   - MPESA_CALLBACK_URL must not be a placeholder domain
 *   - PAYSTACK_CALLBACK_URL must not be a placeholder domain
 *   - M-Pesa / Paystack keys must be configured
 *
 * On failure it emails the report via includes/send_email.php. Alerts are
 * throttled to one email per 24h per problem so a persistent misconfig
 * does not spam the inbox.
 *
 * DISABLED unless .env sets:  ASC_PAYMENT_ALERT_EMAIL_TO=you@example.com
 *
 * Schedule (Windows Task Scheduler or cron), e.g. daily at 7 AM:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Apex Sports Club\cron_payment_health.php
 *
 * Run manually:  php cron_payment_health.php
 */

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/api_config.php';
require_once __DIR__ . '/includes/feature_helpers.php'; // db_table_exists()
require_once __DIR__ . '/includes/send_email.php';
require_once __DIR__ . '/includes/mpesa.php'; // mpesa_callback_url_error()

$to = getenv('ASC_PAYMENT_ALERT_EMAIL_TO');
if ($to === false || trim($to) === '') {
    fwrite(STDERR, "ASC_PAYMENT_ALERT_EMAIL_TO not set in .env — payment health alerts disabled. Nothing to do.\n");
    exit(0);
}

// ── Collect problems ─────────────────────────────────────────────────
$problems = [];

if (defined('MPESA_CALLBACK_URL')) {
    $cbError = mpesa_callback_url_error(MPESA_CALLBACK_URL);
    if ($cbError !== null) {
        $problems[] = 'M-Pesa: ' . $cbError;
    }
} else {
    $problems[] = 'M-Pesa: MPESA_CALLBACK_URL constant is not defined';
}

if (defined('PAYSTACK_CALLBACK_URL')) {
    $paystackCb = trim(PAYSTACK_CALLBACK_URL);
    if ($paystackCb === '') {
        $problems[] = 'Paystack: PAYSTACK_CALLBACK_URL is empty';
    } elseif (preg_match('/your-ngrok-domain|example\.com|placeholder/i', $paystackCb)) {
        $problems[] = 'Paystack: PAYSTACK_CALLBACK_URL is still a placeholder domain';
    }
} else {
    $problems[] = 'Paystack: PAYSTACK_CALLBACK_URL constant is not defined';
}

if (!defined('MPESA_CONSUMER_KEY') || trim(MPESA_CONSUMER_KEY) === '' || MPESA_CONSUMER_KEY === 'test') {
    $problems[] = 'M-Pesa: MPESA_CONSUMER_KEY is not configured';
}
if (!defined('PAYSTACK_SECRET_KEY') || trim(PAYSTACK_SECRET_KEY) === '' || PAYSTACK_SECRET_KEY === 'sk_test_local') {
    $problems[] = 'Paystack: PAYSTACK_SECRET_KEY is not configured';
}

// ── No problems: nothing to do ──────────────────────────────────────
if (empty($problems)) {
    echo "Payment config healthy.\n";
    $conn->close();
    exit(0);
}

// ── Throttle: one email per alert type per 24h ──────────────────────
// Uses the DB-backed security_alert_log table (created by migration 058)
// when it exists; otherwise falls back to sending (cron runs are
// typically daily anyway).
if (db_table_exists($conn, 'security_alert_log')) {
    $stmt = $conn->prepare(
        "SELECT id FROM security_alert_log
         WHERE alert_type = 'payment_health'
           AND sent_at > NOW() - INTERVAL 24 HOUR LIMIT 1"
    );
    if ($stmt) {
        $stmt->execute();
        // NOTE: mysqli_stmt::fetch() returns null (not false) when no rows
        // remain, so test for an explicit boolean true to detect a hit.
        $alreadySent = $stmt->fetch() === true;
        $stmt->close();

        if ($alreadySent) {
            echo "Payment health alert already sent in the last 24h — skipping.\n";
            $conn->close();
            exit(0);
        }

        // Record this alert so we don't re-email within 24h
        $ins = $conn->prepare(
            "INSERT INTO security_alert_log (alert_type) VALUES ('payment_health')"
        );
        if ($ins) {
            $ins->execute();
            $ins->close();
        }
    }
}

// ── Compose + send the report ───────────────────────────────────────
$lineItems = '';
foreach ($problems as $p) {
    $lineItems .= '<li style="margin-bottom:6px;">' . htmlspecialchars($p) . '</li>';
}

$subject = '⚠️ Payment Configuration Issue — Apex Sports Club';
$html = "<div style=\"font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:24px;border:1px solid #e2e8f0;border-radius:12px;\">
    <h2 style=\"color:#dc2626;margin-top:0;\">Payment Configuration Needs Attention</h2>
    <p>The daily payment-health check found " . count($problems) . " problem(s). Payments may fail for members until these are fixed:</p>
    <ul style=\"padding-left:20px;\">{$lineItems}</ul>
    <p><strong>Most common fix:</strong> point <code>MPESA_CALLBACK_URL</code> in <code>.env</code> at an <code>https://</code> tunnel URL (e.g. ngrok) that Safaricom can reach — <code>http://</code> and <code>localhost</code> are rejected. Then verify with <code>public/health.php</code> (payment_config check).</p>
    <p style=\"color:#64748b;font-size:12px;\">Sent by cron_payment_health.php · Apex Sports Club</p>
</div>";

$sent = sendEmail($to, 'Club Admin', $subject, $html);
echo $sent ? "Payment health alert sent to {$to}.\n" : "Failed to send payment health alert email.\n";

$conn->close();
