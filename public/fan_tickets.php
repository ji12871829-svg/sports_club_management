<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/ticket_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

if (empty($_SESSION['fan_ticket_csrf'])) {
    $_SESSION['fan_ticket_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['fan_ticket_csrf'];
$message = '';
$schema_ready = ticketing_ensure_schema($conn);
$selected_fixture_id = (int) ($_GET['fixture_id'] ?? ($_POST['fixture_id'] ?? 0));

$form = [
    'buyer_name' => trim($_POST['buyer_name'] ?? ''),
    'buyer_email' => trim($_POST['buyer_email'] ?? ''),
    'buyer_phone' => trim($_POST['buyer_phone'] ?? ''),
    'payment_method' => trim($_POST['payment_method'] ?? 'Paystack'),
    'quantity' => max(1, min(10, (int) ($_POST['quantity'] ?? 1))),
    'supported_team_id' => (int) ($_POST['supported_team_id'] ?? 0),
];

if (!$schema_ready) {
    $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'><i class='fas fa-exclamation-triangle me-2'></i>Ticketing tables are not ready. Import <code>ticketing_schema.sql</code>.</div>";
}

if ($schema_ready && isset($_GET['paid'])) {
    $message = "<div class='alert alert-success border-0 shadow-sm rounded-4 mb-4'><i class='fas fa-check-circle me-2'></i>Payment confirmed. Your QR ticket has been sent to your email.</div>";
}

if ($schema_ready && isset($_GET['pending'])) {
    $message = "<div class='alert alert-info border-0 shadow-sm rounded-4 mb-4'><i class='fas fa-info-circle me-2'></i>Payment request sent. Your QR ticket will be emailed after payment is confirmed.</div>";
}

if ($schema_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['csrf_token'] ?? '';
    $fixture_id = (int) ($_POST['fixture_id'] ?? 0);
    $supported_team_id = (int) ($_POST['supported_team_id'] ?? 0);
    $quantity = $form['quantity'];
    $payment_method = $form['payment_method'];
    $allowed_methods = ['Paystack', 'M-Pesa'];

    $fixture = ticketing_fetch_fixture($conn, $fixture_id);
    $ticket_info = $fixture ? ticketing_fetch_fixture_ticket_info($conn, $fixture_id) : null;

    if (!hash_equals($csrf, $posted)) {
        $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Security check failed. Please refresh and try again.</div>";
    } elseif (!$fixture) {
        $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Please select a valid fixture.</div>";
    } elseif (!ticketing_fixture_is_saleable($fixture, $ticket_info)) {
        $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Tickets are not available for this fixture.</div>";
    } elseif ($form['buyer_name'] === '') {
        $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Please enter your full name.</div>";
    } elseif (!filter_var($form['buyer_email'], FILTER_VALIDATE_EMAIL)) {
        $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Please enter a valid email address.</div>";
    } elseif ($form['buyer_phone'] === '') {
        $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Please enter your phone number.</div>";
    } elseif (!in_array($supported_team_id, [(int) $fixture['home_team_id'], (int) $fixture['away_team_id']], true)) {
        $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Please choose the team you support for this fixture.</div>";
    } elseif (!in_array($payment_method, $allowed_methods, true)) {
        $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Please select a valid payment method.</div>";
    } elseif ($ticket_info['available'] !== null && $quantity > $ticket_info['available']) {
        $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Only " . e($ticket_info['available']) . " ticket(s) are available for this fixture.</div>";
    } else {
        $unit_price = (float) $ticket_info['ticket_price'];
        $order_id = ticketing_create_order(
            $conn,
            null,
            $fixture_id,
            $supported_team_id,
            $quantity,
            $unit_price,
            $payment_method,
            [
                'name' => $form['buyer_name'],
                'email' => $form['buyer_email'],
                'phone' => $form['buyer_phone'],
            ]
        );

        if (!$order_id) {
            $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Could not create the ticket order.</div>";
        } elseif ($payment_method === 'Paystack') {
            try {
                require_once '../includes/paystack.php';
                $paystackResult = paystackInitTransaction(
                    $form['buyer_email'],
                    $unit_price * $quantity,
                    PAYSTACK_CALLBACK_URL,
                    [
                        'ticket_order_id' => $order_id,
                        'fixture_id' => $fixture_id,
                        'source' => 'fan_ticket_purchase',
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
                $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>" . e($error) . "</div>";
            } catch (Throwable $e) {
                ticketing_mark_order_failed($conn, $order_id);
                $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>Paystack is not configured: " . e($e->getMessage()) . "</div>";
            }
        } else {
            require_once '../includes/mpesa.php';
            $mpesaResult = mpesaSTKPush(
                formatMpesaPhone($form['buyer_phone']),
                $unit_price * $quantity,
                'Apex ticket order ' . $order_id
            );

            if (($mpesaResult['ResponseCode'] ?? '') === '0') {
                ticketing_update_order_provider($conn, $order_id, null, $mpesaResult['CheckoutRequestID'] ?? null);
                header('Location: fan_tickets.php?pending=1');
                exit;
            }

            ticketing_mark_order_failed($conn, $order_id);
            $error = $mpesaResult['error'] ?? $mpesaResult['errorMessage'] ?? $mpesaResult['ResponseDescription'] ?? 'Unable to send M-Pesa STK push.';
            $message = "<div class='alert alert-danger border-0 shadow-sm rounded-4 mb-4'>" . e($error) . "</div>";
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
    body { background-color: #f8fafc !important; color: #334155; font-family: system-ui, -apple-system, sans-serif; }
    .page-title { color: #0f172a; font-weight: 800; letter-spacing: -0.5px; }
    .ticket-panel {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02), 0 2px 4px -1px rgba(0, 0, 0, 0.02);
        overflow: hidden;
        margin-bottom: 2rem;
    }
    .ticket-panel-header {
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.5rem;
    }
    .form-control-ticket {
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        color: #0f172a;
        font-size: 0.95rem;
        padding: 0.65rem 0.85rem;
        font-weight: 500;
        transition: all 0.2s ease;
    }
    .form-control-ticket:focus {
        border-color: #1d5c8f;
        box-shadow: 0 0 0 4px rgba(20, 73, 122, 0.1);
        background-color: #fff;
    }
    .btn-ticket-primary {
        background: #1d5c8f;
        border: 1px solid #1d5c8f;
        color: #ffffff;
        border-radius: 8px;
        font-weight: 600;
        padding: 0.65rem 1.25rem;
        transition: all 0.15s ease;
    }
    .btn-ticket-primary:hover { background: #14497a; border-color: #14497a; color: #ffffff; transform: translateY(-1px); }
    .btn-ticket-outline {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        color: #475569;
        border-radius: 8px;
        font-weight: 600;
        padding: 0.65rem 1.25rem;
        transition: all 0.15s ease;
    }
    .btn-ticket-outline:hover { background: #f8fafc; border-color: #94a3b8; color: #1e293b; }
    .match-row { 
        border-bottom: 1px solid #f1f5f9; 
        padding: 1.25rem 1.5rem; 
        transition: background-color 0.2s ease;
    }
    .match-row:hover { background-color: #fafafa; }
    .match-row:last-child { border-bottom: none; }
    .total-box {
        background: #f8fafc;
        border: 1px solid #cbd5e1;
        border-radius: 8px;
        color: #0f172a;
        font-family: monospace;
        font-weight: 700;
        font-size: 1.1rem;
        padding: 0.58rem 0.85rem;
        display: flex;
        align-items: center;
    }
    .sport-badge {
        background-color: #e8f1f8;
        color: #14497a;
        font-weight: 600;
        font-size: 0.75rem;
        padding: 0.35rem 0.75rem;
        border-radius: 6px;
        text-uppercase: uppercase;
        letter-spacing: 0.5px;
    }
    .stat-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #64748b; }
    .sport-ticket-nav .btn { border-radius: 999px; font-weight: 600; }
    .accordion-button:not(.collapsed) { background: #e8f1f8; color: #0e3a5f; box-shadow: none; }
    .accordion-button:focus { box-shadow: 0 0 0 3px rgba(20, 73, 122, 0.15); }
</style>

<div class="container my-5">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-4 mb-4 pb-4 border-bottom">
        <div>
            <h1 class="page-title mb-1">Matchday Ticket Gateway</h1>
            <p class="text-muted mb-0">Secure booking engine verification channels. Club membership registration is optional for match passes.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="register.php" class="btn btn-ticket-outline text-decoration-none">
                <i class="fas fa-user-plus me-2 text-muted"></i>Become a Member
            </a>
            <a href="login.php" class="btn btn-ticket-primary text-decoration-none">
                <i class="fas fa-sign-in-alt me-2"></i>Member Login Portal
            </a>
        </div>
    </div>

    <?php echo $message; ?>

    <?php if ($selected_fixture): ?>
        <?php $info = $selected_fixture['ticket_info']; ?>
        <div class="ticket-panel shadow-sm">
            <div class="ticket-panel-header">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <div class="mb-2">
                            <span class="sport-badge"><?php echo e($selected_fixture['sport_name']); ?></span>
                            <span class="text-muted small ms-2 fw-semibold"><?php echo e($selected_fixture['league_name']); ?></span>
                        </div>
                        <h2 class="h4 mb-2 text-dark font-weight-bold" style="font-weight:700; letter-spacing:-0.3px;"><?php echo e($selected_fixture['home_team'] . ' vs ' . $selected_fixture['away_team']); ?></h2>
                        <p class="text-muted small mb-0 d-flex align-items-center gap-2">
                            <span><i class="far fa-calendar-alt text-muted"></i> <?php echo e(date('l, d M Y', strtotime($selected_fixture['match_date']))); ?></span>
                            <span>•</span>
                            <span><i class="far fa-clock text-muted"></i> <?php echo e(substr((string) $selected_fixture['match_time'], 0, 5)); ?> KEA</span>
                            <span>•</span>
                            <span><i class="fas fa-map-marker-alt text-muted"></i> <?php echo e($selected_fixture['venue'] ?: 'Venue TBC'); ?></span>
                        </p>
                    </div>
                    <div class="text-md-end">
                        <div class="stat-label">Unit Price</div>
                        <div class="h4 mb-0 text-primary font-weight-bold" style="font-weight:800;">KES <?php echo number_format((float) $info['ticket_price'], 2); ?></div>
                    </div>
                </div>
            </div>
            <div class="p-4 bg-light-subtle">
                <?php if (ticketing_fixture_is_saleable($selected_fixture, $info)): ?>
                    <form method="post" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                        <input type="hidden" name="fixture_id" value="<?php echo e($selected_fixture['fixture_id']); ?>">

                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label stat-label">Full Name</label>
                                <input type="text" name="buyer_name" class="form-control form-control-ticket" value="<?php echo e($form['buyer_name']); ?>" placeholder="e.g. John Doe" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label stat-label">Email Address</label>
                                <input type="email" name="buyer_email" class="form-control form-control-ticket" value="<?php echo e($form['buyer_email']); ?>" placeholder="johndoe@example.com" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label stat-label">Phone Number</label>
                                <input type="tel" name="buyer_phone" class="form-control form-control-ticket" value="<?php echo e($form['buyer_phone']); ?>" placeholder="e.g. 0712345678" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label stat-label">Supported Team</label>
                                <select name="supported_team_id" class="form-select form-control-ticket" required>
                                    <option value="">Select team</option>
                                    <option value="<?php echo e($selected_fixture['home_team_id']); ?>" <?php echo $form['supported_team_id'] === (int) $selected_fixture['home_team_id'] ? 'selected' : ''; ?>><?php echo e($selected_fixture['home_team']); ?> (Home)</option>
                                    <option value="<?php echo e($selected_fixture['away_team_id']); ?>" <?php echo $form['supported_team_id'] === (int) $selected_fixture['away_team_id'] ? 'selected' : ''; ?>><?php echo e($selected_fixture['away_team']); ?> (Away)</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label stat-label">Quantity</label>
                                <input type="number" name="quantity" id="ticket_quantity" class="form-control form-control-ticket" min="1" max="10" value="<?php echo e($form['quantity']); ?>" data-price="<?php echo e($info['ticket_price']); ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label stat-label">Payment Channel</label>
                                <select name="payment_method" class="form-select form-control-ticket">
                                    <option value="Paystack" <?php echo $form['payment_method'] === 'Paystack' ? 'selected' : ''; ?>>Paystack (Card/M-Pesa)</option>
                                    <option value="M-Pesa" <?php echo $form['payment_method'] === 'M-Pesa' ? 'selected' : ''; ?>>Direct M-Pesa STK Push</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label stat-label">Aggregate Summary</label>
                                <div class="total-box" id="ticket_total">KES <?php echo number_format((float) $info['ticket_price'] * $form['quantity'], 2); ?></div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4 pt-3 border-top">
                            <button type="submit" class="btn btn-ticket-primary">
                                <i class="fas fa-shield-alt me-2"></i>Proceed to Secure Checkout
                            </button>
                            <a href="fan_tickets.php" class="btn btn-ticket-outline text-decoration-none">Cancel & Change Match</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning border-0 rounded-3 mb-0"><i class='fas fa-exclamation-circle me-2'></i>Tickets are not available for this fixture.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="ticket-panel shadow-sm">
        <div class="ticket-panel-header bg-white">
            <h2 class="h5 mb-0 text-dark font-weight-bold" style="font-weight:700;"><i class="fas fa-calendar-alt text-muted me-2"></i>Tickets by Sport</h2>
            <p class="text-muted small mb-0 mt-1">Choose a sport category, then pick a match to book.</p>
        </div>
        <?php
            $purchase_page = 'fan_tickets.php';
            $list_style = 'fan';
            include '../includes/ticket_fixture_list.php';
        ?>
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