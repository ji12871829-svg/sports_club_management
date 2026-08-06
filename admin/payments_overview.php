<?php
/**
 * admin/payments_overview.php
 * Unified payments overview — Paystack transactions + M-Pesa STK pushes
 * in one table with provider, status and date-range filters, CSV export,
 * and a cancel/timeout action for in-flight M-Pesa pushes.
 *
 * Data sources:
 *   - payments      : recorded/settled transactions (Paystack, confirmed M-Pesa,
 *                     Cash, Card, Bank Transfer, ...) — provider_reference /
 *                     payment_status columns when present.
 *   - mpesa_pending : in-flight M-Pesa STK pushes (status 'Pending') awaiting
 *                     the Safaricom callback. Completed pushes are recorded in *   `payments`, so only Pending rows are unioned to avoid
 *   double counting.
 */
ob_start(); // Buffer the admin header HTML so the CSV export branch stays clean.
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once __DIR__ . '/../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/cache.php';

$has_mpesa_pending = db_table_exists($conn, 'mpesa_pending');

// ── Filters (server side) ────────────────────────────────────────────────
$provider_filter = $_GET['provider'] ?? 'all';
$status_filter   = $_GET['status'] ?? 'all';
$date_from       = $_GET['from'] ?? '';
$date_to         = $_GET['to'] ?? '';
// Query string preserving active filters (used by the cancel PRG redirect and form)
$qs_current = http_build_query(array_filter([
    'provider' => $provider_filter !== 'all' ? $provider_filter : '',
    'status'   => $status_filter !== 'all' ? $status_filter : '',
    'from'     => $date_from,
    'to'       => $date_to,
]));

// ── POST: cancel / timeout a pending M-Pesa STK push ───────────────────────
$flash_msg = '';
$flash_ok  = true;
// Session flash (set by the PRG redirect after a successful cancel)
if (isset($_SESSION['po_flash'])) {
    $flash_ok  = (bool)($_SESSION['po_flash']['ok'] ?? true);
    $flash_msg = (string)($_SESSION['po_flash']['msg'] ?? '');
    unset($_SESSION['po_flash']);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'timeout_pending') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $flash_ok  = false;
        $flash_msg = 'Security check failed. Please refresh and try again.';
    } elseif (!$has_mpesa_pending) {
        $flash_ok  = false;
        $flash_msg = 'M-Pesa pending tracking is not enabled on this database.';
    } else {
        $pending_id = (int) ($_POST['pending_id'] ?? 0);
        if ($pending_id <= 0) {
            $flash_ok  = false;
            $flash_msg = 'Invalid pending push id.';
        } else {
            $stmt = $conn->prepare(
                "UPDATE mpesa_pending SET status = 'Cancelled' WHERE pending_id = ? AND status = 'Pending'"
            );
            $stmt->bind_param('i', $pending_id);
            $stmt->execute();
            if ($stmt->affected_rows > 0) {
                cache_delete('po_rows');
                $_SESSION['po_flash'] = ['ok' => true, 'msg' => 'Pending STK push marked as cancelled/timeout.'];
                header('Location: payments_overview.php' . ($qs_current !== '' ? '?' . $qs_current : ''));
                exit;
            } else {
                $flash_ok  = false;
                $flash_msg = 'That push was already processed or no longer pending.';
            }
            $stmt->close();
        }
    }
}

// ── Branch 1: recorded payments (cached 30s — writes go through manage_payments) ──
$rows = cache_remember('po_rows', 30, function () use ($conn, $has_mpesa_pending) {
    $rows = [];
    $sql = "SELECT
                p.payment_id             AS txn_id,
                NULL                     AS checkout_request_id,
                CONCAT(m.first_name,' ',m.last_name) AS member_name,
                m.email                  AS member_email,
                p.amount,
                p.payment_method         AS provider,
                CASE WHEN p.payment_status IN ('Paid','Completed','completed','Success','success')
                     THEN 'Completed'
                     ELSE COALESCE(NULLIF(p.payment_status,''), 'Completed')
                END                      AS status,
                p.provider_reference     AS reference,
                p.description,
                p.payment_date           AS txn_date,
                'payments'               AS source_table
            FROM payments p
            LEFT JOIN members m ON m.member_id = p.member_id";

    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) $rows[] = $row;
        $result->free();
    }

    // ── Branch 2: in-flight M-Pesa STK pushes ────────────────────
    if ($has_mpesa_pending) {
        $sql = "SELECT
                    NULL                 AS txn_id,
                    mp.pending_id        AS checkout_request_id,
                    CONCAT(m.first_name,' ',m.last_name) AS member_name,
                    m.email              AS member_email,
                    mp.amount,
                    'M-Pesa'             AS provider,
                    mp.status            AS status,
                    mp.checkout_request_id AS reference,
                    mp.description,
                    mp.created_at        AS txn_date,
                    'mpesa_pending'      AS source_table
                FROM mpesa_pending mp
                LEFT JOIN members m ON m.member_id = mp.member_id
                WHERE mp.status = 'Pending'";

        if ($result = $conn->query($sql)) {
            while ($row = $result->fetch_assoc()) $rows[] = $row;
            $result->free();
        }
    }

    // Chronological sort — newest first
    usort($rows, fn($a, $b) => strcmp($b['txn_date'] ?? '', $a['txn_date'] ?? ''));
    return $rows;
});

$conn->close();

// ── Filters (server side) ────────────────────────────────────────────────
$filtered = array_filter($rows, function ($r) use ($provider_filter, $status_filter, $date_from, $date_to) {
    if ($provider_filter !== 'all' && strtolower($r['provider'] ?? '') !== strtolower($provider_filter)) {
        return false;
    }
    if ($status_filter !== 'all' && strtolower($r['status'] ?? '') !== strtolower($status_filter)) {
        return false;
    }
    $d = substr((string)($r['txn_date'] ?? ''), 0, 10);
    if ($date_from !== '' && $d < $date_from) return false;
    if ($date_to !== '' && $d > $date_to) return false;
    return true;
});

// ── CSV export (respects the active filters) ─────────────────────────────
if (($_GET['export'] ?? '') === '1') {
    // Discard any HTML already emitted by admin_header.php before the CSV.
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="payments_overview_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Member', 'Email', 'Amount (KES)', 'Provider', 'Status', 'Reference', 'Description', 'Date']);
    foreach ($filtered as $r) {
        fputcsv($out, [
            $r['member_name'] ?? '', $r['member_email'] ?? '',
            number_format((float)($r['amount'] ?? 0), 2),
            $r['provider'] ?? '', $r['status'] ?? '', $r['reference'] ?? '',
            $r['description'] ?? '', $r['txn_date'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// ── Totals (always from the full dataset) ────────────────────────────────
$total_all      = array_sum(array_column($rows, 'amount'));
$is_completed   = fn($r) => strtolower($r['status'] ?? '') === 'completed';
$total_paystack = array_sum(array_map(fn($r) => strtolower($r['provider'] ?? '') === 'paystack' && $is_completed($r) ? (float)$r['amount'] : 0, $rows));
$total_mpesa    = array_sum(array_map(fn($r) => strtolower($r['provider'] ?? '') === 'm-pesa'   && $is_completed($r) ? (float)$r['amount'] : 0, $rows));
$pending_count  = count(array_filter($rows, fn($r) => strtolower($r['status'] ?? '') === 'pending'));

// ── Distinct providers for the filter dropdown ───────────────────────────
$providers = [];
foreach ($rows as $r) {
    $p = trim((string)($r['provider'] ?? ''));
    if ($p !== '' && !in_array($p, $providers, true)) $providers[] = $p;
}
sort($providers);

// ── Preserve filters when building URLs (reset / export) ─────────────────
$qs = $qs_current;
$export_url = 'payments_overview.php?export=1' . ($qs !== '' ? '&' . $qs : '');
$reset_url  = 'payments_overview.php';
?>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="asc-page-head">
        <div>
            <h1 class="asc-page-title">Payments Overview</h1>
            <p class="asc-page-sub">Paystack transactions & M-Pesa STK pushes in one place</p>
        </div>
        <div class="asc-page-actions">
            <a href="<?php echo e($export_url); ?>" class="asc-btn asc-btn-ghost"><i class="fas fa-file-csv"></i> Export CSV</a>
            <a href="manage_payments.php" class="asc-btn asc-btn-primary"><i class="fas fa-wallet"></i> Manage Payments</a>
            <a href="revenue_dashboard.php" class="asc-btn asc-btn-ghost"><i class="fas fa-chart-line"></i> Revenue</a>
        </div>
    </div>

    <?php if ($flash_msg !== ''): ?>
        <div class="alert <?php echo $flash_ok ? 'alert-success' : 'alert-danger'; ?> py-2">
            <i class="fas <?php echo $flash_ok ? 'fa-check-circle' : 'fa-times-circle'; ?> me-2"></i><?php echo e($flash_msg); ?>
        </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px;background:#1d5c8f20;">
                        <i class="fas fa-coins" style="color:#1d5c8f;"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5">KES <?php echo number_format($total_all, 2); ?></div>
                        <div class="text-muted small">All Transactions</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px;background:#0ea5e920;">
                        <i class="fas fa-credit-card" style="color:#0ea5e9;"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5">KES <?php echo number_format($total_paystack, 2); ?></div>
                        <div class="text-muted small">Paystack Transactions</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px;background:#10b98120;">
                        <i class="fas fa-mobile-alt" style="color:#10b981;"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5">KES <?php echo number_format($total_mpesa, 2); ?></div>
                        <div class="text-muted small">M-Pesa (settled)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:44px;height:44px;background:#f59e0b20;">
                        <i class="fas fa-hourglass-half" style="color:#f59e0b;"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5"><?php echo number_format($pending_count); ?></div>
                        <div class="text-muted small">Pending STK Pushes</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="GET" action="" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Provider</label>
                    <select name="provider" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo $provider_filter === 'all' ? 'selected' : ''; ?>>All Providers</option>
                        <?php foreach ($providers as $p): ?>
                            <option value="<?php echo e($p); ?>" <?php echo strtolower($provider_filter) === strtolower($p) ? 'selected' : ''; ?>>
                                <?php echo e($p); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">Status</label>
                    <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                        <option value="Completed" <?php echo strtolower($status_filter) === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="Pending" <?php echo strtolower($status_filter) === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">From</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?php echo e($date_from); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold mb-1">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?php echo e($date_to); ?>">
                </div>
                <div class="col-md-2">
                    <input type="text" id="searchTxn" class="form-control form-control-sm" placeholder="Search member, reference...">
                </div>
                <div class="col-md-2 text-md-end">
                    <a href="<?php echo e($reset_url); ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-rotate me-1"></i> Reset</a>
                    <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i> Apply</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0 fw-semibold">
                    <i class="fas fa-list me-2 text-primary"></i>
                    <?php echo count($filtered); ?> transaction<?php echo count($filtered) === 1 ? '' : 's'; ?>
                </h5>
                <span class="badge bg-light text-dark border">
                    <?php echo $has_mpesa_pending ? 'Live M-Pesa tracking on' : 'M-Pesa pending tracking off (table absent)'; ?>
                </span>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle" id="txnTable">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Member</th>
                            <th>Amount</th>
                            <th>Provider</th>
                            <th>Status</th>
                            <th>Reference</th>
                            <th>Description</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($filtered) > 0): ?>
                            <?php $i = 1; foreach ($filtered as $txn): ?>
                                <tr>
                                    <td class="text-muted"><?php echo $i++; ?></td>
                                    <td>
                                        <strong><?php echo e($txn['member_name'] ?: 'Not provided'); ?></strong>
                                        <?php if (!empty($txn['member_email'])): ?>
                                            <br><small class="text-muted"><?php echo e($txn['member_email']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong class="text-success">KES <?php echo number_format((float)$txn['amount'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <?php $prov = strtolower($txn['provider'] ?? ''); ?>
                                        <?php if ($prov === 'paystack'): ?>
                                            <span class="badge bg-primary"><i class="fas fa-credit-card me-1"></i>Paystack</span>
                                        <?php elseif ($prov === 'm-pesa'): ?>
                                            <span class="badge bg-success"><i class="fas fa-mobile-alt me-1"></i>M-Pesa</span>
                                        <?php elseif ($prov === 'cash'): ?>
                                            <span class="badge bg-secondary"><i class="fas fa-coins me-1"></i>Cash</span>
                                        <?php else: ?>
                                            <span class="badge bg-info"><?php echo e($txn['provider'] ?? '—'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php $txn_status = strtolower($txn['status'] ?? ''); ?>
                                        <?php if ($txn_status === 'pending'): ?>
                                            <span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>Pending</span>
                                        <?php elseif ($txn_status === 'completed'): ?>
                                            <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Completed</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><i class="fas fa-circle-question me-1"></i><?php echo e($txn['status'] ?: 'Other'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($txn['reference'])): ?>
                                            <code class="small"><?php echo e($txn['reference']); ?></code>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted small"><?php echo e($txn['description'] ?: '—'); ?></td>
                                    <td>
                                        <small><?php echo date('d M Y, H:i', strtotime($txn['txn_date'])); ?></small>
                                    </td>
                                    <td>
                                        <?php if ($txn_status === 'pending' && !empty($txn['checkout_request_id'])): ?>
                                            <form method="POST" action="payments_overview.php<?php echo $qs_current !== '' ? '?' . e($qs_current) : ''; ?>" class="d-inline"
                                                  onsubmit="return confirm('Mark this pending M-Pesa push as cancelled/timeout?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo e(csrf_ensure('admin_csrf')); ?>">
                                                <input type="hidden" name="action" value="timeout_pending">
                                                <input type="hidden" name="pending_id" value="<?php echo (int)$txn['checkout_request_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Cancel / timeout this STK push">
                                                    <i class="fas fa-xmark me-1"></i>Cancel
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted py-4">
                                    <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                    No transactions match the current filters.
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
// ── Live client-side search across the table ─────────────────────────────
document.getElementById('searchTxn').addEventListener('keyup', function () {
    var q = this.value.toLowerCase();
    var rows = document.querySelectorAll('#txnTable tbody tr');
    rows.forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>

<?php include_once '../includes/footer.php'; ?>
