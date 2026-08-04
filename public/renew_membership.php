<?php
// ============================================================
//  public/renew_membership.php
//  Online membership renewal — M-Pesa STK Push or Paystack
// ============================================================
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
asc_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('location: login.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

$member_id = (int) $_SESSION['member_id'];
$success = $error = $info = '';

// Fetch member
$stmt = $conn->prepare("SELECT first_name, last_name, email, phone_number FROM members WHERE member_id = ? LIMIT 1");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch plans
$plans = [];
if ($result = $conn->query("SELECT plan_id, name, price, duration_days, description FROM membership_plans WHERE status='Active' ORDER BY price")) {
    while ($row = $result->fetch_assoc()) $plans[] = $row;
}

// Current membership
$active = get_active_membership($conn, $member_id);

// ── Handle Paystack init ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_paystack'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'renew_csrf')) {
        $error = 'Security check failed. Please refresh.';
    } else {
        $plan_id = (int) ($_POST['plan_id'] ?? 0);
        // Validate plan
        $pstmt = $conn->prepare("SELECT plan_id, name, price FROM membership_plans WHERE plan_id=? AND status='Active' LIMIT 1");
        $pstmt->bind_param('i', $plan_id);
        $pstmt->execute();
        $plan = $pstmt->get_result()->fetch_assoc();
        $pstmt->close();

        if (!$plan) {
            $error = 'Invalid plan selected.';
        } else {
            $amount_kobo = (int) ($plan['price'] * 100); // Paystack uses smallest unit (kobo/pesewas); for KES multiply by 100
            $callback    = defined('PAYSTACK_CALLBACK_URL') ? PAYSTACK_CALLBACK_URL : 'http://localhost/Apex%20Sports%20Club/callbacks/paystack_callback.php';
            $ref         = 'RENEW_' . $member_id . '_' . $plan_id . '_' . time();

            $body = json_encode([
                'email'        => $member['email'],
                'amount'       => $amount_kobo,
                'reference'    => $ref,
                'callback_url' => $callback,
                'metadata'     => ['member_id' => $member_id, 'plan_id' => $plan_id, 'type' => 'membership_renewal'],
            ]);

            $ch = curl_init('https://api.paystack.co/transaction/initialize');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
                    'Content-Type: application/json',
                ],
            ]);
            $resp = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($resp, true);

            if (!empty($data['data']['authorization_url'])) {
                header('Location: ' . $data['data']['authorization_url']);
                exit;
            } else {
                $error = 'Could not initiate Paystack payment. Please try again.';
            }
        }
    }
}

// ── Handle M-Pesa STK Push ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pay_mpesa'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'renew_csrf')) {
        $error = 'Security check failed. Please refresh.';
    } elseif (!rate_limit_check('mpesa_push_m' . $member_id, 5, 600)) {
        // Each attempt fires an STK push (and an OAuth + push call to
        // Safaricom) — cap per member so a loop/bug can't spam pushes.
        // 5 per 10 min gives a typo'd-phone user room to retry.
        $error = 'Too many M-Pesa requests. Please wait 10 minutes before trying again.';
    } else {
        $plan_id = (int) ($_POST['plan_id'] ?? 0);
        $phone   = preg_replace('/\D/', '', trim($_POST['mpesa_phone'] ?? ''));
        // Normalise to 2547XXXXXXXX
        if (str_starts_with($phone, '0')) $phone = '254' . substr($phone, 1);
        if (str_starts_with($phone, '7') || str_starts_with($phone, '1')) $phone = '254' . $phone;

        $pstmt = $conn->prepare("SELECT plan_id, name, price FROM membership_plans WHERE plan_id=? AND status='Active' LIMIT 1");
        $pstmt->bind_param('i', $plan_id);
        $pstmt->execute();
        $plan = $pstmt->get_result()->fetch_assoc();
        $pstmt->close();

        if (!$plan || strlen($phone) !== 12 || !str_starts_with($phone, '254')) {
            $error = 'Invalid plan or phone number. Use format 0712345678.';
        } else {
            // Get M-Pesa access token
            $creds   = base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
            $env     = (MPESA_ENV === 'production') ? 'api' : 'sandbox';
            $tok_ch  = curl_init("https://{$env}.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials");
            curl_setopt_array($tok_ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $creds],
            ]);
            $tok_resp   = curl_exec($tok_ch);
            curl_close($tok_ch);
            $tok_data   = json_decode($tok_resp, true);
            $access_token = $tok_data['access_token'] ?? '';

            if (empty($access_token)) {
                $error = 'Could not connect to M-Pesa. Please try again.';
            } else {
                $timestamp  = date('YmdHis');
                $passkey    = MPESA_PASSKEY;
                $shortcode  = MPESA_SHORTCODE;
                $password   = base64_encode($shortcode . $passkey . $timestamp);
                $amount     = (int) ceil($plan['price']);
                $callback   = defined('MPESA_CALLBACK_URL') ? MPESA_CALLBACK_URL : '';

                $stk_body = [
                    'BusinessShortCode' => $shortcode,
                    'Password'          => $password,
                    'Timestamp'         => $timestamp,
                    'TransactionType'   => 'CustomerPayBillOnline',
                    'Amount'            => $amount,
                    'PartyA'            => $phone,
                    'PartyB'            => $shortcode,
                    'PhoneNumber'       => $phone,
                    'CallBackURL'       => $callback,
                    'AccountReference'  => 'APEX_MEM_' . $member_id,
                    'TransactionDesc'   => 'Membership renewal: ' . $plan['name'],
                ];

                $stk_ch = curl_init("https://{$env}.safaricom.co.ke/mpesa/stkpush/v1/processrequest");
                curl_setopt_array($stk_ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => json_encode($stk_body),
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Bearer ' . $access_token,
                        'Content-Type: application/json',
                    ],
                ]);
                $stk_resp = curl_exec($stk_ch);
                curl_close($stk_ch);
                $stk_data = json_decode($stk_resp, true);

                if (($stk_data['ResponseCode'] ?? '') === '0') {
                    $info = 'M-Pesa prompt sent to <strong>' . e($phone) . '</strong>. Enter your PIN on your phone to complete payment.';
                } else {
                    $error = 'M-Pesa request failed: ' . e($stk_data['ResponseDescription'] ?? 'Unknown error');
                }
            }
        }
    }
}

$conn->close();
include '../includes/header.php';
?>
<style>
    body { background: #f8fafc !important; }
    .rn-card {
        border: 1px solid #e2e8f0; border-radius: 14px;
        background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.05);
    }
    .plan-card {
        border: 2px solid #e2e8f0; border-radius: 12px;
        cursor: pointer; transition: border-color .15s, box-shadow .15s;
        padding: 1.2rem; background: #fff;
    }
    .plan-card:hover { border-color: #4f46e5; box-shadow: 0 0 0 4px rgba(79,70,229,.07); }
    .plan-card.selected { border-color: #4f46e5; background: #f5f3ff; }
    .plan-price { font-size: 1.4rem; font-weight: 700; color: #1e293b; }
    .plan-name  { font-weight: 600; color: #334155; }
    .plan-dur   { font-size: .78rem; color: #94a3b8; }
    .plan-radio { display: none; }
    .method-btn {
        border: 2px solid #e2e8f0; border-radius: 10px;
        padding: .6rem 1.2rem; cursor: pointer;
        transition: border-color .15s, background .15s;
        background: #fff; text-align: center; font-weight: 600;
    }
    .method-btn.active { border-color: #4f46e5; background: #f5f3ff; }
    .method-mpesa.active { border-color: #00a550; background: #f0fdf4; }
    .btn-pay {
        background: linear-gradient(135deg,#4f46e5,#6366f1);
        color: #fff; border: none; border-radius: 9px;
        padding: .65rem 2rem; font-weight: 600;
        transition: opacity .15s;
    }
    .btn-pay:hover { opacity: .87; color: #fff; }
    .btn-pay-mpesa {
        background: linear-gradient(135deg,#00a550,#00c060);
        color: #fff; border: none; border-radius: 9px;
        padding: .65rem 2rem; font-weight: 600;
    }
    .btn-pay-mpesa:hover { opacity: .87; color: #fff; }
    #mpesaPhoneRow { display: none; }
</style>

<div class="container py-4" style="max-width:680px;">
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="memberships.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <div>
            <h4 class="mb-0 fw-bold">Renew Membership</h4>
            <p class="text-muted small mb-0">Choose a plan and pay instantly</p>
        </div>
    </div>

    <?php if ($active): ?>
    <div class="alert alert-info border-0 rounded-3 mb-4" style="background:#eff6ff;">
        <i class="fas fa-info-circle me-2"></i>
        Your current plan <strong><?php echo e($active['plan_name']); ?></strong> expires on
        <strong><?php echo e(date('d M Y', strtotime($active['end_date']))); ?></strong>.
        Renewing now will extend from that date.
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success border-0 rounded-3 mb-4"><i class="fas fa-check-circle me-2"></i><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if ($info): ?>
        <div class="alert alert-info border-0 rounded-3 mb-4"><i class="fas fa-mobile-alt me-2"></i><?php echo $info; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger border-0 rounded-3 mb-4"><i class="fas fa-exclamation-circle me-2"></i><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if (empty($plans)): ?>
        <div class="rn-card p-4 text-center text-muted">No active membership plans available.</div>
    <?php else: ?>

    <div class="rn-card p-4 mb-4">
        <p class="text-uppercase fw-bold small text-secondary mb-3" style="letter-spacing:.07em;">
            <i class="fas fa-tags me-1"></i> Select a Plan
        </p>
        <div class="row g-3" id="planGrid">
            <?php foreach ($plans as $i => $pl): ?>
            <div class="col-md-6">
                <label class="plan-card d-block <?php echo $i === 0 ? 'selected' : ''; ?>" for="plan_<?php echo (int)$pl['plan_id']; ?>">
                    <input type="radio" class="plan-radio" name="selected_plan" id="plan_<?php echo (int)$pl['plan_id']; ?>"
                           value="<?php echo (int)$pl['plan_id']; ?>"
                           data-price="<?php echo e($pl['price']); ?>"
                           data-name="<?php echo e($pl['name']); ?>"
                           <?php echo $i === 0 ? 'checked' : ''; ?>>
                    <div class="plan-name mb-1"><?php echo e($pl['name']); ?></div>
                    <div class="plan-price">KES <?php echo number_format($pl['price'], 2); ?></div>
                    <div class="plan-dur"><?php echo (int)$pl['duration_days']; ?> days
                        <?php if (!empty($pl['description'])): ?>
                            · <?php echo e(mb_strimwidth($pl['description'], 0, 50, '…')); ?>
                        <?php endif; ?>
                    </div>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Payment method -->
    <div class="rn-card p-4 mb-4">
        <p class="text-uppercase fw-bold small text-secondary mb-3" style="letter-spacing:.07em;">
            <i class="fas fa-credit-card me-1"></i> Payment Method
        </p>
        <div class="d-flex gap-3 mb-3 flex-wrap">
            <div class="method-btn active flex-fill" id="btnPaystack" onclick="selectMethod('paystack')">
                <i class="fas fa-credit-card me-1 text-primary"></i> Card / Paystack
            </div>
            <div class="method-btn method-mpesa flex-fill" id="btnMpesa" onclick="selectMethod('mpesa')">
                <i class="fas fa-mobile-alt me-1 text-success"></i> M-Pesa
            </div>
        </div>

        <!-- Paystack form -->
        <form method="POST" id="formPaystack">
            <?php echo csrf_field('renew_csrf'); ?>
            <input type="hidden" name="plan_id" id="ps_plan_id" value="<?php echo (int)$plans[0]['plan_id']; ?>">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <span class="text-muted small">Amount due:</span>
                <span class="fw-bold fs-5" id="psAmount">KES <?php echo number_format($plans[0]['price'], 2); ?></span>
            </div>
            <button type="submit" name="pay_paystack" class="btn-pay btn w-100">
                <i class="fas fa-lock me-1"></i> Pay with Paystack
            </button>
        </form>

        <!-- Mpesa form -->
        <form method="POST" id="formMpesa" style="display:none;">
            <?php echo csrf_field('renew_csrf'); ?>
            <input type="hidden" name="plan_id" id="mp_plan_id" value="<?php echo (int)$plans[0]['plan_id']; ?>">
            <div class="mb-3">
                <label class="form-label fw-600 small">M-Pesa Phone Number</label>
                <input type="tel" name="mpesa_phone" class="form-control"
                       placeholder="0712 345 678"
                       value="<?php echo e(preg_replace('/\D/', '', $member['phone_number'] ?? '')); ?>">
                <div class="form-text">We'll send a payment prompt to this number.</div>
            </div>
            <div class="d-flex align-items-center justify-content-between mb-3">
                <span class="text-muted small">Amount due:</span>
                <span class="fw-bold fs-5" id="mpAmount">KES <?php echo number_format($plans[0]['price'], 2); ?></span>
            </div>
            <button type="submit" name="pay_mpesa" class="btn-pay-mpesa btn w-100">
                <i class="fas fa-mobile-alt me-1"></i> Send M-Pesa Prompt
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
let currentMethod = 'paystack';

// Plan selection
document.querySelectorAll('input[name="selected_plan"]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.plan-card').forEach(c => c.classList.remove('selected'));
        radio.closest('.plan-card').classList.add('selected');
        const price = parseFloat(radio.dataset.price).toFixed(2);
        const formatted = 'KES ' + Number(price).toLocaleString('en-KE', {minimumFractionDigits:2});
        document.getElementById('psAmount').textContent = formatted;
        document.getElementById('mpAmount').textContent = formatted;
        document.getElementById('ps_plan_id').value = radio.value;
        document.getElementById('mp_plan_id').value = radio.value;
    });
});

function selectMethod(method) {
    currentMethod = method;
    document.getElementById('btnPaystack').classList.toggle('active', method === 'paystack');
    document.getElementById('btnMpesa').classList.toggle('active', method === 'mpesa');
    document.getElementById('btnMpesa').classList.toggle('method-mpesa', true);
    document.getElementById('formPaystack').style.display = method === 'paystack' ? '' : 'none';
    document.getElementById('formMpesa').style.display    = method === 'mpesa'    ? '' : 'none';
}
</script>

<?php include '../includes/footer.php'; ?>
