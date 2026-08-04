<?php
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../includes/paystack.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/ticket_helpers.php';
require_once __DIR__ . '/../includes/promo_codes.php';

$message = 'Invalid request.';
$success = false;
$return_url = 'admin/manage_payments.php';
$reference = $_GET['reference'] ?? '';

if ($reference) {
    $verify = paystackVerifyTransaction($reference);

    if (!empty($verify['status']) && $verify['status'] === true && !empty($verify['data']['status']) && $verify['data']['status'] === 'success') {
        $data = $verify['data'];
        $amount = ($data['amount'] ?? 0) / 100;
        $metadata = $data['metadata'] ?? [];
        $member_id = isset($metadata['member_id']) ? (int) $metadata['member_id'] : 0;
        $description = $metadata['description'] ?? 'Paystack payment';
        $source = $metadata['source'] ?? 'admin';
        $plan_id = isset($metadata['plan_id']) ? (int) $metadata['plan_id'] : 0;
        $ticket_order_id = isset($metadata['ticket_order_id']) ? (int) $metadata['ticket_order_id'] : 0;
        $customer_email = $data['customer']['email'] ?? '';
        $is_ticket_purchase = in_array($source, ['ticket_purchase', 'fan_ticket_purchase'], true) && $ticket_order_id > 0;
        $is_fan_ticket_purchase = $source === 'fan_ticket_purchase' && $ticket_order_id > 0;
        $is_auto_renewal = $source === 'auto_renewal'; // Fix 4
        if ($is_ticket_purchase) {
            $return_url = $is_fan_ticket_purchase ? 'public/fan_tickets.php?paid=1' : 'public/my_tickets.php?paid=1';
        } else {
            $return_url = $source === 'member_portal' ? 'public/payments.php?paid=1' : 'admin/manage_payments.php';
        }

        if (($member_id <= 0 && !$is_fan_ticket_purchase) || $amount <= 0) {
            $message = 'Unable to save payment because the member or amount information is missing.';
        } else {
            $payment_method = 'Paystack';
            $record = $member_id > 0
                ? record_payment($conn, $member_id, $amount, $payment_method, $description, $reference, $source, 'Paid')
                : ['success' => true, 'payment_id' => null, 'duplicate' => false];

            if (!empty($record['success'])) {
                    $success = true;
                    $message = !empty($record['duplicate'])
                        ? 'Paystack payment was already recorded.'
                        : 'Paystack payment completed and recorded successfully.';

                    if ($member_id > 0 && empty($record['duplicate']) && $plan_id > 0) {
                        activate_membership_for_payment($conn, $member_id, $plan_id, (int) $record['payment_id']);
                    }

                    if (empty($record['duplicate']) && !empty($metadata['promo_id'])) {
                        asc_redeem_promo_code($conn, (int) $metadata['promo_id']);
                    }

                    // Fix 4: If this was an auto-renewal charge, activate the membership
                    // here (on confirmed webhook) instead of in the cron, and update
                    // the renewal log to Success. Also reset the reminder flag so the
                    // next cycle can send fresh reminders for the new membership period.
                    if ($is_auto_renewal && $member_id > 0 && empty($record['duplicate'])) {
                        require_once __DIR__ . '/includes/renewal_helpers.php';
                        $renewal_log_id  = isset($metadata['renewal_log_id'])  ? (int) $metadata['renewal_log_id']  : 0;
                        $membership_id_m = isset($metadata['membership_id'])    ? (int) $metadata['membership_id']   : 0;
                        if ($renewal_log_id > 0) {
                            update_renewal_log($conn, $renewal_log_id, 'Success', $reference);
                        }
                        if ($membership_id_m > 0) {
                            reset_renewal_reminder_sent($conn, $membership_id_m);
                        }
                    }

                    if ($is_ticket_purchase && ticketing_ensure_schema($conn)) {
                        $ticket_result = ticketing_finalize_paid_order(
                            $conn,
                            $ticket_order_id,
                            $record['payment_id'] ?? null,
                            $reference,
                            'Paystack',
                            empty($record['duplicate'])
                        );

                        if (empty($ticket_result['success'])) {
                            $success = false;
                            $message = 'Payment was recorded, but ticket issuing failed: ' . ($ticket_result['error'] ?? 'Unknown ticket error.');
                        }
                    }

                    $member_email = '';
                    $member_fname = '';
                    $member_lname = '';
                    if ($member_id > 0) {
                        $sql_member = 'SELECT email, first_name, last_name FROM members WHERE member_id = ?';
                        if ($stmt_m = $conn->prepare($sql_member)) {
                            $stmt_m->bind_param('i', $member_id);
                            $stmt_m->execute();
                            $stmt_m->bind_result($member_email, $member_fname, $member_lname);
                            $stmt_m->fetch();
                            $stmt_m->close();
                        }
                    }

                    if ($member_email && empty($record['duplicate']) && !$is_ticket_purchase) {
                        sendEmail(
                            $member_email,
                            trim($member_fname . ' ' . $member_lname),
                            '🧾 Payment Receipt — Apex Sports Club',
                            emailPaymentReceipt($member_fname ?: 'Member', $amount, 'Paystack', $description)
                        );
                    }
            } else {
                $message = 'Failed to record payment: ' . ($record['error'] ?? 'Database error.');
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
            <a href="<?php echo htmlspecialchars($return_url); ?>" class="btn btn-primary">Continue</a>
        </div>
    </div>
</div>
</body>
</html>
