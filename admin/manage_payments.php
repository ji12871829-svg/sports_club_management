<?php
// ============================================================
//  admin/manage_payments.php
//  APIs Added:
//    Paystack API     - checkout payments
//    Brevo Email API  - payment receipt email to member
// ============================================================
require_once "../config/db_connect.php";
require_once "../config/api_config.php";

require_once "../includes/paystack.php";
require_once "../includes/send_email.php";

$message       = "";
$member_id     = $amount = $payment_method = $description = "";
$member_id_err = $amount_err = $payment_method_err = "";
$emailSent     = false;

// ── Handle Add Payment ────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate member
    if (empty(trim($_POST["member_id"]))) {
        $member_id_err = "Please select a member.";
    } else {
        $member_id = trim($_POST["member_id"]);
    }

    // Validate amount
    if (empty(trim($_POST["amount"]))) {
        $amount_err = "Please enter an amount.";
    } elseif (!is_numeric(trim($_POST["amount"])) || trim($_POST["amount"]) <= 0) {
        $amount_err = "Please enter a valid amount.";
    } else {
        $amount = trim($_POST["amount"]);
    }

    // Validate payment method
    if (empty(trim($_POST["payment_method"]))) {
        $payment_method_err = "Please select a payment method.";
    } else {
        $payment_method = trim($_POST["payment_method"]);
    }



    $member_email = $member_fname = $member_lname = "";
    if (empty($member_id_err)) {
        $sql_member = "SELECT email, first_name, last_name FROM members WHERE member_id = ?";
        if ($stmt_m = $conn->prepare($sql_member)) {
            $stmt_m->bind_param("i", $member_id);
            $stmt_m->execute();
            $stmt_m->bind_result($member_email, $member_fname, $member_lname);
            $stmt_m->fetch();
            $stmt_m->close();
        }

        if ($payment_method === "Paystack" && empty($member_email)) {
            $payment_method_err = "Please select a member with a valid email address for Paystack.";
        }
    }

    $description = trim($_POST["description"]);

    // ── Process Payment ───────────────────────────────────────
    if (empty($member_id_err) && empty($amount_err) && empty($payment_method_err)) {

        $payment_success = true;

        // ── Paystack ──────────────────────────────────────────
        if ($payment_method === "Paystack") {
            $paystackResult = paystackInitTransaction(
                $member_email,
                $amount,
                PAYSTACK_CALLBACK_URL,
                [
                    'member_id'   => $member_id,
                    'description' => $description ?: 'Sports Club Payment'
                ]
            );

            if (!empty($paystackResult['status']) && $paystackResult['status'] === true && !empty($paystackResult['data']['authorization_url'])) {
                header('Location: ' . $paystackResult['data']['authorization_url']);
                exit;
            } else {
                $payment_success = false;
                $errorMsg = $paystackResult['message'] ?? 'Unable to initialize Paystack payment.';
                $message = "
                <div class='alert alert-danger'>
                    <i class='fas fa-times-circle me-2'></i>
                    <strong>Paystack Failed:</strong> {$errorMsg}
                </div>";
            }
        }

        // ── Save Payment to Database ──────────────────────────
        if ($payment_success) {
            $sql = "INSERT INTO payments (member_id, amount, payment_method, description) VALUES (?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("idss", $member_id, $amount, $payment_method, $description);
                if ($stmt->execute()) {

                    // ── Fetch member details for email ────────
                    $member_email = $member_fname = $member_lname = "";
                    $sql_member = "SELECT email, first_name, last_name FROM members WHERE member_id = ?";
                    if ($stmt_m = $conn->prepare($sql_member)) {
                        $stmt_m->bind_param("i", $member_id);
                        $stmt_m->execute();
                        $stmt_m->bind_result($member_email, $member_fname, $member_lname);
                        $stmt_m->fetch();
                        $stmt_m->close();
                    }

                    // ── Send Payment Receipt Email ─────────────
                    if ($member_email) {
                        $emailSent = sendEmail(
                            $member_email,
                            $member_fname . " " . $member_lname,
                            "🧾 Payment Receipt — Sports Club",
                            emailPaymentReceipt($member_fname, $amount, $payment_method, $description)
                        );
                    }

                    $message .= "
                    <div class='alert alert-success'>
                        <i class='fas fa-check-circle me-2'></i>
                        <strong>Payment recorded successfully!</strong>
                        " . ($member_email && $emailSent ? "<br><small>📧 Receipt sent to <strong>{$member_email}</strong></small>" : "") . "
                    </div>";

                    // Clear form
                    $member_id = $amount = $payment_method = $description = "";

                } else {
                    $message .= "
                    <div class='alert alert-danger'>
                        <i class='fas fa-times-circle me-2'></i>
                        Error saving payment: " . $stmt->error . "
                    </div>";
                }
                $stmt->close();
            }
        }
    }
}

// ── Fetch all payments ────────────────────────────────────────
$payments = [];
$sql = "SELECT p.payment_id, m.first_name, m.last_name, m.phone_number,
               p.amount, p.payment_date, p.payment_method, p.description
        FROM payments p
        LEFT JOIN members m ON p.member_id = m.member_id
        ORDER BY p.payment_date DESC";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) $payments[] = $row;
    $result->free();
}

// ── Fetch members for dropdown ────────────────────────────────
$members = [];
$sql_members = "SELECT member_id, first_name, last_name, phone_number FROM members ORDER BY first_name";
if ($result_members = $conn->query($sql_members)) {
    while ($row = $result_members->fetch_assoc()) $members[] = $row;
    $result_members->free();
}

// ── Payment totals ────────────────────────────────────────────
$total_all      = array_sum(array_column($payments, 'amount'));
$total_paystack = array_sum(array_map(fn($p) => $p['payment_method'] === 'Paystack' ? $p['amount'] : 0, $payments));
$total_cash     = array_sum(array_map(fn($p) => $p['payment_method'] === 'Cash'     ? $p['amount'] : 0, $payments));

$conn->close();
?>

<!-- ── Payment Summary Cards ───────────────────────────────── -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card text-white bg-success text-center">
            <div class="card-body py-3">
                <i class="fas fa-money-bill-wave fa-2x mb-2"></i>
                <h4 class="mb-0">KES <?php echo number_format($total_all, 2); ?></h4>
                <small>Total Payments</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-white bg-primary text-center">
            <div class="card-body py-3">
                <i class="fas fa-credit-card fa-2x mb-2"></i>
                <h4 class="mb-0">KES <?php echo number_format($total_paystack, 2); ?></h4>
                <small>Paystack Payments</small>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card text-white bg-secondary text-center">
            <div class="card-body py-3">
                <i class="fas fa-coins fa-2x mb-2"></i>
                <h4 class="mb-0">KES <?php echo number_format($total_cash, 2); ?></h4>
                <small>Cash Payments</small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-money-bill-wave me-2"></i>Manage Payments</h2>
            </div>
            <div class="card-body">

                <?php include_once("../includes/admin_header.php"); ?>

                <?php echo $message; ?>

                <!-- ── Add Payment Form ───────────────────────── -->
                <h5 class="mb-3"><i class="fas fa-plus-circle me-2 text-success"></i>Record New Payment</h5>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="paymentForm">
                    <div class="row">

                        <!-- Member -->
                        <div class="col-md-6 mb-3">
                            <label for="member_id" class="form-label">Member <span class="text-danger">*</span></label>
                            <select name="member_id" id="member_id"
                                    class="form-select <?php echo (!empty($member_id_err)) ? 'is-invalid' : ''; ?>">
                                <option value="">— Select Member —</option>
                                <?php foreach ($members as $m): ?>
                                    <option value="<?php echo $m['member_id']; ?>"
                                            data-phone="<?php echo htmlspecialchars($m['phone_number'] ?? ''); ?>"
                                        <?php echo ($member_id == $m['member_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?>
                                        <?php echo $m['phone_number'] ? ' — ' . $m['phone_number'] : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="invalid-feedback"><?php echo $member_id_err; ?></span>
                        </div>

                        <!-- Amount -->
                        <div class="col-md-6 mb-3">
                            <label for="amount" class="form-label">Amount (KES) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">KES</span>
                                <input type="number" name="amount" id="amount" step="0.01" min="1"
                                       class="form-control <?php echo (!empty($amount_err)) ? 'is-invalid' : ''; ?>"
                                       value="<?php echo htmlspecialchars($amount); ?>"
                                       placeholder="e.g. 500">
                                <span class="invalid-feedback"><?php echo $amount_err; ?></span>
                            </div>
                        </div>

                        <!-- Payment Method -->
                        <div class="col-md-6 mb-3">
                            <label for="payment_method" class="form-label">Payment Method <span class="text-danger">*</span></label>
                            <select name="payment_method" id="payment_method"
                                    class="form-select <?php echo (!empty($payment_method_err)) ? 'is-invalid' : ''; ?>">
                                <option value="">— Select Method —</option>

                                <option value="Paystack"      <?php echo ($payment_method === 'Paystack')      ? 'selected' : ''; ?>>🅿️ Paystack</option>
                                <option value="Cash"          <?php echo ($payment_method === 'Cash')          ? 'selected' : ''; ?>>💵 Cash</option>
                                <option value="Credit Card"   <?php echo ($payment_method === 'Credit Card')   ? 'selected' : ''; ?>>💳 Credit Card</option>
                                <option value="Bank Transfer" <?php echo ($payment_method === 'Bank Transfer') ? 'selected' : ''; ?>>🏦 Bank Transfer</option>
                            </select>
                            <span class="invalid-feedback"><?php echo $payment_method_err; ?></span>
                        </div>



                        <!-- Description -->
                        <div class="col-md-12 mb-3">
                            <label for="description" class="form-label">Description</label>
                            <input type="text" name="description" id="description"
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($description); ?>"
                                   placeholder="e.g. Monthly membership fee, Volleyball session fee">
                        </div>

                    </div>



                    <div id="paystackBanner" class="alert alert-info py-2"
                         style="display:<?php echo ($payment_method === 'Paystack') ? 'block' : 'none'; ?>">
                        <i class="fas fa-credit-card me-2"></i>
                        <strong>Paystack:</strong> Member will be redirected to Paystack checkout.
                        Once the payment is completed, it will be recorded automatically.
                    </div>

                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        <i class="fas fa-plus-circle me-1"></i>
                        <span id="submitText">Record Payment</span>
                    </button>
                    <button type="reset" class="btn btn-secondary ms-2">
                        <i class="fas fa-undo me-1"></i>Clear
                    </button>
                </form>

                <hr class="my-4">

                <!-- ── Payments Table ─────────────────────────── -->
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Payments</h5>
                    <input type="text" id="searchPayments" class="form-control w-25"
                           placeholder="🔍 Search payments...">
                </div>

                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="paymentsTable">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Member</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Description</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($payments) > 0): ?>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($payment['payment_id']); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($payment['first_name'] . ' ' . $payment['last_name']); ?></strong>
                                            <?php if ($payment['phone_number']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($payment['phone_number']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong class="text-success">
                                                KES <?php echo number_format($payment['amount'], 2); ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <?php if ($payment['payment_method'] === 'Paystack'): ?>
                                                <span class="badge bg-primary">
                                                    <i class="fas fa-credit-card me-1"></i>Paystack
                                                </span>
                                            <?php elseif ($payment['payment_method'] === 'Cash'): ?>
                                                <span class="badge bg-secondary">
                                                    <i class="fas fa-coins me-1"></i>Cash
                                                </span>
                                            <?php elseif ($payment['payment_method'] === 'Credit Card'): ?>
                                                <span class="badge bg-primary">
                                                    <i class="fas fa-credit-card me-1"></i>Card
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-info">
                                                    <?php echo htmlspecialchars($payment['payment_method']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($payment['description'] ?: '—'); ?></td>
                                        <td>
                                            <small><?php echo date('d M Y, H:i', strtotime($payment['payment_date'])); ?></small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        No payments recorded yet.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
// ── Show/hide Paystack banner based on payment method ────────
var paymentMethodSelect = document.getElementById('payment_method');
var paystackBanner      = document.getElementById('paystackBanner');
var submitText          = document.getElementById('submitText');

paymentMethodSelect.addEventListener('change', function () {
    var isPaystack = this.value === 'Paystack';
    paystackBanner.style.display = isPaystack ? 'block' : 'none';
    submitText.textContent       = isPaystack ? 'Proceed to Paystack' : 'Record Payment';
});

// ── Search/filter payments table ──────────────────────────────
document.getElementById('searchPayments').addEventListener('keyup', function () {
    var query = this.value.toLowerCase();
    var rows  = document.querySelectorAll('#paymentsTable tbody tr');
    rows.forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
    });
});
</script>

<?php include_once("../includes/footer.php"); ?>
