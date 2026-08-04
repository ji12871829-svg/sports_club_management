<?php
// ============================================================
//  admin/ai_review_log.php
//  Audit log for Gemini AI booking review decisions
// ============================================================
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once __DIR__ . '/../includes/input_sanitize.php';

// ── PAGINATION ─────────────────────────────────────────────────────────
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 25;
$offset = ($page - 1) * $per_page;

// ── FILTERS ────────────────────────────────────────────────────────────
$filter_decision = $_GET['decision'] ?? '';
$filter_applied = $_GET['applied'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';

$where_clauses = [];
$params = [];
$types = '';

if ($filter_decision !== '' && in_array($filter_decision, ['APPROVE', 'REJECT'], true)) {
    $where_clauses[] = 'r.decision = ?';
    $params[] = $filter_decision;
    $types .= 's';
}

if ($filter_applied !== '' && in_array($filter_applied, ['0', '1'], true)) {
    $where_clauses[] = 'r.applied = ?';
    $params[] = (int)$filter_applied;
    $types .= 'i';
}

if ($filter_date_from !== '') {
    $where_clauses[] = 'r.reviewed_at >= ?';
    $params[] = $filter_date_from . ' 00:00:00';
    $types .= 's';
}

if ($filter_date_to !== '') {
    $where_clauses[] = 'r.reviewed_at <= ?';
    $params[] = $filter_date_to . ' 23:59:59';
    $types .= 's';
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// Fetch filtered log entries (paginated)
$logs = [];

// Count total matching (filters applied)
$count_sql = "SELECT COUNT(*) AS total
        FROM ai_review_log r
        LEFT JOIN bookings b ON b.booking_id = r.booking_id
        {$where_sql}";

$filtered_total = 0;
if (!empty($params)) {
    $count_stmt = $conn->prepare($count_sql);
    if ($count_stmt) {
        $count_stmt->bind_param($types, ...$params);
        $count_stmt->execute();
        $count_stmt->bind_result($filtered_total);
        $count_stmt->fetch();
        $count_stmt->close();
    }
} else {
    $cr = $conn->query($count_sql);
    if ($cr) {
        $filtered_total = (int)$cr->fetch_assoc()['total'];
        $cr->free();
    }
}

$total_pages = max(1, ceil($filtered_total / $per_page));
$page = min($page, $total_pages);
$offset = ($page - 1) * $per_page;

$data_sql = "SELECT r.log_id, r.booking_id, r.decision, r.reason, r.applied, r.reviewed_at,
               m.first_name, m.last_name, s.name AS sport_name
        FROM ai_review_log r
        LEFT JOIN bookings b ON b.booking_id = r.booking_id
        LEFT JOIN members m ON m.member_id = b.member_id
        LEFT JOIN sports s ON s.sport_id = b.sport_id
        {$where_sql}
        ORDER BY r.reviewed_at DESC
        LIMIT ? OFFSET ?";

$data_params = $params;
$data_types = $types;
$data_params[] = $per_page;
$data_types .= 'i';
$data_params[] = $offset;
$data_types .= 'i';

$stmt = $conn->prepare($data_sql);
if ($stmt) {
    $stmt->bind_param($data_types, ...$data_params);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
}

// Total unfiltered count for stats
$total_count = 0;
$count_result = $conn->query("SELECT COUNT(*) AS total FROM ai_review_log");
if ($count_result) {
    $total_count = (int)$count_result->fetch_assoc()['total'];
    $count_result->free();
}

// Stats (unfiltered for overview)
$approve_count = 0;
$reject_count = 0;
$applied_count = 0;
$result = $conn->query("SELECT decision, applied, COUNT(*) AS cnt FROM ai_review_log GROUP BY decision, applied");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        if ($row['decision'] === 'APPROVE') $approve_count += (int)$row['cnt'];
        if ($row['decision'] === 'REJECT') $reject_count += (int)$row['cnt'];
        if ((int)$row['applied']) $applied_count += (int)$row['cnt'];
    }
    $result->free();
}

$conn->close();
?>

<style>
    body {
        background-color: #f8fafc !important;
        color: #334155;
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
        background-color: #7c3aed;
        border-radius: 2px;
        margin-bottom: 1rem;
    }

    .stat-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 1.25rem;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
    }

    .stat-value {
        font-size: 1.5rem;
        font-weight: 800;
        letter-spacing: -0.5px;
    }

    .stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-top: 2px;
    }

    .corporate-block-wrapper {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }

    .corporate-block-header {
        background-color: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.25rem 1.5rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 0.65rem;
    }

    .table-corporate {
        font-size: 0.9rem;
        margin-bottom: 0;
        vertical-align: middle;
    }

    .table-corporate thead th {
        background-color: #f8fafc;
        color: #475569;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #e2e8f0;
        padding: 1rem 1.25rem;
    }

    .table-corporate tbody td {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
    }

    .table-corporate tbody tr:last-child td {
        border-bottom: none;
    }

    .table-corporate tbody tr {
        transition: background-color 0.15s ease;
    }

    .table-corporate tbody tr:hover {
        background-color: #f8fafc;
    }

    .badge-decision-approve {
        background-color: #d1fae5;
        color: #065f46;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.25rem 0.625rem;
        border-radius: 50px;
        display: inline-block;
    }

    .badge-decision-reject {
        background-color: #fef2f2;
        color: #991b1b;
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.25rem 0.625rem;
        border-radius: 50px;
        display: inline-block;
    }

    .badge-applied {
        background-color: #e0f2fe;
        color: #0369a1;
        font-size: 0.7rem;
        font-weight: 600;
        padding: 0.2rem 0.5rem;
        border-radius: 50px;
    }

    .badge-skipped {
        background-color: #f1f5f9;
        color: #64748b;
        font-size: 0.7rem;
        font-weight: 600;
        padding: 0.2rem 0.5rem;
        border-radius: 50px;
    }
</style>

<div class="container-fluid py-5 px-4" style="max-width: 1200px;">

    <div class="row page-header-corporate align-items-end">
        <div class="col-md-12 d-flex justify-content-between align-items-end flex-wrap gap-3">
            <div>
                <div class="brand-accent-line"></div>
                <h1 class="corporate-title mb-2">AI Review Audit Log</h1>
                <p class="text-muted mb-0">Historical record of all Gemini AI booking review decisions.</p>
            </div>
            <div>
                <span class="badge bg-white text-dark border font-monospace px-3 py-2 small shadow-sm" style="border-radius: 6px;">
                    TOTAL: <?php echo $total_count; ?> REVIEWS
                </span>
            </div>
        </div>
    </div>

    <!-- Stats Overview -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width: 44px; height: 44px; background: #ecfdf5;">
                    <i class="fas fa-check text-success"></i>
                </div>
                <div>
                    <div class="stat-value text-success"><?php echo $approve_count; ?></div>
                    <div class="stat-label">Approvals</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width: 44px; height: 44px; background: #fef2f2;">
                    <i class="fas fa-times text-danger"></i>
                </div>
                <div>
                    <div class="stat-value text-danger"><?php echo $reject_count; ?></div>
                    <div class="stat-label">Rejections</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width: 44px; height: 44px; background: #e0f2fe;">
                    <i class="fas fa-check-double text-info"></i>
                </div>
                <div>
                    <div class="stat-value text-info"><?php echo $applied_count; ?></div>
                    <div class="stat-label">Applied</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-card d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center"
                     style="width: 44px; height: 44px; background: #f3e8ff;">
                    <i class="fas fa-robot text-purple-600" style="color:#7c3aed;"></i>
                </div>
                <div>
                    <div class="stat-value" style="color:#7c3aed;"><?php echo $total_count; ?></div>
                    <div class="stat-label">Total Reviews</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="corporate-block-wrapper mb-4">
        <div class="corporate-block-header">
            <i class="fas fa-filter" style="color:#7c3aed;"></i> Filters
        </div>
        <div class="p-3">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted mb-1">Decision</label>
                    <select name="decision" class="form-select form-select-sm"
                            style="border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;padding:0.45rem 0.75rem;">
                        <option value="">All Decisions</option>
                        <option value="APPROVE" <?php echo $filter_decision === 'APPROVE' ? 'selected' : ''; ?>>✅ Approve</option>
                        <option value="REJECT" <?php echo $filter_decision === 'REJECT' ? 'selected' : ''; ?>>❌ Reject</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold small text-muted mb-1">Status</label>
                    <select name="applied" class="form-select form-select-sm"
                            style="border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;padding:0.45rem 0.75rem;">
                        <option value="">All Statuses</option>
                        <option value="1" <?php echo $filter_applied === '1' ? 'selected' : ''; ?>>✅ Applied</option>
                        <option value="0" <?php echo $filter_applied === '0' ? 'selected' : ''; ?>>⏳ Skipped</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted mb-1">From Date</label>
                    <input type="date" name="date_from" value="<?php echo e($filter_date_from); ?>"
                           class="form-control form-control-sm"
                           style="border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;padding:0.45rem 0.75rem;">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small text-muted mb-1">To Date</label>
                    <input type="date" name="date_to" value="<?php echo e($filter_date_to); ?>"
                           class="form-control form-control-sm"
                           style="border:1px solid #e2e8f0;border-radius:6px;font-size:0.85rem;padding:0.45rem 0.75rem;">
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-sm w-100"
                            style="background:#7c3aed;color:#fff;border:none;font-weight:600;padding:0.45rem;border-radius:6px;">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <?php if ($filter_decision !== '' || $filter_applied !== '' || $filter_date_from !== '' || $filter_date_to !== ''): ?>
                    <div class="col-md-12 mt-2">
                        <a href="ai_review_log.php" class="small text-muted">
                            <i class="fas fa-times me-1"></i>Clear all filters
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Log Table -->
    <div class="corporate-block-wrapper">
        <div class="corporate-block-header">
            <i class="fas fa-history text-purple-600" style="color:#7c3aed;"></i> Review History
            <?php if (!empty($where_clauses)): ?>
                <span class="badge bg-light text-dark font-monospace ms-auto small px-2 py-1" style="font-size:0.7rem;">
                    FILTERED
                </span>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-corporate">
                <thead>
                    <tr>
                        <th style="width: 80px;">Log ID</th>
                        <th style="width: 80px;">Booking</th>
                        <th>Member</th>
                        <th>Sport</th>
                        <th>Decision</th>
                        <th>AI Reasoning</th>
                        <th>Status</th>
                        <th>Reviewed At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td>
                                    <span class="font-monospace text-muted small">#<?php echo (int)$log['log_id']; ?></span>
                                </td>
                                <td>
                                    <a href="manage_bookings.php?booking_id=<?php echo (int)$log['booking_id']; ?>"
                                       class="font-monospace text-decoration-none" style="color:#2563eb;font-weight:600;">
                                        #<?php echo (int)$log['booking_id']; ?>
                                    </a>
                                </td>
                                <td style="font-weight:600;color:#0f172a;">
                                    <?php echo e(($log['first_name'] ?? '') . ' ' . ($log['last_name'] ?? 'Unknown')); ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-secondary border px-2 py-1 rounded">
                                        <?php echo e($log['sport_name'] ?? 'N/A'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="<?php echo $log['decision'] === 'APPROVE' ? 'badge-decision-approve' : 'badge-decision-reject'; ?>">
                                        <i class="fas fa-<?php echo $log['decision'] === 'APPROVE' ? 'check' : 'times'; ?> me-1"></i>
                                        <?php echo e($log['decision']); ?>
                                    </span>
                                </td>
                                <td class="text-muted small" style="max-width: 280px;">
                                    <?php echo e($log['reason'] ?? '-'); ?>
                                </td>
                                <td>
                                    <?php if ($log['applied']): ?>
                                        <span class="badge-applied"><i class="fas fa-check me-1"></i>Applied</span>
                                    <?php else: ?>
                                        <span class="badge-skipped"><i class="fas fa-times me-1"></i>Skipped</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-monospace text-muted small">
                                    <?php echo e(date('d M Y H:i', strtotime($log['reviewed_at']))); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="p-5 text-center text-muted">
                                <div class="mb-3" style="font-size: 2.5rem;">🤖</div>
                                <h5 class="fw-bold text-dark mb-1">No AI Reviews Yet</h5>
                                <p class="mb-0 small text-muted">
                                    Run an AI review on the
                                    <a href="manage_bookings.php" class="text-decoration-none fw-semibold" style="color:#7c3aed;">
                                        Bookings page
                                    </a>
                                    to see results here.
                                </p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination Controls -->
    <div class="d-flex justify-content-between align-items-center px-3 py-3 border-top border-light">
        <div class="text-muted small">
            <?php if ($filtered_total > 0): ?>
                Showing <?php echo $offset + 1; ?> to <?php echo min($filtered_total, $offset + $per_page); ?> of <?php echo $filtered_total; ?> entries
            <?php else: ?>
                0 entries
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Log pagination">
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page - 1; ?>
                                <?php echo $filter_decision !== '' ? '&amp;decision=' . urlencode($filter_decision) : ''; ?>
                                <?php echo $filter_applied !== '' ? '&amp;applied=' . urlencode($filter_applied) : ''; ?>
                                <?php echo $filter_date_from !== '' ? '&amp;date_from=' . urlencode($filter_date_from) : ''; ?>
                                <?php echo $filter_date_to !== '' ? '&amp;date_to=' . urlencode($filter_date_to) : ''; ?>">Previous</a>
                        </li>
                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);
                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>
                                    <?php echo $filter_decision !== '' ? '&amp;decision=' . urlencode($filter_decision) : ''; ?>
                                    <?php echo $filter_applied !== '' ? '&amp;applied=' . urlencode($filter_applied) : ''; ?>
                                    <?php echo $filter_date_from !== '' ? '&amp;date_from=' . urlencode($filter_date_from) : ''; ?>
                                    <?php echo $filter_date_to !== '' ? '&amp;date_to=' . urlencode($filter_date_to) : ''; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page + 1; ?>
                                <?php echo $filter_decision !== '' ? '&amp;decision=' . urlencode($filter_decision) : ''; ?>
                                <?php echo $filter_applied !== '' ? '&amp;applied=' . urlencode($filter_applied) : ''; ?>
                                <?php echo $filter_date_from !== '' ? '&amp;date_from=' . urlencode($filter_date_from) : ''; ?>
                                <?php echo $filter_date_to !== '' ? '&amp;date_to=' . urlencode($filter_date_to) : ''; ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            <?php endif; ?>
            <a href="manage_bookings.php" class="btn btn-sm" style="background:#7c3aed;color:#fff;border:none;font-weight:600;padding:0.4rem 1rem;border-radius:6px;">
                <i class="fas fa-arrow-left me-1"></i> Back to Bookings
            </a>
        </div>
    </div>

</div>

<?php include_once("../includes/footer.php"); ?>
