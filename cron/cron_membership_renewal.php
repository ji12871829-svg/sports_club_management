<?php
/**
 * Automated Membership Renewal Processor
 *
 * Supports both Paystack (silent card charge) and M-Pesa (STK Push to phone).
 * Payment method is determined per-member from their profile.
 *
 * Fixes applied:
 *  Fix 1 — Respects each member's personal renewal_days_before preference
 *  Fix 2 — Checks renewal_reminder_sent flag; resets it after success
 *  Fix 3 — Applies a 3-day grace period on failed charges
 *  Fix 4 — Defers membership activation to callback (webhook/mpesa_callback)
 *  Fix 5 — Rate-limits HTTP-triggered cron calls (max once per hour)
 *
 * Schedule: 0 2 * * *  (daily at 2 AM)
 * Manual:   php cron/cron_membership_renewal.php
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../includes/paystack.php';
require_once __DIR__ . '/../includes/mpesa.php';
require_once __DIR__ . '/../includes/mpesa_helpers.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/renewal_helpers.php';

// -----------------------------------------------------------------------
// Access control
// -----------------------------------------------------------------------
if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTP_X_CRON_SECRET'])) {
    http_response_code(403);
    die('Access denied.');
}

if (!empty($_SERVER['HTTP_X_CRON_SECRET'])) {
    $cronSecret = getenv('CRON_SECRET');
    if (!$cronSecret || $_SERVER['HTTP_X_CRON_SECRET'] !== $cronSecret) {
        http_response_code(403);
        die('Invalid cron secret.');
    }

    // Fix 5: Rate limiting — HTTP trigger allowed at most once per hour
    define('CRON_MIN_INTERVAL', 3600);
    define('CRON_LOCK_FILE', __DIR__ . '/logs/.cron_last_run');

    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    if (file_exists(CRON_LOCK_FILE)) {
        $lastRun = (int) file_get_contents(CRON_LOCK_FILE);
        $elapsed = time() - $lastRun;
        if ($elapsed < CRON_MIN_INTERVAL) {
            http_response_code(429);
            die('Too many requests. Last run was ' . $elapsed . 's ago. Min interval: ' . CRON_MIN_INTERVAL . 's.');
        }
    }
    file_put_contents(CRON_LOCK_FILE, time());
}

// -----------------------------------------------------------------------
// Logging
// -----------------------------------------------------------------------
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/renewal_' . date('Y-m-d') . '.log';

function log_renewal(string $message): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// -----------------------------------------------------------------------
// Main process
// -----------------------------------------------------------------------
log_renewal('=== Membership Renewal Process Started ===');

// Single-instance guard: MySQL GET_LOCK ensures a CLI run cannot overlap a
// scheduled run (or another CLI run), which could otherwise double-charge
// members. Timeout 0 = fail immediately if another instance holds the lock.
$lockOk = $conn->query("SELECT GET_LOCK('apex_membership_renewal', 0)");
$lockHeld = $lockOk && $lockOk->fetch_row()[0] == 1;
if (!$lockHeld) {
    log_renewal('Another renewal process is already running — exiting.');
    exit(0);
}
register_shutdown_function(function () use ($conn) {
    if ($conn instanceof mysqli) {
        $conn->query("SELECT RELEASE_LOCK('apex_membership_renewal')");
    }
});

try {
    $membershipsForRenewal = get_memberships_for_renewal($conn, 7);
    log_renewal('Found ' . count($membershipsForRenewal) . ' memberships ready for renewal.');

    $paystack_success = 0;
    $mpesa_pushed     = 0;
    $failed           = 0;
    $skipped          = 0;

    foreach ($membershipsForRenewal as $membership) {
        $memberId     = (int)   $membership['member_id'];
        $membershipId = (int)   $membership['membership_id'];
        $planId       = (int)   $membership['plan_id'];
        $amount       = (float) $membership['price'];
        $planName     = $membership['plan_name'];
        $endDate      = $membership['end_date'];
        $memberEmail  = $membership['email'];
        $memberName   = trim($membership['first_name'] . ' ' . $membership['last_name']);
        $effectiveDays = (int) $membership['effective_days_before'];
        $paymentMethod = strtolower(trim($membership['payment_method'] ?? ''));
        $phoneNumber   = $membership['phone_number'] ?? '';

        log_renewal("Processing member {$memberId} ({$memberName}) — Plan: {$planName}, Expires: {$endDate}, Method: {$paymentMethod}");

        // Fix 2: Skip if reminder already sent this cycle
        if (!empty($membership['renewal_reminder_sent'])) {
            log_renewal("  ↷ Reminder already sent for membership #{$membershipId}. Skipping.");
            $skipped++;
            continue;
        }

        // Log the attempt as Pending
        $renewalLog = log_renewal_attempt($conn, $memberId, $membershipId, $planId, 'Pending', $amount);
        if (!$renewalLog['success']) {
            log_renewal("  ✗ Failed to create renewal log for member {$memberId}");
            $failed++;
            continue;
        }
        $renewalLogId = $renewalLog['renewal_log_id'];

        // ----------------------------------------------------------------
        // Route by payment method
        // ----------------------------------------------------------------
        if ($paymentMethod === 'm-pesa' || $paymentMethod === 'mpesa') {
            handle_mpesa_renewal(
                $conn, $memberId, $membershipId, $planId, $planName,
                $amount, $endDate, $memberEmail, $memberName,
                $phoneNumber, $renewalLogId,
                $mpesa_pushed, $failed
            );
        } else {
            handle_paystack_renewal(
                $conn, $memberId, $membershipId, $planId, $planName,
                $amount, $endDate, $memberEmail, $memberName,
                $renewalLogId,
                $paystack_success, $failed
            );
        }
    }

    log_renewal('=== Renewal Process Completed ===');
    log_renewal("Paystack charges authorised (awaiting webhook): {$paystack_success}");
    log_renewal("M-Pesa STK Push sent (awaiting member PIN):     {$mpesa_pushed}");
    log_renewal("Failed (grace period applied):                  {$failed}");
    log_renewal("Skipped (reminder already sent):                {$skipped}");

} catch (Exception $e) {
    log_renewal('ERROR: ' . $e->getMessage());
    log_renewal($e->getTraceAsString());
}

// -----------------------------------------------------------------------
// Paystack renewal handler
// -----------------------------------------------------------------------
function handle_paystack_renewal(
    mysqli $conn,
    int $memberId, int $membershipId, int $planId,
    string $planName, float $amount, string $endDate,
    string $memberEmail, string $memberName,
    int $renewalLogId,
    int &$successCount, int &$failureCount
): void {
    $authorization = get_paystack_authorization($conn, $memberId);

    if (!$authorization) {
        log_renewal("  ⚠ No saved Paystack card for member {$memberId}. Sending reminder.");
        send_renewal_reminder_email($memberEmail, $memberName, $planName, $endDate, 'paystack');
        mark_renewal_reminder_sent($conn, $membershipId);
        update_renewal_log($conn, $renewalLogId, 'Failed', null, 'No saved payment method');
        $failureCount++;
        return;
    }

    $authCode = $authorization['authorization_code'];
    log_renewal("  [Paystack] Using authorization: {$authCode}");

    $chargeResult = paystackChargeAuthorization(
        $authCode,
        $memberEmail,
        (int) ($amount * 100),
        [
            'member_id'      => $memberId,
            'membership_id'  => $membershipId,
            'plan_id'        => $planId,
            'renewal_log_id' => $renewalLogId,
            'description'    => "Automatic renewal: {$planName}",
            'source'         => 'auto_renewal',
        ]
    );

    if (!empty($chargeResult['status']) && $chargeResult['status'] === true) {
        $reference = $chargeResult['data']['reference'] ?? null;
        log_renewal("  ✓ Paystack charge authorised. Reference: {$reference}");
        // Fix 4: Activation deferred to callbacks/paystack_callback.php on charge.success webhook
        update_renewal_log($conn, $renewalLogId, 'Pending-Webhook', $reference);
        // Fix 2: Reset flag for next cycle
        reset_renewal_reminder_sent($conn, $membershipId);
        send_renewal_processing_email($memberEmail, $memberName, $planName, $amount, $reference, 'paystack');
        $successCount++;
    } else {
        $errorMsg = $chargeResult['message'] ?? 'Unknown error';
        log_renewal("  ✗ Paystack charge failed: {$errorMsg}");
        update_renewal_log($conn, $renewalLogId, 'Failed', null, $errorMsg);
        // Fix 3: Grace period
        apply_grace_period($conn, $membershipId);
        mark_renewal_reminder_sent($conn, $membershipId);
        send_renewal_failure_email($memberEmail, $memberName, $planName, $errorMsg);
        $failureCount++;
    }
}

// -----------------------------------------------------------------------
// M-Pesa renewal handler
// -----------------------------------------------------------------------
function handle_mpesa_renewal(
    mysqli $conn,
    int $memberId, int $membershipId, int $planId,
    string $planName, float $amount, string $endDate,
    string $memberEmail, string $memberName,
    string $phoneNumber, int $renewalLogId,
    int &$mpesaPushed, int &$failureCount
): void {
    if (empty($phoneNumber)) {
        log_renewal("  ⚠ No phone number for member {$memberId}. Sending reminder.");
        send_renewal_reminder_email($memberEmail, $memberName, $planName, $endDate, 'mpesa');
        mark_renewal_reminder_sent($conn, $membershipId);
        update_renewal_log($conn, $renewalLogId, 'Failed', null, 'No phone number on file');
        $failureCount++;
        return;
    }

    $formattedPhone = formatMpesaPhone($phoneNumber);
    $description    = "Membership renewal: {$planName}";

    log_renewal("  [M-Pesa] Sending STK Push to {$formattedPhone}");

    $stkResult = mpesaSTKPush($formattedPhone, (int) $amount, $description);

    if (!empty($stkResult['ResponseCode']) && $stkResult['ResponseCode'] === '0') {
        $checkoutId = $stkResult['CheckoutRequestID'] ?? '';
        log_renewal("  ✓ STK Push sent. CheckoutRequestID: {$checkoutId}");

        // Store pending record so callbacks/mpesa_callback.php can match it
        mpesa_create_pending(
            $conn,
            $checkoutId,
            $amount,
            $description,
            'auto_renewal',
            $memberId,
            null
        );

        // Store renewal_log_id and membership_id in mpesa_pending metadata
        // by saving them in the description field as JSON for callback lookup
        store_mpesa_renewal_meta($conn, $checkoutId, $renewalLogId, $membershipId, $planId);

        // Fix 2: Reset flag — callback will confirm actual payment
        reset_renewal_reminder_sent($conn, $membershipId);

        update_renewal_log($conn, $renewalLogId, 'Pending-STK', $checkoutId);
        send_mpesa_stk_push_email($memberEmail, $memberName, $planName, $amount, $formattedPhone);
        $mpesaPushed++;
    } else {
        $errorMsg = $stkResult['errorMessage'] ?? ($stkResult['ResponseDescription'] ?? 'STK Push failed');
        log_renewal("  ✗ M-Pesa STK Push failed: {$errorMsg}");
        update_renewal_log($conn, $renewalLogId, 'Failed', null, $errorMsg);
        // Fix 3: Grace period
        apply_grace_period($conn, $membershipId);
        mark_renewal_reminder_sent($conn, $membershipId);
        send_renewal_failure_email($memberEmail, $memberName, $planName, $errorMsg);
        $failureCount++;
    }
}

// -----------------------------------------------------------------------
// Store M-Pesa renewal metadata for callback lookup
// -----------------------------------------------------------------------
function store_mpesa_renewal_meta(
    mysqli $conn,
    string $checkoutId,
    int $renewalLogId,
    int $membershipId,
    int $planId
): void {
    if (!db_table_exists($conn, 'mpesa_pending')) return;

    $meta = json_encode([
        'renewal_log_id' => $renewalLogId,
        'membership_id'  => $membershipId,
        'plan_id'        => $planId,
    ]);

    $stmt = $conn->prepare("
        UPDATE mpesa_pending
        SET description = CONCAT(description, ' | meta:', ?)
        WHERE checkout_request_id = ?
    ");
    if ($stmt) {
        $stmt->bind_param('ss', $meta, $checkoutId);
        $stmt->execute();
        $stmt->close();
    }
}

// -----------------------------------------------------------------------
// Shared grace period helper
// -----------------------------------------------------------------------
function apply_grace_period(mysqli $conn, int $membershipId, int $days = 3): void {
    if (set_membership_grace_period($conn, $membershipId, $days)) {
        log_renewal("  ⏱ Grace period of {$days} days applied to membership #{$membershipId}.");
    } else {
        log_renewal("  ⚠ Could not apply grace period to membership #{$membershipId}.");
    }
}

// -----------------------------------------------------------------------
// Email helpers
// -----------------------------------------------------------------------
function send_renewal_reminder_email(
    string $email, string $name, string $planName,
    string $expiryDate, string $method = 'paystack'
): void {
    $subject = '⏰ Your Membership is Expiring Soon';
    $isMpesa = $method === 'mpesa';
    $instruction = $isMpesa
        ? 'Please ensure your M-Pesa number is up to date so we can send you a payment prompt.'
        : 'Please update your payment method to enable automatic renewal.';
    $btnUrl  = getenv('APP_URL') . '/public/payments.php';
    $btnText = $isMpesa ? 'Update Phone Number' : 'Update Payment Method';

    $body = "
        <p>Hi {$name},</p>
        <p>Your <strong>{$planName}</strong> membership expires on <strong>{$expiryDate}</strong>.</p>
        <p>{$instruction}</p>
        <p><a href='{$btnUrl}'
              style='display:inline-block;padding:10px 20px;background:#007bff;color:#fff;text-decoration:none;border-radius:5px;'>
           {$btnText}
        </a></p>
        <p>Best regards,<br>Apex Sports Club</p>
    ";
    sendEmail($email, $name, $subject, $body);
}

function send_mpesa_stk_push_email(
    string $email, string $name, string $planName,
    float $amount, string $phone
): void {
    $subject = '📱 M-Pesa Payment Request Sent — Membership Renewal';
    $body = "
        <p>Hi {$name},</p>
        <p>We have sent an M-Pesa payment request to <strong>{$phone}</strong> for your <strong>{$planName}</strong> membership renewal.</p>
        <ul>
            <li>Amount: <strong>KES " . number_format($amount, 2) . "</strong></li>
            <li>Reference: ApexClub</li>
        </ul>
        <p>Please check your phone and <strong>enter your M-Pesa PIN</strong> to complete the payment and renew your membership.</p>
        <p>If you did not receive the prompt, you can renew manually:</p>
        <p><a href='" . getenv('APP_URL') . "/public/payments.php'
              style='display:inline-block;padding:10px 20px;background:#28a745;color:#fff;text-decoration:none;border-radius:5px;'>
           Renew Manually
        </a></p>
        <p>Best regards,<br>Apex Sports Club</p>
    ";
    sendEmail($email, $name, $subject, $body);
}

function send_renewal_processing_email(
    string $email, string $name, string $planName,
    float $amount, ?string $reference, string $method = 'paystack'
): void {
    $subject = '⏳ Your Membership Renewal is Being Processed';
    $body = "
        <p>Hi {$name},</p>
        <p>We have charged your saved card for your <strong>{$planName}</strong> membership renewal.</p>
        <ul>
            <li>Amount: KES " . number_format($amount, 2) . "</li>
            <li>Reference: " . htmlspecialchars($reference ?? 'N/A') . "</li>
        </ul>
        <p>Your membership will be activated within a few minutes once payment is confirmed.</p>
        <p><a href='" . getenv('APP_URL') . "/public/dashboard.php'
              style='display:inline-block;padding:10px 20px;background:#28a745;color:#fff;text-decoration:none;border-radius:5px;'>
           View Dashboard
        </a></p>
        <p>Best regards,<br>Apex Sports Club</p>
    ";
    sendEmail($email, $name, $subject, $body);
}

function send_renewal_failure_email(
    string $email, string $name, string $planName,
    string $errorMsg, int $graceDays = 3
): void {
    $subject = '⚠ Membership Renewal Failed — ' . $graceDays . '-Day Grace Period Applied';
    $body = "
        <p>Hi {$name},</p>
        <p>We were unable to renew your <strong>{$planName}</strong> membership.</p>
        <p><strong>Reason:</strong> " . htmlspecialchars($errorMsg) . "</p>
        <p>A <strong>{$graceDays}-day grace period</strong> has been applied so you retain access while you resolve this.</p>
        <p><a href='" . getenv('APP_URL') . "/public/payments.php'
              style='display:inline-block;padding:10px 20px;background:#dc3545;color:#fff;text-decoration:none;border-radius:5px;'>
           Renew Now
        </a></p>
        <p>Best regards,<br>Apex Sports Club</p>
    ";
    sendEmail($email, $name, $subject, $body);
}

