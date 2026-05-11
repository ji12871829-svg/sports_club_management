<?php
require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/config/api_config.php';
require_once __DIR__ . '/includes/paystack.php';
require_once __DIR__ . '/includes/send_email.php';

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

        if ($member_id <= 0 || $amount <= 0) {
            $message = 'Unable to save payment because the member or amount information is missing.';
        } else {
            $sql = "INSERT INTO payments (member_id, amount, payment_method, description) VALUES (?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $payment_method = 'Paystack';
                $stmt->bind_param('idss', $member_id, $amount, $payment_method, $description);

                if ($stmt->execute()) {
                    $success = true;
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
                            '🧾 Payment Receipt — Sports Club',
                            emailPaymentReceipt($member_fname ?: 'Member', $amount, 'Paystack', $description)
                        );
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
?>
<!doctype html>
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
