<?php

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/ticket_helpers.php';
require_once __DIR__ . '/../includes/mpesa_helpers.php';
require_once __DIR__ . '/../includes/promo_codes.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

$client_ip = mpesa_get_client_ip();
if (!mpesa_validate_ip($client_ip)) {
    error_log("Unauthorized M-Pesa callback attempt from IP: " . $client_ip);
    log_security_event_throttled('callback_reject', 'critical', 'M-Pesa callback from IP outside the Safaricom allow-list', $client_ip);
    http_response_code(403);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Forbidden']);
    exit;
}

// Defense-in-depth: cap callbacks per client IP even after the allow-list
// check, so a compromised/rotated sender can't flood the endpoint.
if (!rate_limit_check('mpesa_cb_' . md5($client_ip), 120, 60)) {
    log_security_event_throttled('callback_reject', 'warning', 'M-Pesa callback rate limit exceeded', $client_ip);
    http_response_code(429);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Rate limited']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid payload']);
    exit;
}

mpesa_log_callback($data);

$resultCode = $data['Body']['stkCallback']['ResultCode'] ?? -1;

if ((int) $resultCode !== 0) {
    $conn->close();
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

$callback = $data['Body']['stkCallback'] ?? [];
$items = $callback['CallbackMetadata']['Item'] ?? [];
$checkoutId = trim((string) ($callback['CheckoutRequestID'] ?? ''));
$metadata = [];

foreach ($items as $item) {
    if (isset($item['Name'])) {
        $metadata[$item['Name']] = $item['Value'] ?? '';
    }
}

$amount = (float) ($metadata['Amount'] ?? ($items[0]['Value'] ?? 0));
$mpesaCode = trim((string) ($metadata['MpesaReceiptNumber'] ?? ($items[1]['Value'] ?? '')));

if ($checkoutId === '' || $mpesaCode === '' || $amount <= 0) {
    $conn->close();
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    exit;
}

$processed = false;

if (ticketing_schema_ready($conn)) {
    $ticketOrder = ticketing_fetch_order_by_checkout($conn, $checkoutId);

    if ($ticketOrder) {
        if (!mpesa_amounts_match((float) $ticketOrder['total_amount'], $amount)) {
            error_log('M-Pesa callback amount mismatch for ticket order #' . $ticketOrder['order_id']);
        } elseif (($ticketOrder['status'] ?? '') === 'Pending' || ($ticketOrder['status'] ?? '') === 'Paid') {
            $memberId = empty($ticketOrder['member_id']) ? null : (int) $ticketOrder['member_id'];
            $record = $memberId
                ? record_payment(
                    $conn,
                    $memberId,
                    $amount,
                    'M-Pesa',
                    'Ticket order #' . $ticketOrder['order_id'] . ' - ' . $mpesaCode,
                    $mpesaCode,
                    'ticket_purchase',
                    'Paid'
                )
                : [
                    'success' => true,
                    'payment_id' => null,
                    'duplicate' => $ticketOrder['status'] === 'Paid'
                        && ticketing_order_tickets_exist($conn, (int) $ticketOrder['order_id']),
                ];

            if (!empty($record['success']) && mpesa_amounts_match((float) $ticketOrder['total_amount'], $amount)) {
                $ticketResult = ticketing_finalize_paid_order(
                    $conn,
                    (int) $ticketOrder['order_id'],
                    $record['payment_id'] ?? null,
                    $mpesaCode,
                    'M-Pesa',
                    false
                );

                if (!empty($ticketResult['success']) && empty($record['duplicate']) && empty($ticketResult['duplicate'])) {
                    $stmt_ticket = $conn->prepare(
                        "SELECT COALESCE(m.first_name, o.buyer_name, 'Fan') AS first_name,
                                COALESCE(m.email, o.buyer_email) AS email,
                                f.match_date, f.match_time, f.venue,
                                h.name AS home_team, a.name AS away_team,
                                l.name AS league_name, s.name AS sport_name,
                                o.quantity,
                                GROUP_CONCAT(t.ticket_code ORDER BY t.ticket_id SEPARATOR ',') AS codes
                         FROM ticket_orders o
                         LEFT JOIN members m ON m.member_id = o.member_id
                         JOIN fixtures f ON f.fixture_id = o.fixture_id
                         JOIN teams h    ON h.team_id    = f.home_team_id
                         JOIN teams a    ON a.team_id    = f.away_team_id
                         JOIN leagues l  ON l.league_id  = f.league_id
                         JOIN sports s   ON s.sport_id   = l.sport_id
                         LEFT JOIN tickets t ON t.order_id = o.order_id
                         WHERE o.order_id = ?
                         GROUP BY o.order_id"
                    );
                    $orderId = (int) $ticketOrder['order_id'];
                    $stmt_ticket->bind_param('i', $orderId);
                    $stmt_ticket->execute();
                    $trow = $stmt_ticket->get_result()->fetch_assoc();
                    $stmt_ticket->close();

                    if ($trow && !empty($trow['email'])) {
                        $codes = array_filter(explode(',', $trow['codes'] ?? ''));
                        $fixture_label = $trow['home_team'] . ' vs ' . $trow['away_team'];

                        if (!empty($codes)) {
                            sendEmail(
                                $trow['email'],
                                $trow['first_name'],
                                'Your Match Ticket - ' . $fixture_label . ' | Apex Sports Club',
                                emailTicketConfirmation(
                                    $trow['first_name'],
                                    $fixture_label,
                                    $trow['sport_name'] . ' - ' . $trow['league_name'],
                                    $trow['match_date'],
                                    $trow['match_time'],
                                    $trow['venue'],
                                    $amount,
                                    $mpesaCode,
                                    $codes
                                )
                            );
                        }
                    }
                }

                $processed = true;
            }
        }
    }
}

if (!$processed) {
    $pending = mpesa_fetch_pending_by_checkout($conn, $checkoutId);

    if (
        $pending
        && ($pending['status'] ?? '') === 'Pending'
        && mpesa_amounts_match((float) $pending['amount'], $amount)
        && !empty($pending['member_id'])
    ) {
        $memberId = (int) $pending['member_id'];
        $source   = $pending['source'] ?? 'mpesa_callback';

        $record = record_payment(
            $conn,
            $memberId,
            $amount,
            'M-Pesa',
            $pending['description'] ?: ('M-Pesa payment — ' . $mpesaCode),
            $mpesaCode,
            $source,
            'Paid'
        );

        if (!empty($record['success']) && empty($record['duplicate'])) {
            mpesa_mark_pending_completed($conn, (int) $pending['pending_id']);

            $meta = mpesa_parse_pending_meta($pending['description'] ?? '');
            if (!empty($meta['promo_id'])) {
                asc_redeem_promo_code($conn, (int) $meta['promo_id']);
            }

            // M-Pesa plan purchase from payments page: activate membership
            if ($source === 'membership_payment') {
                $plan_id_meta = isset($meta['plan_id']) ? (int) $meta['plan_id'] : 0;
                if ($plan_id_meta > 0) {
                    activate_membership_for_payment($conn, $memberId, $plan_id_meta, (int) ($record['payment_id'] ?? 0));
                }

                $stmt_m = $conn->prepare('SELECT first_name, email FROM members WHERE member_id = ? LIMIT 1');
                if ($stmt_m) {
                    $stmt_m->bind_param('i', $memberId);
                    $stmt_m->execute();
                    $stmt_m->bind_result($fname, $femail);
                    if ($stmt_m->fetch() && $femail) {
                        sendEmail(
                            $femail, $fname,
                            '✓ Your Membership Has Been Activated via M-Pesa',
                            "<p>Hi {$fname},</p>
                             <p>Your membership has been successfully activated.</p>
                             <p>M-Pesa receipt: <strong>{$mpesaCode}</strong><br>
                             Amount: <strong>KES " . number_format($amount, 2) . "</strong></p>
                             <p>Thank you,<br>Apex Sports Club</p>"
                        );
                    }
                    $stmt_m->close();
                }

            // Auto-renewal: activate membership and update renewal log
            } elseif ($source === 'auto_renewal') {
                require_once __DIR__ . '/../includes/renewal_helpers.php';

                $plan_id_meta        = isset($meta['plan_id'])        ? (int) $meta['plan_id']        : 0;
                $renewal_log_id_meta = isset($meta['renewal_log_id']) ? (int) $meta['renewal_log_id'] : 0;
                $membership_id_meta  = isset($meta['membership_id'])  ? (int) $meta['membership_id']  : 0;

                if ($plan_id_meta > 0) {
                    activate_membership_for_payment($conn, $memberId, $plan_id_meta, (int) ($record['payment_id'] ?? 0));
                }
                if ($renewal_log_id_meta > 0) {
                    update_renewal_log($conn, $renewal_log_id_meta, 'Success', $mpesaCode);
                }
                if ($membership_id_meta > 0) {
                    reset_renewal_reminder_sent($conn, $membership_id_meta);
                }

                // Send renewal confirmation email
                $stmt_m = $conn->prepare('SELECT first_name, email FROM members WHERE member_id = ? LIMIT 1');
                if ($stmt_m) {
                    $stmt_m->bind_param('i', $memberId);
                    $stmt_m->execute();
                    $stmt_m->bind_result($fname, $femail);
                    if ($stmt_m->fetch() && $femail) {
                        sendEmail(
                            $femail, $fname,
                            '✓ Your Membership Has Been Renewed via M-Pesa',
                            "<p>Hi {$fname},</p>
                             <p>Your membership has been successfully renewed.</p>
                             <p>M-Pesa receipt: <strong>{$mpesaCode}</strong><br>
                             Amount: <strong>KES " . number_format($amount, 2) . "</strong></p>
                             <p>Thank you,<br>Apex Sports Club</p>"
                        );
                    }
                    $stmt_m->close();
                }

            } else {
                // Regular M-Pesa payment — send standard receipt
                $stmt = $conn->prepare('SELECT first_name, email FROM members WHERE member_id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $memberId);
                    $stmt->execute();
                    $stmt->bind_result($member_fname, $member_email);
                    if ($stmt->fetch() && $member_email) {
                        sendEmail(
                            $member_email, $member_fname,
                            'M-Pesa Payment Receipt — Apex Sports Club',
                            emailPaymentReceipt($member_fname, $amount, 'M-Pesa', $pending['description'] ?: $mpesaCode)
                        );
                    }
                    $stmt->close();
                }
            }
        }
    }
}

$conn->close();
http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
