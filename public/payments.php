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

<!-- High-End Corporate Minimalist Design Token Layer -->
<style>
    body { 
        background-color: #f8fafc !important; 
        color: #334155 !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    
    .page-header-corporate {
        border-bottom: 1px solid #e2e8f0;
        margin-bottom: 2.5rem;
        padding-bottom: 1.25rem;
    }

    .corporate-title {
        color: #0f172a;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .brand-accent-line {
        width: 40px;
        height: 4px;
        background-color: #2563eb;
        border-radius: 2px;
        margin-bottom: 1rem;
    }

    .corporate-block-wrapper {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2.5rem;
        padding: 2rem;
    }

    .corporate-block-wrapper-table {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2.5rem;
    }

    .block-header-bar {
        background-color: #f8fafc;
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .section-title-text {
        color: #0f172a;
        font-weight: 700;
        font-size: 1.1rem;
        margin-bottom: 0;
    }

    .form-label-corporate {
        font-size: 0.825rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.5rem;
    }

    .form-select-corporate, .form-control-corporate {
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 0.6rem 0.75rem;
        font-size: 0.925rem;
        color: #1e293b;
        background-color: #ffffff;
        transition: border-color 0.15s ease;
    }

    .form-select-corporate:focus, .form-control-corporate:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 1px #2563eb;
        outline: 0;
    }

    .table-corporate {
        margin-bottom: 0;
    }

    .table-corporate thead th {
        background-color: #ffffff;
        color: #64748b;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .table-corporate tbody td {
        padding: 1rem 1.5rem;
        vertical-align: middle;
        color: #334155;
        font-size: 0.925rem;
        border-bottom: 1px solid #f1f5f9;
    }

    .table-corporate tbody tr:last-child td {
        border-bottom: none;
    }

    .table-corporate tbody tr {
        transition: background-color 0.15s ease-in-out;
    }

    .table-corporate tbody tr:hover {
        background-color: #f8fafc;
    }

    .text-primary-dark {
        color: #0f172a;
        font-weight: 600;
    }

    .currency-badge {
        font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-weight: 600;
        color: #16a34a;
        background-color: #f0fdf4;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.85rem;
    }

    .method-tag {
        font-size: 0.825rem;
        font-weight: 500;
        color: #475569;
        background-color: #f1f5f9;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
    }

    .btn-corporate {
        font-size: 0.875rem;
        font-weight: 600;
        padding: 0.6rem 1.5rem;
        border-radius: 6px;
        transition: all 0.15s ease;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    .btn-corporate-primary {
        background-color: #2563eb;
        color: #ffffff !important;
    }

    .btn-corporate-primary:hover {
        background-color: #1d4ed8;
    }

    .corporate-empty-box {
        padding: 3rem 2rem;
        color: #64748b;
    }
</style>

<div class="container py-5">
    
    <!-- Corporate Header Module -->
    <div class="row page-header-corporate">
        <div class="col-12">
            <div class="brand-accent-line"></div>
            <h1 class="corporate-title mb-2">Member Payments</h1>
            <p class="text-muted mb-0">Initialize premium portal feature subscriptions, access custom payment parameters, and monitor chronological statements.</p>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-12">
            
            <!-- Output Alert Messaging Matrix -->
            <?php if (!empty($message)): ?>
                <div class="mb-4">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <!-- Interactive Payment Process Card -->
            <div class="corporate-block-wrapper">
                <form method="post" class="row g-3">
                    <?php echo csrf_field('csrf_token'); ?>
                    
                    <?php if ($plans_ready): ?>
                        <div class="col-12 mb-2">
                            <label for="plan_id" class="form-label-corporate">Target Membership Program Structure</label>
                            <select name="plan_id" id="plan_id" class="form-select form-select-corporate w-100">
                                <option value="0">No plan &mdash; Custom Financial Allocation</option>
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
                        <label for="amount" class="form-label-corporate">Financial Amount (KES)</label>
                        <input type="number" min="1" step="0.01" name="amount" id="amount" class="form-control form-control-corporate w-100" value="<?php echo e($amount); ?>" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label for="payment_method" class="form-label-corporate">Gateway Settlement Channel</label>
                        <select name="payment_method" id="payment_method" class="form-select form-select-corporate w-100">
                            <option value="Paystack" <?php echo $payment_method === 'Paystack' ? 'selected' : ''; ?>>Paystack </option>
                            <option value="M-Pesa" <?php echo $payment_method === 'M-Pesa' ? 'selected' : ''; ?>>Safaricom M-Pesa STK Push</option>
                        </select>
                    </div>

                    <div class="col-md-8">
                        <label for="description" class="form-label-corporate">Allocation Description / Purpose</label>
                        <input type="text" name="description" id="description" class="form-control form-control-corporate w-100" value="<?php echo e($description); ?>" placeholder="e.g. Account subscription renewal allocation">
                    </div>
                    
                    <div class="col-md-4">
                        <label for="promo_code" class="form-label-corporate">Corporate Promo Code <span class="text-muted font-weight-normal">(Optional)</span></label>
                        <input type="text" name="promo_code" id="promo_code" class="form-control form-control-corporate text-uppercase w-100" placeholder="SUMMER2026" maxlength="40">
                    </div>

                    <div class="col-12 pt-3">
                        <button type="submit" class="btn-corporate btn-corporate-primary shadow-sm">
                            Proceed to Payment
                        </button>
                    </div>
                </form>
            </div>

            <!-- Historical Audit Timeline Display Layer -->
            <div class="corporate-block-wrapper-table">
                <div class="block-header-bar">
                    <h3 class="section-title-text">Recent Ledger Settlements</h3>
                </div>

                <?php if (empty($payments)): ?>
                    <div class="text-center corporate-empty-box">
                        <p class="mb-0 text-muted">No historical statements mapped to this member identity token.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-corporate table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 20%;">Execution Date</th>
                                    <th style="width: 20%;">Quantum Settled</th>
                                    <th style="width: 15%;">Channel</th>
                                    <th style="width: 45%;">Allocation Log</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td>
                                            <span style="color: #475569; font-weight: 500;">
                                                <?php echo e(date('d M Y, H:i', strtotime($payment['payment_date']))); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="currency-badge">
                                                KES <?php echo number_format((float) $payment['amount'], 2); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="method-tag">
                                                <?php echo e($payment['payment_method']); ?>
                                            </span>
                                        </td>
                                        <td class="text-primary-dark">
                                            <?php echo e($payment['description']); ?>
                                        </td>
                                        <td>
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