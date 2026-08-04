<?php
/**
 * admin/manage_refunds.php
 * Issue full or partial refunds, track refund status.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';
require_once '../includes/csrf.php';

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

$message = '';

// ── POST: create refund ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_refund') {
        $payment_id  = (int)($_POST['payment_id'] ?? 0);
        $amount      = (float)($_POST['refund_amount'] ?? 0);
        $reason      = trim($_POST['reason'] ?? '');
        $admin_id    = $_SESSION['admin_id'] ?? null;

        // Get payment details
        $pmt = $conn->prepare("
            SELECT p.*, CONCAT(m.first_name,' ',m.last_name) AS member_name
            FROM payments p
            JOIN members m ON m.member_id = p.member_id
            WHERE p.payment_id = ?
        ");
        $pmt->bind_param("i", $payment_id);
        $pmt->execute();
        $payment = $pmt->get_result()->fetch_assoc();
        $pmt->close();

        if (!$payment) {
            $message = '<div class="alert alert-danger">Payment not found.</div>';
        } elseif ($amount <= 0 || $amount > (float)$payment['amount']) {
            $message = '<div class="alert alert-danger">Refund amount must be between KES 1 and KES ' . number_format($payment['amount'], 2) . '.</div>';
        } else {
            $type = $amount >= (float)$payment['amount'] ? 'full' : 'partial';
            $stmt = $conn->prepare("
                INSERT INTO refunds (payment_id, member_id, amount, refund_type, reason, status, processed_by, processed_at)
                VALUES (?, ?, ?, ?, ?, 'Processed', ?, NOW())
            ");
            $stmt->bind_param("iidssi", $payment_id, $payment['member_id'], $amount, $type, $reason, $admin_id);
            if ($stmt->execute()) {
                log_activity($conn, 'Issued refund', 'Payments', $payment_id,
                    "KES " . number_format($amount, 2) . " ({$type}) to {$payment['member_name']}. Reason: $reason");
                $message = "<div class='alert alert-success'>
                              <i class='fas fa-check-circle me-2'></i>
                              <strong>{$type} refund</strong> of KES " . number_format($amount, 2) . "
                              issued to <strong>" . e($payment['member_name']) . "</strong>.
                              <br><small class='text-muted'>Note: Record this as a manual refund in your payment provider dashboard.</small>
                            </div>";
            } else {
                $message = '<div class="alert alert-danger">Failed to record refund: ' . e($conn->error) . '</div>';
            }
            $stmt->close();
        }
    } elseif ($action === 'update_status') {
        $refund_id = (int)($_POST['refund_id'] ?? 0);
        $status    = $_POST['status'] ?? '';
        if (in_array($status, ['Pending','Processed','Failed'], true)) {
            $stmt = $conn->prepare("UPDATE refunds SET status=?, processed_at=NOW() WHERE refund_id=?");
            $stmt->bind_param("si", $status, $refund_id);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'Updated refund status', 'Payments', $refund_id, "Status: $status");
            $message = '<div class="alert alert-success">Refund status updated.</div>';
        }
    }
}

// ── Load data ─────────────────────────────────────────────────────────────────
// Recent payments eligible for refund
$payments = $conn->query("
    SELECT p.payment_id, p.amount, p.payment_method, p.description, p.payment_date,
           CONCAT(m.first_name,' ',m.last_name) AS member_name, m.email,
           COALESCE(SUM(r.amount), 0) AS refunded_total
    FROM payments p
    JOIN members m ON m.member_id = p.member_id
    LEFT JOIN refunds r ON r.payment_id = p.payment_id AND r.status = 'Processed'
    GROUP BY p.payment_id
    HAVING refunded_total < p.amount
    ORDER BY p.payment_date DESC
    LIMIT 100
")->fetch_all(MYSQLI_ASSOC);

// All refunds
$refunds = $conn->query("
    SELECT r.*, p.amount AS payment_amount, p.description AS payment_desc,
           p.payment_method, p.payment_date,
           CONCAT(m.first_name,' ',m.last_name) AS member_name
    FROM refunds r
    JOIN payments p ON p.payment_id = r.payment_id
    JOIN members  m ON m.member_id  = r.member_id
    ORDER BY r.created_at DESC
    LIMIT 100
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:#dc2626;">
            <i class="fas fa-undo text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">Refund Management</h1>
            <p class="text-muted mb-0 small">Issue and track payment refunds</p>
        </div>
    </div>

    <?php if ($message) echo $message; ?>

    <div class="row g-4">
        <!-- Issue refund -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-plus-circle me-2 text-danger"></i>Issue New Refund
                </div>
                <div class="card-body">
                    <form method="POST">
                        <?php echo csrf_field('admin_csrf'); ?>
                        <input type="hidden" name="action" value="create_refund">

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Select Payment</label>
                            <select name="payment_id" class="form-select" id="payment_select" required
                                    onchange="updateMaxAmount(this)">
                                <option value="">— Choose a payment —</option>
                                <?php foreach ($payments as $p): ?>
                                    <option value="<?php echo e($p['payment_id']); ?>"
                                            data-max="<?php echo e((float)$p['amount'] - (float)$p['refunded_total']); ?>"
                                            data-amount="<?php echo e($p['amount']); ?>">
                                        <?php echo e($p['member_name']); ?> —
                                        KES <?php echo number_format($p['amount'], 2); ?> —
                                        <?php echo e($p['description']); ?> —
                                        <?php echo e(date('d M Y', strtotime($p['payment_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Refund Amount (KES)</label>
                            <input type="number" name="refund_amount" id="refund_amount"
                                   class="form-control" min="1" step="0.01"
                                   placeholder="Enter amount to refund" required>
                            <div class="form-text" id="max_note"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Reason</label>
                            <textarea name="reason" class="form-control" rows="3"
                                      placeholder="Reason for refund (optional)"></textarea>
                        </div>

                        <div class="alert alert-warning py-2 small mb-3">
                            <i class="fas fa-info-circle me-1"></i>
                            This records the refund in the system. You must also process the actual
                            money transfer in your payment provider (M-Pesa / Paystack) dashboard.
                        </div>

                        <button type="submit" class="btn btn-danger w-100">
                            <i class="fas fa-undo me-2"></i>Issue Refund
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Refund history -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex align-items-center">
                    <i class="fas fa-history me-2"></i>Refund History
                    <span class="badge bg-secondary ms-2"><?php echo count($refunds); ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($refunds)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-receipt fa-2x mb-2 d-block"></i>
                            No refunds issued yet.
                        </div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Date</th><th>Member</th><th>Amount</th><th>Type</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($refunds as $r): ?>
                                <tr>
                                    <td><small><?php echo e(date('d M Y', strtotime($r['created_at']))); ?></small></td>
                                    <td class="fw-semibold"><?php echo e($r['member_name']); ?></td>
                                    <td class="fw-bold text-danger">KES <?php echo number_format($r['amount'], 2); ?></td>
                                    <td>
                                        <span class="badge <?php echo $r['refund_type'] === 'full' ? 'bg-danger' : 'bg-warning text-dark'; ?>">
                                            <?php echo ucfirst($r['refund_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $r['status'] === 'Processed' ? 'bg-success' : ($r['status'] === 'Failed' ? 'bg-danger' : 'bg-secondary'); ?>">
                                            <?php echo e($r['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <?php echo csrf_field('admin_csrf'); ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="refund_id" value="<?php echo e($r['refund_id']); ?>">
                                            <select name="status" class="form-select form-select-sm d-inline w-auto"
                                                    onchange="this.form.submit()">
                                                <?php foreach (['Pending','Processed','Failed'] as $st): ?>
                                                    <option <?php echo $r['status'] === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                                <?php if ($r['reason']): ?>
                                <tr class="table-light">
                                    <td colspan="6" class="py-1 ps-4">
                                        <small class="text-muted"><em><?php echo e($r['reason']); ?></em></small>
                                    </td>
                                </tr>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function updateMaxAmount(sel) {
    const opt = sel.options[sel.selectedIndex];
    const max = parseFloat(opt.dataset.max || 0);
    const input = document.getElementById('refund_amount');
    const note  = document.getElementById('max_note');
    if (max > 0) {
        input.max = max;
        input.value = max.toFixed(2);
        note.textContent = 'Max refundable: KES ' + max.toLocaleString('en-KE', {minimumFractionDigits:2});
    } else {
        input.value = '';
        note.textContent = '';
    }
}
</script>
