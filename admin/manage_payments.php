<?php
// ============================================================
//  admin/manage_payments.php
// ============================================================
require_once "../includes/admin_auth.php";
require_once "../config/db_connect.php";
require_once "../config/api_config.php";
require_once "../includes/paystack.php";
require_once "../includes/mpesa.php";
require_once "../includes/send_email.php";
require_once "../includes/csrf.php";

$message       = "";
$member_id     = $amount = $payment_method = $description = "";
$member_id_err = $amount_err = $payment_method_err = "";
$emailSent     = false;

if (!empty($_SESSION['payment_flash'])) {
    $message = $_SESSION['payment_flash'];
    unset($_SESSION['payment_flash']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'payment_csrf')) {
        die("Security token expired.");
    }

    if (empty(trim($_POST["member_id"]))) { $member_id_err = "Please select a member."; } else { $member_id = trim($_POST["member_id"]); }
    if (empty(trim($_POST["amount"]))) { $amount_err = "Please enter an amount."; } elseif (!is_numeric(trim($_POST["amount"])) || trim($_POST["amount"]) <= 0) { $amount_err = "Enter a valid amount."; } else { $amount = trim($_POST["amount"]); }
    
    $allowed_methods = ['Cash', 'Credit Card', 'Bank Transfer', 'M-Pesa', 'Paystack'];
    if (empty(trim($_POST["payment_method"]))) { $payment_method_err = "Select a method."; } elseif (!in_array(trim($_POST["payment_method"]), $allowed_methods)) { $payment_method_err = "Invalid method."; } else { $payment_method = trim($_POST["payment_method"]); }

    $member_email = $member_fname = $member_lname = $member_phone = "";
    if (empty($member_id_err)) {
        $sql_member = "SELECT email, first_name, last_name, phone_number FROM members WHERE member_id = ?";
        if ($stmt_m = $conn->prepare($sql_member)) {
            $stmt_m->bind_param("i", $member_id);
            $stmt_m->execute();
            $stmt_m->bind_result($member_email, $member_fname, $member_lname, $member_phone);
            $stmt_m->fetch();
            $stmt_m->close();
        }
    }

    $description = trim($_POST["description"] ?? '');

    if (empty($member_id_err) && empty($amount_err) && empty($payment_method_err)) {
        $payment_success = true;
        $record_payment  = true;

        if ($payment_method === "Paystack") {
            $record_payment = false;
            $paystackResult = paystackInitTransaction($member_email, $amount, PAYSTACK_CALLBACK_URL, ['member_id' => $member_id, 'description' => $description ?: 'Club Payment']);
            if (!empty($paystackResult['status']) && $paystackResult['status'] === true && !empty($paystackResult['data']['authorization_url'])) {
                header('Location: ' . $paystackResult['data']['authorization_url']);
                exit;
            } else {
                $payment_success = false;
                $message = "<div class='alert alert-danger shadow-sm border-0 d-flex align-items-center'><i class='fas fa-exclamation-circle me-2'></i> Paystack failed: " . htmlspecialchars($paystackResult['message'] ?? 'Error') . "</div>";
            }
        }

        if ($payment_method === "M-Pesa") {
            $record_payment = false;
            require_once '../includes/mpesa_helpers.php';
            $mpesaResult = mpesaSTKPush(formatMpesaPhone($member_phone), $amount, $description ?: 'Club Payment');
            if (($mpesaResult['ResponseCode'] ?? '') === '0') {
                $checkoutId = trim((string) ($mpesaResult['CheckoutRequestID'] ?? ''));
                if ($checkoutId !== '') { mpesa_create_pending($conn, $checkoutId, (float)$amount, $description ?: 'Club Payment', 'admin', (int)$member_id); }
                $_SESSION['payment_flash'] = "<div class='alert alert-success shadow-sm border-0 d-flex align-items-center'><i class='fas fa-mobile-alt me-2'></i> STK Push Sent to " . htmlspecialchars($member_phone) . "! Waiting for PIN authentication...</div>";
                header('Location: manage_payments.php');
                exit;
            } else {
                $payment_success = false;
                $message = "<div class='alert alert-danger shadow-sm border-0 d-flex align-items-center'><i class='fas fa-exclamation-circle me-2'></i> M-Pesa error: " . htmlspecialchars($mpesaResult['ResponseDescription'] ?? 'Failed') . "</div>";
            }
        }

        if ($payment_success && $record_payment) {
            $sql = "INSERT INTO payments (member_id, amount, payment_method, description) VALUES (?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("idss", $member_id, $amount, $payment_method, $description);
                if ($stmt->execute()) {
                    if ($member_email) { sendEmail($member_email, $member_fname . " " . $member_lname, "🧾 Payment Receipt", emailPaymentReceipt($member_fname, $amount, $payment_method, $description)); }
                    $_SESSION['payment_flash'] = "<div class='alert alert-success shadow-sm border-0 d-flex align-items-center'><i class='fas fa-check-circle me-2'></i> Payment of KES " . number_format($amount, 2) . " recorded, receipt emailed!</div>";
                    $stmt->close();
                    header('Location: manage_payments.php');
                    exit;
                }
                $stmt->close();
            }
        }
    }
}

$payments = [];
$sql = "SELECT p.payment_id, m.first_name, m.last_name, m.phone_number, p.amount, p.payment_date, p.payment_method, p.description FROM payments p LEFT JOIN members m ON p.member_id = m.member_id ORDER BY p.payment_date DESC";
if ($result = $conn->query($sql)) { while ($row = $result->fetch_assoc()) $payments[] = $row; $result->free(); }

$members = [];
$sql_members = "SELECT member_id, first_name, last_name, phone_number FROM members ORDER BY first_name";
if ($result_members = $conn->query($sql_members)) { while ($row = $result_members->fetch_assoc()) $members[] = $row; $result_members->free(); }

$total_all      = array_sum(array_column($payments, 'amount'));
$total_paystack = array_sum(array_map(fn($p) => $p['payment_method'] === 'Paystack' ? $p['amount'] : 0, $payments));
$total_mpesa    = array_sum(array_map(fn($p) => $p['payment_method'] === 'M-Pesa'   ? $p['amount'] : 0, $payments));
$total_cash     = array_sum(array_map(fn($p) => $p['payment_method'] === 'Cash'     ? $p['amount'] : 0, $payments));

include_once "../includes/admin_header.php";
$conn->close();
?>

<style>
    body { background-color: #f8fafc; color: #1e293b; font-family: 'Inter', system-ui, -apple-system, sans-serif; }
    .page-title { font-size: 1.75rem; font-weight: 700; color: #0f172a; letter-spacing: -0.02em; }
    
    /* Elegant Metric Cards */
    .metric-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; }
    .metric-card:hover { transform: translateY(-3px); box-shadow: 0 12px 20px -5px rgba(0, 0, 0, 0.05); border-color: #cbd5e1; }
    .icon-shape { width: 48px; height: 48px; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-size: 1.25rem; }
    
    /* Custom Color Accents */
    .accent-total { background-color: #f1f5f9; color: #334155; }
    .accent-mpesa { background-color: #ecfdf5; color: #059669; }
    .accent-paystack { background-color: #e0e7ff; color: #4f46e5; }
    .accent-cash { background-color: #fff7ed; color: #ea580c; }

    /* Modern Card Layouts */
    .card-modern { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.02); overflow: hidden; }
    .card-modern .card-header { background: #ffffff; border-bottom: 1px solid #f1f5f9; padding: 1.25rem 1.5rem; font-weight: 600; color: #0f172a; }
    
    /* Styled Form Inputs */
    .form-label-custom { font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 0.5rem; }
    .form-control-modern, .form-select-modern { background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.65rem 1rem; font-size: 0.95rem; color: #1e293b; transition: all 0.2s; }
    .form-control-modern:focus, .form-select-modern:focus { background-color: #fff; border-color: #6366f1; box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); outline: none; }
    
    /* Buttons */
    .btn-indigo-premium { background: linear-gradient(135deg, #4f46e5 0%, #6366f1 100%); color: #fff; border: none; border-radius: 10px; padding: 0.7rem 1.5rem; font-weight: 500; transition: all 0.2s; }
    .btn-indigo-premium:hover { background: linear-gradient(135deg, #4338ca 0%, #4f46e5 100%); color: #fff; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25); }
    
    /* Table Enhancements */
    .table-modern thead th { background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #64748b; font-weight: 600; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 1rem 1.5rem; }
    .table-modern tbody td { padding: 1.1rem 1.5rem; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 0.95rem; }
    
    /* Badges */
    .badge-premium { padding: 0.45em 0.75em; font-size: 0.8rem; font-weight: 600; border-radius: 8px; border: 1px solid transparent; display: inline-flex; align-items: center; gap: 0.35rem; }
    .badge-mpesa { background-color: #e6fbf1; color: #065f46; border-color: #a7f3d0; }
    .badge-paystack { background-color: #edf2ff; color: #3730a3; border-color: #c7d2fe; }
    .badge-cash { background-color: #f8fafc; color: #334155; border-color: #e2e8f0; }
    
    .search-wrapper { position: relative; max-width: 320px; width: 100%; }
    .search-icon { position: absolute; left: 1rem; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 0.9rem; }
    .search-input-premium { padding-left: 2.5rem !important; border-radius: 20px !important; }
</style>

<div class="container-fluid py-4 px-md-4">
    <div class="d-flex align-items-center justify-content-between mb-4">
        <h2 class="page-title mb-0">Finance Ledger & Payments</h2>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card metric-card">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="icon-shape accent-total me-3"><i class="fas fa-vault"></i></div>
                    <div>
                        <span class="text-muted small d-block fw-medium mb-1">Total Collected</span>
                        <h4 class="mb-0 fw-bold tracking-tight">KES <?php echo number_format($total_all, 0); ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card metric-card">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="icon-shape accent-mpesa me-3"><i class="fas fa-mobile-alt"></i></div>
                    <div>
                        <span class="text-muted small d-block fw-medium mb-1">M-Pesa Revenue</span>
                        <h4 class="mb-0 fw-bold text-success">KES <?php echo number_format($total_mpesa, 0); ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card metric-card">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="icon-shape accent-paystack me-3"><i class="fas fa-credit-card"></i></div>
                    <div>
                        <span class="text-muted small d-block fw-medium mb-1">Paystack Checkout</span>
                        <h4 class="mb-0 fw-bold text-indigo">KES <?php echo number_format($total_paystack, 0); ?></h4>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card metric-card">
                <div class="card-body d-flex align-items-center p-3">
                    <div class="icon-shape accent-cash me-3"><i class="fas fa-money-bill-wave"></i></div>
                    <div>
                        <span class="text-muted small d-block fw-medium mb-1">Physical Cash</span>
                        <h4 class="mb-0 fw-bold text-warning">KES <?php echo number_format($total_cash, 0); ?></h4>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if(!empty($message)) echo "<div class='mb-4'>$message</div>"; ?>

    <div class="card card-modern mb-4">
        <div class="card-header"><i class="fas fa-plus me-2 text-indigo"></i>Record New System Entry</div>
        <div class="card-body p-4">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="paymentForm">
                <?php echo csrf_field('payment_csrf'); ?>
                
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="member_id" class="form-label-custom">Target Club Member <span class="text-danger">*</span></label>
                        <select name="member_id" id="member_id" class="form-select form-select-modern <?php echo (!empty($member_id_err)) ? 'is-invalid' : ''; ?>" required>
                            <option value="">Select Member</option>
                            <?php foreach ($members as $m): ?>
                                <option value="<?php echo $m['member_id']; ?>" <?php echo ($member_id == $m['member_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?>
                                    <?php echo $m['phone_number'] ? ' (' . htmlspecialchars($m['phone_number']) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="invalid-feedback"><?php echo $member_id_err; ?></div>
                    </div>

                    <div class="col-md-4">
                        <label for="amount" class="form-label-custom">Amount (KES) <span class="text-danger">*</span></label>
                        <input type="number" name="amount" id="amount" step="0.01" min="1" class="form-control form-control-modern <?php echo (!empty($amount_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($amount); ?>" placeholder="Enter amount" required>
                        <div class="invalid-feedback"><?php echo $amount_err; ?></div>
                    </div>

                    <div class="col-md-4">
                        <label for="payment_method" class="form-label-custom">Payment Channel Gateway <span class="text-danger">*</span></label>
                        <select name="payment_method" id="payment_method" class="form-select form-select-modern <?php echo (!empty($payment_method_err)) ? 'is-invalid' : ''; ?>" required>
                            <option value="">Select Method</option>
                            <option value="M-Pesa" <?php echo ($payment_method === 'M-Pesa') ? 'selected' : ''; ?>>📱 M-Pesa Daraja Push</option>
                            <option value="Paystack" <?php echo ($payment_method === 'Paystack') ? 'selected' : ''; ?>>🅿️ Paystack Checkout</option>
                            <option value="Cash" <?php echo ($payment_method === 'Cash') ? 'selected' : ''; ?>>💵 Cash Handover</option>
                            <option value="Credit Card" <?php echo ($payment_method === 'Credit Card') ? 'selected' : ''; ?>>💳 External Card System</option>
                            <option value="Bank Transfer" <?php echo ($payment_method === 'Bank Transfer') ? 'selected' : ''; ?>>🏦 Direct Bank Wire</option>
                        </select>
                        <div class="invalid-feedback"><?php echo $payment_method_err; ?></div>
                    </div>

                    <div class="col-12">
                        <label for="description" class="form-label-custom">Transaction Description Memo</label>
                        <input type="text" name="description" id="description" class="form-control form-control-modern" value="<?php echo htmlspecialchars($description); ?>" placeholder="e.g. Gor Mahia registration fee, monthly gym pass subscription">
                    </div>

                    <div class="col-12" id="bannerContainer" style="display: none;">
                        <div id="paystackBanner" class="alert alert-info border-0 shadow-sm py-2 mb-0 align-items-center gap-2" style="display: none;">
                            <i class="fas fa-external-link-alt text-indigo"></i>
                            <span><strong>Paystack Checkout Redirection:</strong> Initiating standard checkout terminal page via API secure window.</span>
                        </div>
                        <div id="mpesaBanner" class="alert alert-success border-0 shadow-sm py-2 mb-0 align-items-center gap-2" style="display: none;">
                            <i class="fas fa-paper-plane text-success"></i>
                            <span><strong>Safaricom Daraja Push:</strong> Triggering an instant M-Pesa STK PIN entry wrapper directly onto the target device.</span>
                        </div>
                    </div>
                </div>

                <div class="mt-4 d-flex justify-content-end gap-2">
                    <button type="reset" class="btn btn-light px-4" style="border-radius:10px;">Clear Form</button>
                    <button type="submit" class="btn btn-indigo-premium" id="submitBtn">
                        <i class="fas fa-receipt me-2"></i><span id="submitText">Record Entry</span>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card card-modern shadow-sm">
        <div class="card-header d-flex flex-column flex-sm-row justify-content-between align-items-sm-center gap-3">
            <div class="fw-semibold text-slate-800">Club Transaction Ledger Logs</div>
            <div class="search-wrapper">
                <i class="fas fa-search search-icon"></i>
                <input type="text" id="searchPayments" class="form-control form-control-modern search-input-premium" placeholder="Filter ledger reference...">
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-modern table-hover align-middle mb-0" id="paymentsTable">
                    <thead>
                        <tr>
                            <th>Ref ID</th>
                            <th>Club Member</th>
                            <th>Amount</th>
                            <th>Channel Gateway</th>
                            <th>Context Memo</th>
                            <th>Execution Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($payments) > 0): ?>
                            <?php foreach ($payments as $payment): ?>
                                <tr>
                                    <td class="text-muted font-monospace small fw-bold">#<?php echo str_pad($payment['payment_id'], 5, '0', STR_PAD_LEFT); ?></td>
                                    <td>
                                        <div class="fw-semibold text-slate-900"><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></div>
                                        <?php if ($payment['phone_number']): ?>
                                            <span class="text-muted small"><i class="fas fa-phone-alt fa-xs me-1"></i><?php echo htmlspecialchars($payment['phone_number']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="fw-bold text-slate-900">KES <?php echo number_format($payment['amount'], 2); ?></span></td>
                                    <td>
                                        <?php if ($payment['payment_method'] === 'Paystack'): ?>
                                            <span class="badge-premium badge-paystack"><i class="fab fa-cc-visa fa-xs"></i>Paystack</span>
                                        <?php elseif ($payment['payment_method'] === 'M-Pesa'): ?>
                                            <span class="badge-premium badge-mpesa"><i class="fas fa-mobile-alt fa-xs"></i>M-Pesa</span>
                                        <?php else: ?>
                                            <span class="badge-premium badge-cash"><i class="fas fa-coins fa-xs"></i><?php echo htmlspecialchars($payment['payment_method']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-secondary small"><?php echo htmlspecialchars($payment['description'] ?: '—'); ?></td>
                                    <td class="text-muted small"><?php echo date('M d, Y • h:i A', strtotime($payment['payment_date'])); ?></td>
                                    <td>
                                        <a href="../public/payment_receipt.php?id=<?php echo (int)$payment['payment_id']; ?>"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-secondary"
                                           title="View Receipt"
                                           style="font-size:.72rem;padding:3px 8px;">
                                            🧾
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="fas fa-receipt fa-2x d-block mb-2 text-slate-300"></i> No transactions logged yet.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
// Dynamic view banner layout toggles
const methodSelect   = document.getElementById('payment_method');
const bannerContainer = document.getElementById('bannerContainer');
const paystackBanner  = document.getElementById('paystackBanner');
const mpesaBanner     = document.getElementById('mpesaBanner');
const submitText      = document.getElementById('submitText');

methodSelect.addEventListener('change', function () {
    const isPaystack = this.value === 'Paystack';
    const isMpesa    = this.value === 'M-Pesa';
    
    bannerContainer.style.display = (isPaystack || isMpesa) ? 'block' : 'none';
    paystackBanner.style.display  = isPaystack ? 'flex' : 'none';
    mpesaBanner.style.display     = isMpesa ? 'flex' : 'none';

    if (isPaystack) {
        submitText.textContent = 'Proceed to Checkout';
    } else if (isMpesa) {
        submitText.textContent = 'Send STK Push Request';
    } else {
        submitText.textContent = 'Record Handover Entry';
    }
});

// Premium real-time search filtering loop
document.getElementById('searchPayments').addEventListener('keyup', function () {
    const query = this.value.toLowerCase();
    const rows  = document.querySelectorAll('#paymentsTable tbody tr');
    rows.forEach(function (row) {
        if(row.querySelector('td[colspan]')) return;
        row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
});
</script>

<?php include_once("../includes/footer.php"); ?>