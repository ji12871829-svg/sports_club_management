<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/ticket_helpers.php';
require_once '../config/api_config.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

if (empty($_SESSION['ticket_csrf'])) {
    $_SESSION['ticket_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['ticket_csrf'];

$member_id = (int) $_SESSION['member_id'];
$member = ticketing_fetch_member($conn, $member_id);
$message = '';
$schema_ready = ticketing_ensure_schema($conn);
$selected_fixture_id = (int) ($_GET['fixture_id'] ?? ($_POST['fixture_id'] ?? 0));

if (!$schema_ready) {
    $message = "<div class='alert alert-danger border-0 shadow-sm'><i class='fas fa-exclamation-triangle me-2'></i>Ticketing tables are not ready. Import <code>ticketing_schema.sql</code>.</div>";
}

if ($schema_ready && isset($_GET['pending'])) {
    $message = "<div class='alert alert-info border-0 shadow-sm'><i class='fas fa-info-circle me-2'></i>Payment request sent. Your QR ticket will be emailed after payment is confirmed.</div>";
}

if ($schema_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrf, $posted)) {
        $message = "<div class='alert alert-danger border-0 shadow-sm'><i class='fas fa-shield-alt me-2'></i>Security check failed. Please refresh and try again.</div>";
    } else {
        $fixture_id = (int) ($_POST['fixture_id'] ?? 0);
        $supported_team_id = (int) ($_POST['supported_team_id'] ?? 0);
        $quantity = max(1, min(10, (int) ($_POST['quantity'] ?? 1)));
        $payment_method = trim($_POST['payment_method'] ?? 'Paystack');
        $allowed_methods = ['Paystack', 'M-Pesa'];

        $fixture = ticketing_fetch_fixture($conn, $fixture_id);
        $ticket_info = $fixture ? ticketing_fetch_fixture_ticket_info($conn, $fixture_id) : null;

        if (!$fixture) {
            $message = "<div class='alert alert-danger border-0 shadow-sm'>Please select a valid fixture.</div>";
        } elseif (!ticketing_fixture_is_saleable($fixture, $ticket_info)) {
            $message = "<div class='alert alert-danger border-0 shadow-sm'>Tickets are not available for this fixture.</div>";
        } elseif (!in_array($supported_team_id, [(int) $fixture['home_team_id'], (int) $fixture['away_team_id']], true)) {
            $message = "<div class='alert alert-danger border-0 shadow-sm'>Please choose the team you support for this fixture.</div>";
        } elseif (!in_array($payment_method, $allowed_methods, true)) {
            $message = "<div class='alert alert-danger border-0 shadow-sm'>Please select a valid payment method.</div>";
        } elseif ($ticket_info['available'] !== null && $quantity > $ticket_info['available']) {
            $message = "<div class='alert alert-danger border-0 shadow-sm'>Only " . e($ticket_info['available']) . " ticket(s) are available for this fixture.</div>";
        } elseif ($payment_method === 'Paystack' && empty($member['email'])) {
            $message = "<div class='alert alert-danger border-0 shadow-sm'>Your account needs an email address before Paystack checkout.</div>";
        } elseif ($payment_method === 'M-Pesa' && empty($member['phone_number'])) {
            $message = "<div class='alert alert-danger border-0 shadow-sm'>Add a phone number to your profile before using M-Pesa.</div>";
        } else {
            $unit_price = (float) $ticket_info['ticket_price'];
            $order_id = ticketing_create_order(
                $conn,
                $member_id,
                $fixture_id,
                $supported_team_id,
                $quantity,
                $unit_price,
                $payment_method
            );

            if (!$order_id) {
                $message = "<div class='alert alert-danger border-0 shadow-sm'>Could not create the ticket order.</div>";
            } elseif ($payment_method === 'Paystack') {
                try {
                    require_once '../includes/paystack.php';
                    $paystackResult = paystackInitTransaction(
                        $member['email'],
                        $unit_price * $quantity,
                        PAYSTACK_CALLBACK_URL,
                        [
                            'member_id' => $member_id,
                            'ticket_order_id' => $order_id,
                            'fixture_id' => $fixture_id,
                            'source' => 'ticket_purchase',
                            'description' => 'Ticket: ' . $fixture['home_team'] . ' vs ' . $fixture['away_team']
                        ]
                    );

                    if (!empty($paystackResult['status']) && !empty($paystackResult['data']['authorization_url'])) {
                        $reference = $paystackResult['data']['reference'] ?? null;
                        ticketing_update_order_provider($conn, $order_id, $reference, null);
                        header('Location: ' . $paystackResult['data']['authorization_url']);
                        exit;
                    }

                    ticketing_mark_order_failed($conn, $order_id);
                    $error = $paystackResult['message'] ?? 'Unable to initialize Paystack checkout.';
                    $message = "<div class='alert alert-danger border-0 shadow-sm'>" . e($error) . "</div>";
                } catch (Throwable $e) {
                    ticketing_mark_order_failed($conn, $order_id);
                    $message = "<div class='alert alert-danger border-0 shadow-sm'>Paystack is not configured: " . e($e->getMessage()) . "</div>";
                }
            } else {
                require_once '../includes/mpesa.php';
                $mpesaResult = mpesaSTKPush(
                    formatMpesaPhone($member['phone_number']),
                    $unit_price * $quantity,
                    'Apex ticket order ' . $order_id
                );

                if (($mpesaResult['ResponseCode'] ?? '') === '0') {
                    ticketing_update_order_provider($conn, $order_id, null, $mpesaResult['CheckoutRequestID'] ?? null);
                    header('Location: tickets.php?pending=1');
                    exit;
                }

                ticketing_mark_order_failed($conn, $order_id);
                $error = $mpesaResult['error'] ?? $mpesaResult['errorMessage'] ?? $mpesaResult['ResponseDescription'] ?? 'Unable to send M-Pesa STK push.';
                $message = "<div class='alert alert-danger border-0 shadow-sm'>" . e($error) . "</div>";
            }
        }
    }
}

$fixtures = $schema_ready ? ticketing_fetch_upcoming_fixtures($conn) : [];
$fixtures_by_sport = ticketing_group_fixtures_by_sport($fixtures);
$selected_fixture = $schema_ready
    ? ticketing_resolve_selected_fixture($conn, $fixtures, $selected_fixture_id)
    : null;

$conn->close();
include '../includes/header.php';
?>

<style>
    body {
        background-color: #f8fafc !important;
        color: #334155;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    .page-title {
        color: #0f172a;
        font-weight: 700;
        letter-spacing: -0.5px;
    }
    .ledger-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }
    .ledger-header {
        background-color: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.5rem;
    }
    .checkout-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        padding: 1.5rem;
    }
    .table-ledger th {
        background-color: #f8fafc;
        color: #64748b;
        font-family: monospace;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
    }
    .table-ledger td {
        padding: 1.2rem 1.25rem;
        color: #334155;
        font-size: 0.9rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .table-ledger tr:last-child td {
        border-bottom: none;
    }
    .form-control-premium {
        background-color: #ffffff;
        border: 1px solid #cbd5e1;
        color: #0f172a;
        font-size: 0.9rem;
        padding: 0.5rem 0.75rem;
        border-radius: 6px;
    }
    .form-control-premium:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        outline: none;
    }
    .total-display-box {
        background-color: #ffffff;
        border: 1px solid #cbd5e1;
        font-family: monospace;
        font-weight: 700;
        color: #0f172a;
        font-size: 1rem;
        padding: 0.5rem 0.75rem;
        border-radius: 6px;
        display: flex;
        align-items: center;
    }
    .btn-premium-primary {
        background-color: #2563eb;
        color: #ffffff;
        font-weight: 600;
        border: none;
        padding: 0.5rem 1.25rem;
        border-radius: 6px;
        transition: background 0.15s ease;
    }
    .btn-premium-primary:hover {
        background-color: #1d4ed8;
        color: #ffffff;
    }
    .btn-premium-outline {
        border: 1px solid #cbd5e1;
        background-color: #ffffff;
        color: #475569;
        font-weight: 600;
        padding: 0.5rem 1.25rem;
        border-radius: 6px;
        transition: all 0.15s ease;
    }
    .btn-premium-outline:hover {
        background-color: #f1f5f9;
        color: #0f172a;
    }
    .text-cell-dark {
        color: #0f172a;
        font-weight: 600;
    }
    .badge-sales {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
    }
</style>

<div class="container-fluid my-4 px-md-4">
    
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom border-light">
        <div>
            <h2 class="page-title mb-1">Match Day Ticketing</h2>
            <p class="text-muted small mb-0">Secure real-time entry pass provisioning and gate terminal distribution.</p>
        </div>
        <div>
            <a href="my_tickets.php" class="btn btn-premium-outline text-decoration-none">
                <i class="fas fa-ticket-alt me-2 small"></i>My Ticket Wallet
            </a>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            
            <?php echo $message; ?>

            <?php if ($selected_fixture): ?>
                <?php $info = $selected_fixture['ticket_info']; ?>
                <div class="card ledger-card mb-4">
                    <div class="ledger-header bg-light border-bottom">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <span class="badge bg-primary mb-2 text-uppercase font-monospace" style="font-size:0.7rem; letter-spacing: 0.5px;">
                                    <?php echo e($selected_fixture['sport_name']); ?> • <?php echo e($selected_fixture['league_name']); ?>
                                </span>
                                <h4 class="mb-1 text-cell-dark fs-5"><?php echo e($selected_fixture['home_team'] . ' vs ' . $selected_fixture['away_team']); ?></h4>
                                <p class="text-muted small mb-0">
                                    <i class="far fa-calendar me-1"></i> <?php echo e(date('d M Y', strtotime($selected_fixture['match_date']))); ?> at <?php echo e(substr((string) $selected_fixture['match_time'], 0, 5)); ?> | 
                                    <i class="fas fa-map-marker-alt me-1"></i> <?php echo e($selected_fixture['venue'] ?: 'Venue TBC'); ?>
                                </p>
                            </div>
                            <div class="text-end">
                                <span class="text-muted small d-block">Unit Price</span>
                                <span class="text-cell-dark font-monospace fs-5">KES <?php echo number_format((float) $info['ticket_price'], 2); ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card-body p-4">
                        <?php if (ticketing_fixture_is_saleable($selected_fixture, $info)): ?>
                            <form method="post" class="m-0">
                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                                <input type="hidden" name="fixture_id" value="<?php echo e($selected_fixture['fixture_id']); ?>">

                                <div class="row align-items-end">
                                    <div class="col-md-4 mb-3">
                                        <label class="form-label small fw-bold text-muted uppercase">Allocation Support Sector</label>
                                        <select name="supported_team_id" class="form-select form-control-premium" required>
                                            <option value="">Choose team context...</option>
                                            <option value="<?php echo e($selected_fixture['home_team_id']); ?>"><?php echo e($selected_fixture['home_team']); ?> (Home)</option>
                                            <option value="<?php echo e($selected_fixture['away_team_id']); ?>"><?php echo e($selected_fixture['away_team']); ?> (Away)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-2 mb-3">
                                        <label class="form-label small fw-bold text-muted uppercase">Quantity</label>
                                        <input type="number" name="quantity" id="ticket_quantity" class="form-control form-control-premium font-monospace" value="1" min="1" max="10" data-price="<?php echo e($info['ticket_price']); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label small fw-bold text-muted uppercase">Gateway Provider</label>
                                        <select name="payment_method" class="form-select form-control-premium">
                                            <option value="Paystack">Paystack (Card/Channels)</option>
                                            <option value="M-Pesa">M-Pesa (STK Push)</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label class="form-label small fw-bold text-muted uppercase">Total Gross Settlement</label>
                                        <div class="total-display-box" id="ticket_total">
                                            KES <?php echo number_format((float) $info['ticket_price'], 2); ?>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-flex gap-2 mt-2">
                                    <button type="submit" class="btn btn-premium-primary">
                                        <i class="fas fa-credit-card me-2 small"></i>Initialize Secure Order
                                    </button>
                                    <a href="tickets.php" class="btn btn-premium-outline">
                                        Cancel Workspace
                                    </a>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-warning border-0 m-0"><i class="fas fa-exclamation-circle me-2"></i>Pass processing channels are offline or fully allocated for this timeline item.</div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card ledger-card">
                <div class="ledger-header">
                    <h5 class="mb-0 text-dark fw-bold fs-6">Tickets by Sport</h5>
                    <p class="text-muted small mb-0 mt-1">Expand a sport to see its upcoming matches.</p>
                </div>
                <?php
                    $purchase_page = 'tickets.php';
                    $list_style = 'member';
                    include '../includes/ticket_fixture_list.php';
                ?>
            </div>
            
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var quantityInput = document.getElementById('ticket_quantity');
    var totalBox = document.getElementById('ticket_total');
    if (quantityInput && totalBox) {
        quantityInput.addEventListener('input', function () {
            var price = parseFloat(this.getAttribute('data-price') || '0');
            var quantity = Math.max(1, parseInt(this.value || '1', 10));
            totalBox.textContent = 'KES ' + (price * quantity).toLocaleString(undefined, {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>