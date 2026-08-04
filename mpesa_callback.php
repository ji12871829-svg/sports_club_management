<?php
// Safaricom sends payment confirmation here
// IDEMPOTENT: uses provider_reference as upsert key — retried callbacks don't duplicate.

require_once 'config/db_connect.php';
require_once 'includes/send_email.php';

$data = json_decode(file_get_contents('php://input'), true);

// ── Log only masked data (no full JSON, no PII dump) ───────────────────
$resultCode = $data['Body']['stkCallback']['ResultCode'] ?? -1;
$checkoutId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? 'N/A';
error_log('[M-Pesa Callback] ResultCode=' . $resultCode . ' CheckoutRequestID=' . $checkoutId);

if ($resultCode == 0) {
    $items     = $data['Body']['stkCallback']['CallbackMetadata']['Item'];
    $amount    = (float)($items[0]['Value'] ?? 0);
    $mpesaCode = $items[1]['Value'] ?? '';           // MpesaReceiptNumber — unique transaction ref
    $phone     = $items[4]['Value'] ?? '';
    $transDate = $items[3]['Value'] ?? '';            // TransactionDate (YYYYMMDDHHmmss)

    // Must have a receipt number for idempotency
    if ($mpesaCode === '') {
        error_log('[M-Pesa Callback] Missing MpesaReceiptNumber — cannot process.');
        http_response_code(200);
        echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted (missing receipt)']);
        exit;
    }

    // Find member by phone
    $stmt = $conn->prepare("SELECT member_id, first_name, email FROM members WHERE phone_number LIKE ?");
    $phoneSearch = '%' . substr($phone, -9) . '%';
    $stmt->bind_param("s", $phoneSearch);
    $stmt->execute();
    $stmt->bind_result($member_id, $member_fname, $member_email);
    $stmt->fetch();
    $stmt->close();

    if ($member_id) {
        // ── Idempotent upsert using provider_reference ─────────────────
        $method = 'M-Pesa';
        $desc   = 'M-Pesa payment — ' . $mpesaCode;

        $stmt2 = $conn->prepare(
            "INSERT INTO payments (member_id, amount, payment_method, description, provider_reference, payment_status)
             VALUES (?, ?, ?, ?, ?, 'Completed')
             ON DUPLICATE KEY UPDATE
                 payment_status = VALUES(payment_status),
                 description    = VALUES(description),
                 payment_date   = CURRENT_TIMESTAMP"
        );
        $stmt2->bind_param("idsss", $member_id, $amount, $method, $desc, $mpesaCode);
        $stmt2->execute();

        // Check if this was an insert (new) or update (duplicate already existed)
        $is_new = $stmt2->affected_rows === 1;
        $stmt2->close();

        if ($is_new) {
            // Only send receipt email for new payments
            sendEmail(
                $member_email,
                $member_fname,
                '🧾 M-Pesa Payment Receipt — Apex Sports Club',
                emailPaymentReceipt($member_fname, $amount, 'M-Pesa', $desc)
            );
            error_log('[M-Pesa Callback] Recorded new payment: ' . $mpesaCode . ' KES ' . $amount);
        } else {
            error_log('[M-Pesa Callback] Duplicate callback ignored for: ' . $mpesaCode);
        }
    } else {
        error_log('[M-Pesa Callback] No member found for phone ending in ' . substr($phone, -4));
    }
}

$conn->close();
http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
?>
