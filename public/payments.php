<?php
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
asc_session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/feature_helpers.php';
require_once '../includes/promo_codes.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/url.php';

$member_id = (int) $_SESSION['member_id'];
$message = '';
$amount = '';
$payment_method = 'Paystack';
$description = '';
$selected_plan_id = (int) ($_GET['plan_id'] ?? 0);
$plans_ready = db_table_exists($conn, 'membership_plans') && db_table_exists($conn, 'member_memberships');
$plans = [];
$payments = [];
$member = [
    'email' => $_SESSION['email'] ?? '',
    'first_name' => $_SESSION['first_name'] ?? '',
    'last_name' => '',
    'phone_number' => ''
];

if ($stmt = $conn->prepare("SELECT email, first_name, last_name, phone_number FROM members WHERE member_id = ?")) {
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $member = $row;
    }
    $stmt->close();
}

if ($plans_ready) {
    if ($result = $conn->query("SELECT plan_id, name, price, duration_days FROM membership_plans WHERE status = 'Active' ORDER BY price")) {
        while ($row = $result->fetch_assoc()) {
            $plans[] = $row;
            if ((int) $row['plan_id'] === $selected_plan_id && $_SERVER['REQUEST_METHOD'] !== 'POST') {
                $amount = $row['price'];
                $description = 'Membership: ' . $row['name'];
            }
        }
        $result->free();
    }
}

if (isset($_GET['paid'])) {
    $message = "<div class='alert alert-success' style='border-radius: 8px; font-weight: 500;'>Payment completed successfully. Your records have been updated.</div>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'csrf_token')) {
        $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>Security check failed. Please refresh and try again.</div>";
    } else {
        $selected_plan_id = (int) ($_POST['plan_id'] ?? 0);
        $payment_method = trim($_POST['payment_method'] ?? '');
        $amount = trim($_POST['amount'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $allowed_methods = ['Paystack', 'M-Pesa'];

        if (!in_array($payment_method, $allowed_methods, true)) {
            $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>Please select a valid payment method.</div>";
        }

        if ($selected_plan_id > 0) {
            if (!$plans_ready) {
                $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>Membership plans are not ready. Import feature_upgrades.sql first.</div>";
            } else {
                $plan = null;
                if ($stmt = $conn->prepare("SELECT plan_id, name, price FROM membership_plans WHERE plan_id = ? AND status = 'Active'")) {
                    $stmt->bind_param('i', $selected_plan_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $plan = $result->fetch_assoc();
                    $stmt->close();
                }

                if (!$plan) {
                    $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>Please choose a valid membership plan.</div>";
                } else {
                    $amount = (string) $plan['price'];
                    $description = 'Membership: ' . $plan['name'];
                }
            }
        }

        if (empty($message)) {
            if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) {
                $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>Please enter a valid amount.</div>";
            } else {
                $promo_code = strtoupper(trim($_POST['promo_code'] ?? ''));
                $promo_id = 0;
                $discount = 0.0;
                if ($promo_code !== '') {
                    // Brute-force protection: cap redemption attempts per member
                    // so an attacker can't grind through codes or burn DB lookups.
                    if (!rate_limit_check('promo_redeem_m' . $member_id, 5, 300)) {
                        $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>Too many promo code attempts. Please wait 5 minutes.</div>";
                    } else {
                    $promo_check = asc_validate_promo_code($conn, $promo_code, (float) $amount);
                    if (!$promo_check['ok']) {
                        $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>" . e($promo_check['error']) . "</div>";
                    } else {
                        $promo_id = (int) $promo_check['promo_id'];
                        $discount = (float) $promo_check['discount'];
                        $amount = (string) max(1, round((float) $amount - $discount, 2));
                    }
                    }
                }

                if (empty($message) && $payment_method === 'Paystack') {
                    try {
                        require_once '../includes/paystack.php';
                        $paystackResult = paystackInitTransaction(
                            $member['email'],
                            (float) $amount,
                            PAYSTACK_CALLBACK_URL,
                            [
                                'member_id' => $member_id,
                                'description' => $description ?: 'Apex Sports Club payment',
                                'source' => 'member_portal',
                                'plan_id' => $selected_plan_id,
                                'promo_id' => $promo_id,
                                'discount' => $discount,
                            ]
                        );

                        if (!empty($paystackResult['status']) && !empty($paystackResult['data']['authorization_url'])) {
                            header('Location: ' . $paystackResult['data']['authorization_url']);
                            exit;
                        }

                        $error = $paystackResult['message'] ?? 'Unable to initialize Paystack checkout.';
                        $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>" . e($error) . "</div>";
                    } catch (Throwable $e) {
                        $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>Paystack is not configured: " . e($e->getMessage()) . "</div>";
                    }
                } elseif ($payment_method === 'M-Pesa') {
                    if (empty($member['phone_number'])) {
                        $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>Add a phone number to your profile before using M-Pesa.</div>";
                    } else {
                        require_once '../includes/mpesa.php';
                        require_once '../includes/mpesa_helpers.php';
                        $mpesaResult = mpesaSTKPush(
                            formatMpesaPhone($member['phone_number']),
                            (float) $amount,
                            $description ?: 'Apex Sports Club payment'
                        );

                        if (($mpesaResult['ResponseCode'] ?? '') === '0') {
                            $checkoutId = trim((string) ($mpesaResult['CheckoutRequestID'] ?? ''));
                            if ($checkoutId !== '') {
                                $source = $selected_plan_id > 0 ? 'membership_payment' : 'member_portal';
                                $mpesa_meta = [];
                                if ($selected_plan_id > 0) {
                                    $mpesa_meta['plan_id'] = $selected_plan_id;
                                }
                                if ($promo_id > 0) {
                                    $mpesa_meta['promo_id'] = $promo_id;
                                }
                                mpesa_create_pending(
                                    $conn,
                                    $checkoutId,
                                    (float) $amount,
                                    $description ?: 'Apex Sports Club payment',
                                    $source,
                                    $member_id,
                                    null,
                                    $mpesa_meta !== [] ? $mpesa_meta : null
                                );
                            }
                            $message = "<div class='alert alert-success' style='border-radius: 8px; font-weight: 500;'>M-Pesa payment request sent to your phone. Enter your PIN to complete payment.</div>";
                            $amount = $description = '';
                        } else {
                            $error = $mpesaResult['error'] ?? $mpesaResult['errorMessage'] ?? $mpesaResult['ResponseDescription'] ?? 'Unable to send M-Pesa STK push.';
                            $message = "<div class='alert alert-danger' style='border-radius: 8px; font-weight: 500;'>" . e($error) . "</div>";
                        }
                    }
                }
            }
        }
    }
}

$sql = "SELECT payment_id, amount, payment_date, payment_method, description
        FROM payments
        WHERE member_id = ?
        ORDER BY payment_date DESC
        LIMIT 20";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }
    $stmt->close();
}

$conn->close();
?>

<?php include '../includes/header.php'; ?>

<div class="py-4">
    <div class="md-page-head">
        <div>
            <h1 class="md-page-title">Member Payments</h1>
            <p class="md-page-sub">Pay for memberships, apply promo codes, and track your payment history</p>
        </div>
    </div>

    <?php if (!empty($message)): ?>
        <div class="mb-4"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Make a Payment -->
            <div class="md-card mb-4">
                <div class="md-card-head">
                    <h4 class="md-card-title"><i class="fas fa-credit-card"></i>Make a Payment</h4>
                </div>
                <div class="md-card-body">
                    <form method="post" class="row g-3">
                        <?php echo csrf_field('csrf_token'); ?>

                        <?php if ($plans_ready): ?>
                            <div class="col-12 mb-2">
                                <label for="plan_id" class="md-form-label">Membership Plan <span class="text-muted fw-normal">(optional)</span></label>
                                <select name="plan_id" id="plan_id" class="md-select">
                                    <option value="0">Custom amount &mdash; no plan</option>
                                    <?php foreach ($plans as $plan): ?>
                                        <option
                                            value="<?php echo e($plan['plan_id']); ?>"
                                            data-price="<?php echo e($plan['price']); ?>"
                                            data-name="<?php echo e($plan['name']); ?>"
                                            <?php echo $selected_plan_id === (int) $plan['plan_id'] ? 'selected' : ''; ?>>
                                            <?php echo e($plan['name']); ?> &mdash; KES <?php echo number_format((float) $plan['price'], 2); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>

                        <div class="col-md-6">
                            <label for="amount" class="md-form-label">Amount (KES)</label>
                            <input type="number" min="1" step="0.01" name="amount" id="amount" class="md-input" value="<?php echo e($amount); ?>" required>
                        </div>

                        <div class="col-md-6">
                            <label for="payment_method" class="md-form-label">Payment Method</label>
                            <select name="payment_method" id="payment_method" class="md-select">
                                <option value="Paystack" <?php echo $payment_method === 'Paystack' ? 'selected' : ''; ?>>Paystack</option>
                                <option value="M-Pesa" <?php echo $payment_method === 'M-Pesa' ? 'selected' : ''; ?>>Safaricom M-Pesa STK Push</option>
                            </select>
                        </div>

                        <div class="col-md-8">
                            <label for="description" class="md-form-label">Description / Purpose</label>
                            <input type="text" name="description" id="description" class="md-input" value="<?php echo e($description); ?>" placeholder="e.g. Membership renewal">
                        </div>

                        <div class="col-md-4">
                            <label for="promo_code" class="md-form-label">Promo Code <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="promo_code" id="promo_code" class="md-input text-uppercase" placeholder="SUMMER2026" maxlength="40">
                        </div>

                        <div class="col-12 pt-3">
                            <button type="submit" class="md-btn md-btn-primary shadow-sm">
                                <i class="fas fa-lock"></i> Proceed to Payment
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Payment history -->
            <div class="md-card">
                <div class="md-card-head">
                    <div>
                        <h4 class="md-card-title"><i class="fas fa-receipt"></i>Recent Payments</h4>
                        <small class="text-muted">Your latest <?php echo count($payments); ?> transaction<?php echo count($payments) === 1 ? '' : 's'; ?></small>
                    </div>
                </div>
                <?php if (empty($payments)): ?>
                    <div class="md-empty"><i class="fas fa-file-invoice-dollar"></i>No payments recorded yet. Make your first payment above.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table md-table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Description</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td class="text-nowrap"><?php echo e(date('d M Y, H:i', strtotime($payment['payment_date']))); ?></td>
                                        <td><span class="md-pill md-pill-green">KES <?php echo number_format((float) $payment['amount'], 2); ?></span></td>
                                        <td><span class="md-pill md-pill-blue"><?php echo e($payment['payment_method']); ?></span></td>
                                        <td><?php echo e($payment['description']); ?></td>
                                        <td class="text-end">
                                            <a href="<?php echo e(app_url('public/payment_receipt.php?id=' . $payment['payment_id'])); ?>"
                                               target="_blank"
                                               class="btn btn-sm btn-outline-secondary"
                                               title="Download Receipt"
                                               style="font-size:.75rem;padding:4px 10px;">
                                                🧾 Receipt
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <?php include __DIR__ . '/../includes/member_quick_actions.php'; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var planSelect = document.getElementById('plan_id');
    if (planSelect) {
        planSelect.addEventListener('change', function () {
            var option = this.options[this.selectedIndex];
            var price = option.getAttribute('data-price');
            var name = option.getAttribute('data-name');
            if (price) {
                document.getElementById('amount').value = price;
                document.getElementById('description').value = 'Membership: ' + name;
                document.getElementById('payment_method').value = 'Paystack';
            }
        });
    }
});
</script>

<?php include '../includes/footer.php'; ?>