<?php
/**
 * admin/manage_refunds.php
 * Issue full or partial refunds, track refund status.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';
require_once '../includes/csrf.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

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
            $message = '<div class="alert alert-danger border-0 shadow-sm">Payment not found.</div>';
        } elseif ($amount <= 0 || $amount > (float)$payment['amount']) {
            $message = '<div class="alert alert-danger border-0 shadow-sm">Refund amount must be between KES 1 and KES ' . number_format($payment['amount'], 2) . '.</div>';
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
                $message = "<div class='alert alert-success border-0 shadow-sm'>
                              <i class='fas fa-check-circle me-2'></i>
                              <strong>{$type} refund</strong> of KES " . number_format($amount, 2) . "
                              issued to <strong>" . e($payment['member_name']) . "</strong>.
                              <br><small class='text-muted'>Note: Record this as a manual refund in your payment provider dashboard.</small>
                            </div>";
            } else {
                $message = '<div class="alert alert-danger border-0 shadow-sm">Failed to record refund: ' . e($conn->error) . '</div>';
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
            $message = '<div class="alert alert-success border-0 shadow-sm">Refund status updated.</div>';
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

<!-- ── CUSTOM INTEGRATED CSS DESIGN STYLES ──────────────────── -->
<style>
    body { background-color: #f8fafc !important; color: #334155 !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    .page-header-text { font-size: 1.5rem; font-weight: 700; color: #0f172a; letter-spacing: -0.025em; }
    
    /* Premium Minimal Workspace Cards */
    .workspace-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); overflow: hidden; padding: 1.5rem !important; }
    .card-header-title { font-size: 0.95rem; font-weight: 600; color: #0f172a; margin-bottom: 1.25rem; border-bottom: 1px solid #f1f5f9; padding-bottom: 0.75rem; display: flex; align-items: center; }
    
    /* Clean Enterprise Inputs */
    .form-label { font-size: 0.75rem; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.025em; margin-bottom: 0.375rem; }
    .form-control, .form-select { background-color: #ffffff !important; border: 1px solid #cbd5e1 !important; border-radius: 8px !important; padding: 0.55rem 0.75rem !important; font-size: 0.9rem !important; color: #0f172a !important; transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out !important; }
    .form-control:focus, .form-select:focus { border-color: #dc2626 !important; box-shadow: 0 0 0 2px rgba(220, 38, 38, 0.15) !important; outline: 0 !important; }
    .form-text { font-size: 0.75rem !important; color: #64748b !important; margin-top: 0.375rem; }
    
    /* Premium Action Buttons */
    .btn-danger { background-color: #0f172a !important; color: #ffffff !important; border: none !important; border-radius: 8px !important; padding: 0.6rem 1.25rem !important; font-size: 0.875rem !important; font-weight: 500 !important; transition: background-color 0.1s ease !important; }
    .btn-danger:hover { background-color: #1e293b !important; color: #ffffff !important; }

    /* Structural Data Table System */
    .table-container th { background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.75rem 1.25rem; }
    .table-container td { padding: 1rem 1.25rem; border-bottom: 1px solid #f1f5f9; font-size: 0.875rem; color: #334155; vertical-align: middle; }
    .table-container tr:hover td { background-color: #fafafa; }
    
    /* System Transaction Type Badges */
    .status-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; font-weight: 600; border-radius: 6px; line-height: 1; border: 1px solid transparent; }
    .status-processed { background-color: #f0fdf4 !important; color: #16a34a !important; border-color: #bbf7d0; }
    .status-pending { background-color: #fef3c7 !important; color: #d97706 !important; border-color: #fde68a; }
    .status-failed { background-color: #fef2f2 !important; color: #dc2626 !important; border-color: #fecaca; }
    
    .type-pill { display: inline-flex; align-items: center; padding: 0.25rem 0.5rem; font-size: 0.75rem; font-weight: 600; border-radius: 6px; line-height: 1; }
    .type-full { background-color: #fef2f2; color: #dc2626; }
    .type-partial { background-color: #fff7ed; color: #ea580c; }
</style>

<div class="container-fluid py-4 px-md-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center bg-white border shadow-sm"
             style="width:46px;height:46px;">
            <i class="fas fa-undo text-dark"></i>
        </div>
        <div>
            <h1 class="page-header-text mb-0">Refund Management</h1>
            <p class="text-muted mb-0 small">Issue and track payment refunds</p>
        </div>
    </div>

    <?php if ($message) echo $message; ?>

    <div class="row g-4">
        <!-- Issue refund -->
        <div class="col-lg-5">
            <div class="workspace-card">
                <h5 class="card-header-title">
                    <i class="fas fa-plus-circle me-2 text-danger"></i>Issue New Refund
                </h5>
                <form method="POST">
                    <?php echo csrf_field('admin_csrf'); ?>
                    <input type="hidden" name="action" value="create_refund">

                    <div class="mb-3">
                        <label class="form-label">Select Payment</label>
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
                        <label class="form-label">Refund Amount (KES)</label>
                        <input type="number" name="refund_amount" id="refund_amount"
                               class="form-control" min="1" step="0.01"
                               placeholder="Enter amount to refund" required>
                        <div class="form-text text-muted" id="max_note"></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason</label>
                        <textarea name="reason" class="form-control" rows="3"
                                  placeholder="Reason for refund (optional)"></textarea>
                    </div>

                    <div class="alert alert-warning py-2 small mb-3 border-0 shadow-sm" style="background-color: #fffbeb; color: #b45309;">
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

        <!-- Refund history -->
        <div class="col-lg-7">
            <div class="workspace-card p-0">
                <h5 class="card-header-title" style="padding: 0 1.5rem 0.75rem 1.5rem; margin-bottom: 0;">
                    <i class="fas fa-history me-2"></i>Refund History
                    <span class="badge bg-light text-dark border ms-2 px-2 py-0.5" style="font-size: 0.75rem; font-weight: 700;"><?php echo count($refunds); ?></span>
                </h5>
                <div class="card-body p-0">
                    <?php if (empty($refunds)): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fas fa-receipt fa-2x mb-2 d-block opacity-75"></i>
                            No refunds issued yet.
                        </div>
                    <?php else: ?>
                    <div class="table-responsive table-container">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr><th>Date</th><th>Member</th><th>Amount</th><th>Type</th><th>Status</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($refunds as $r): ?>
                                <tr>
                                    <td class="text-muted"><small><?php echo e(date('d M Y', strtotime($r['created_at']))); ?></small></td>
                                    <td class="fw-semibold text-slate-900"><?php echo e($r['member_name']); ?></td>
                                    <td class="fw-bold text-danger">KES <?php echo number_format($r['amount'], 2); ?></td>
                                    <td>
                                        <span class="type-pill <?php echo $r['refund_type'] === 'full' ? 'type-full' : 'type-partial'; ?>">
                                            <?php echo ucfirst($r['refund_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-pill <?php echo $r['status'] === 'Processed' ? 'status-processed' : ($r['status'] === 'Failed' ? 'status-failed' : 'status-pending'); ?>">
                                            <?php echo e($r['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <form method="POST" class="d-inline">
                                            <?php echo csrf_field('admin_csrf'); ?>
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="refund_id" value="<?php echo e($r['refund_id']); ?>">
                                            <select name="status" class="form-select form-select-sm d-inline w-auto" style="padding: 0.25rem 1.5rem 0.25rem 0.5rem !important; font-size: 0.8rem !important;"
                                                    onchange="this.form.submit()">
                                                <?php foreach (['Pending','Processed','Failed'] as $st): ?>
                                                    <option <?php echo $r['status'] === $st ? 'selected' : ''; ?>><?php echo $st; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                </tr>
                                <?php if ($r['reason']): ?>
                                <tr style="background-color: #fafafa;">
                                    <td colspan="6" class="py-2 ps-4 border-bottom">
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

<?php include_once '../includes/footer.php'; ?>