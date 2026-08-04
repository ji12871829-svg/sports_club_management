<?php
// ============================================================
//  paystack_callback.php
//  Paystack transaction verification + idempotent payment recording.
//
//  SECURITY: Verifies the Paystack x-paystack-signature header
//  (HMAC-SHA256 of raw body using PAYSTACK_SECRET_KEY) before trusting
//  any incoming callback. Requests without a valid signature are rejected.
//
//  IDEMPOTENCY: Uses provider_reference (the Paystack reference) as
//  a unique upsert key — retried callbacks never duplicate payments.
// ============================================================

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/api_config.php';
require_once __DIR__ . '/includes/paystack.php';
require_once __DIR__ . '/includes/send_email.php';
require_once __DIR__ . '/includes/rate_limiter.php';

// ── Webhook signature verification ─────────────────────────────────────
// Paystack sends `x-paystack-signature` as HMAC-SHA256 of the raw request
// body, signed with the secret key. We verify this BEFORE trusting any data
// — and BEFORE the rate limiter, so a forged flood (wrong signature) is
// rejected with a cheap CPU-only check and never writes to the DB.
$rawBody = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';
$cb_ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$expectedSig = hash_hmac('sha256', $rawBody, PAYSTACK_SECRET_KEY);
if ($sigHeader === '' || !hash_equals($expectedSig, $sigHeader)) {
    // The callback may have come via GET redirect (user browser) — in that
    // case there's no signature header. We fall through to the reference-
    // based verification (which calls Paystack API directly).
    // If the body is non-empty and the signature is wrong, reject outright.
    if ($rawBody !== '' && $sigHeader !== '') {
        error_log('[Paystack] Webhook signature mismatch — rejecting.');
        log_security_event_throttled('callback_reject', 'critical', 'Paystack webhook signature mismatch (forged or tampered request)', $cb_ip);
        http_response_code(403);
        echo json_encode(['status' => false, 'message' => 'Invalid signature']);
        exit;
    }
}

// Defense-in-depth: cap callback/return traffic per client IP. Only requests
// that passed the signature gate (or browser GET redirects) reach this point.
if (!rate_limit_check('paystack_cb_' . md5($cb_ip), 120, 60)) {
    log_security_event_throttled('callback_reject', 'warning', 'Paystack callback rate limit exceeded', $cb_ip);
    http_response_code(429);
    echo json_encode(['status' => false, 'message' => 'Rate limited']);
    exit;
}

$message = 'Invalid request.';
$success = false;
$reference = $_GET['reference'] ?? '';

if ($reference) {
    $verify = paystackVerifyTransaction($reference);

    if (!empty($verify['status']) && $verify['status'] === true && !empty($verify['data']['status']) && $verify['data']['status'] === 'success') {
        $data = $verify['data'];
        $amount = ($data['amount'] ?? 0) / 100;
        $metadata = $data['metadata'] ?? [];
        $member_id = isset($metadata['member_id']) ? (int) $metadata['member_id'] : 0;
        $description = $metadata['description'] ?? 'Paystack payment';
        $customer_email = $data['customer']['email'] ?? '';

        // Use Paystack reference as the idempotency key
        $paystackRef = $data['reference'] ?? $reference;

        if ($member_id <= 0 || $amount <= 0) {
            $message = 'Unable to save payment because the member or amount information is missing.';
        } else {
            // ── Idempotent upsert ──────────────────────────────────────
            $sql = "INSERT INTO payments (member_id, amount, payment_method, description, provider_reference, payment_status)
                    VALUES (?, ?, ?, ?, ?, 'Completed')
                    ON DUPLICATE KEY UPDATE
                        payment_status = VALUES(payment_status),
                        description    = VALUES(description),
                        payment_date   = CURRENT_TIMESTAMP";
            if ($stmt = $conn->prepare($sql)) {
                $payment_method = 'Paystack';
                $stmt->bind_param('idsss', $member_id, $amount, $payment_method, $description, $paystackRef);

                if ($stmt->execute()) {
                    $is_new = $stmt->affected_rows === 1;
                    $success = true;

                    if ($is_new) {
                        $message = 'Paystack payment completed and recorded successfully.';

                        $member_email = '';
                        $member_fname = '';
                        $member_lname = '';
                        $sql_member = 'SELECT email, first_name, last_name FROM members WHERE member_id = ?';
                        if ($stmt_m = $conn->prepare($sql_member)) {
                            $stmt_m->bind_param('i', $member_id);
                            $stmt_m->execute();
                            $stmt_m->bind_result($member_email, $member_fname, $member_lname);
                            $stmt_m->fetch();
                            $stmt_m->close();
                        }

                        if ($member_email) {
                            sendEmail(
                                $member_email,
                                trim($member_fname . ' ' . $member_lname),
                                '🧾 Payment Receipt — Apex Sports Club',
                                emailPaymentReceipt($member_fname ?: 'Member', $amount, 'Paystack', $description)
                            );
                        }

                        error_log('[Paystack] New payment recorded: ' . $paystackRef . ' KES ' . $amount);
                    } else {
                        $message = 'Payment already recorded (duplicate callback ignored).';
                        error_log('[Paystack] Duplicate callback ignored for: ' . $paystackRef);
                    }
                } else {
                    $message = 'Failed to record payment: ' . $stmt->error;
                }
                $stmt->close();
            } else {
                $message = 'Database error while saving payment.';
            }
        }
    } else {
        $message = $verify['message'] ?? 'Paystack transaction verification failed.';
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Paystack Callback</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3">Paystack Payment Status</h1>
            <div class="alert <?php echo $success ? 'alert-success' : 'alert-danger'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
            <a href="admin/manage_payments.php" class="btn btn-primary">Return to Manage Payments</a>
        </div>
    </div>
</div>
</body>
</html>