<?php
// Safaricom sends payment confirmation here
require_once 'config/db_connect.php';
require_once 'includes/send_email.php';

$data = json_decode(file_get_contents('php://input'), true);

// Log for debugging
file_put_contents('mpesa_log.txt',
    date('Y-m-d H:i:s') . " — " . json_encode($data) . "\n",
    FILE_APPEND
);

$resultCode = $data['Body']['stkCallback']['ResultCode'] ?? -1;

if ($resultCode == 0) {
    $items     = $data['Body']['stkCallback']['CallbackMetadata']['Item'];
    $amount    = $items[0]['Value'] ?? 0;
    $mpesaCode = $items[1]['Value'] ?? '';
    $phone     = $items[4]['Value'] ?? '';

    // Find member by phone
    $stmt = $conn->prepare("SELECT member_id, first_name, email FROM members WHERE phone_number LIKE ?");
    $phoneSearch = '%' . substr($phone, -9) . '%';
    $stmt->bind_param("s", $phoneSearch);
    $stmt->execute();
    $stmt->bind_result($member_id, $member_fname, $member_email);
    $stmt->fetch();
    $stmt->close();

    if ($member_id) {
        // Save payment
        $method = 'M-Pesa';
        $desc   = 'M-Pesa payment — ' . $mpesaCode;
        $stmt2  = $conn->prepare("INSERT INTO payments (member_id, amount, payment_method, description) VALUES (?,?,?,?)");
        $stmt2->bind_param("idss", $member_id, $amount, $method, $desc);
        $stmt2->execute();
        $stmt2->close();

        // Send receipt email
        sendEmail(
            $member_email,
            $member_fname,
            '🧾 M-Pesa Payment Receipt — Apex Sports Club',
            emailPaymentReceipt($member_fname, $amount, 'M-Pesa', $desc)
        );
    }
}

$conn->close();
http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
?>
