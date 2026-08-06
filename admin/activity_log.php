<?php
/**
 * admin/activity_log.php
 * View and filter all admin activity logs.
 */
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";

require_once __DIR__ . '/../includes/input_sanitize.php';

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_module = trim($_GET['module'] ?? '');
$filter_admin  = trim($_GET['admin']  ?? '');
$filter_date   = trim($_GET['date']   ?? '');
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 50;
$offset        = ($page - 1) * $per_page;

$where  = ['1=1'];
$params = [];
$types  = '';

if ($filter_module !== '') {
    $where[]  = 'module = ?';
    $params[] = $filter_module;
    $types   .= 's';
}
if ($filter_admin !== '') {
    $where[]  = 'admin_email LIKE ?';
    $params[] = '%' . $filter_admin . '%';
    $types   .= 's';
}
if ($filter_date !== '') {
    $where[]  = 'DATE(created_at) = ?';
    $params[] = $filter_date;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);

// Total count
$count_sql  = "SELECT COUNT(*) FROM admin_activity_log WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_stmt->bind_result($total_rows);
$count_stmt->fetch();
$count_stmt->close();
$total_pages = max(1, ceil($total_rows / $per_page));

// Fetch logs
$sql  = "SELECT log_id, admin_id, admin_email, action, module, description,
                record_id, ip_address, created_at
         FROM admin_activity_log
         WHERE $where_sql
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$logs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Distinct modules for filter dropdown
$modules = $conn->query("SELECT DISTINCT module FROM admin_activity_log ORDER BY module ASC")
               ->fetch_all(MYSQLI_ASSOC);

$conn->close();

// Module → icon + color map
$module_style = [
    'Auth'          => ['icon' => 'fa-key',           'color' => '#2a6ba8'],
    'Members'       => ['icon' => 'fa-users',          'color' => '#2a6ba8'],
    'Fixtures'      => ['icon' => 'fa-calendar-alt',   'color' => '#f59e0b'],
    'Payments'      => ['icon' => 'fa-credit-card',    'color' => '#10b981'],
    'Bookings'      => ['icon' => 'fa-book',           'color' => '#06b6d4'],
    'Leagues'       => ['icon' => 'fa-trophy',         'color' => '#f97316'],
    'Tickets'       => ['icon' => 'fa-ticket-alt',     'color' => '#ec4899'],
    'Equipment'     => ['icon' => 'fa-tools',          'color' => '#64748b'],
    'Facilities'    => ['icon' => 'fa-building',       'color' => '#84cc16'],
    'Coaches'       => ['icon' => 'fa-chalkboard-teacher', 'color' => '#14b8a6'],
    'Damage'        => ['icon' => 'fa-exclamation-triangle', 'color' => '#ef4444'],
];
?>

<style>
.log-row { transition: background .15s; }
.log-row:hover { background: #f8fafc; }
.module-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: .72rem; font-weight: 700; padding: 3px 10px;
    border-radius: 50px; color: #fff;
    white-space: nowrap;
}
.filter-bar { background: #f8fafc; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; }
.log-desc { color: #64748b; font-size: .82rem; max-width: 280px; }
.ip-text  { font-size: .75rem; color: #94a3b8; font-family: monospace; }
.time-text { font-size: .8rem; color: #475569; white-space: nowrap; }
.pagination .page-link { color: #dc2626; }
.pagination .page-item.active .page-link { background: #dc2626; border-color: #dc2626; color: #fff; }
</style>

<div class="container-fluid py-4">

    <!-- Header -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle bg-dark d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;min-width:48px">
            <i class="fas fa-history text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">Admin Activity Log</h1>
            <p class="text-muted mb-0 small">Full audit trail of all admin actions</p>
        </div>
        <div class="ms-auto">
            <span class="badge bg-secondary">
                <?php echo number_format($total_rows); ?> total entries
            </span>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <label class="form-label small fw-semibold mb-1">Module</label>
                <select name="module" class="form-select form-select-sm">
                    <option value="">All Modules</option>
                    <?php foreach ($modules as $m): ?>
                        <option value="<?php echo e($m['module']); ?>"
                            <?php echo $filter_module === $m['module'] ? 'selected' : ''; ?>>
                            <?php echo e($m['module']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3">
                <label class="form-label small fw-semibold mb-1">Admin Email</label>
                <input type="text" name="admin" class="form-control form-control-sm"
                       placeholder="Search by email..."
                       value="<?php echo e($filter_admin); ?>">
            </div>
            <div class="col-sm-3">
                <label class="form-label small fw-semibold mb-1">Date</label>
                <input type="date" name="date" class="form-control form-control-sm"
                       value="<?php echo e($filter_date); ?>">
            </div>
            <div class="col-sm-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-dark">
                    <i class="fas fa-filter me-1"></i> Filter
                </button>
                <a href="activity_log.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-times me-1"></i> Clear
                </a>
            </div>
        </form>
    </div>

    <!-- Log table -->
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($logs)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-history fa-2x mb-2 d-block"></i>
                    No activity logs found.
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:140px">Time</th>
                            <th style="width:100px">Module</th>
                            <th>Action</th>
                            <th>Details</th>
                            <th style="width:80px">Record</th>
                            <th style="width:110px">Admin</th>
                            <th style="width:110px">IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log):
                            $style = $module_style[$log['module']] ?? ['icon' => 'fa-circle', 'color' => '#94a3b8'];
                        ?>
                        <tr class="log-row">
                            <td class="time-text">
                                <?php echo e(date('d M Y', strtotime($log['created_at']))); ?><br>
                                <span class="text-muted"><?php echo e(date('H:i:s', strtotime($log['created_at']))); ?></span>
                            </td>
                            <td>
                                <span class="module-badge"
                                      style="background:<?php echo $style['color']; ?>">
                                    <i class="fas <?php echo $style['icon']; ?>"></i>
                                    <?php echo e($log['module']); ?>
                                </span>
                            </td>
                            <td class="fw-semibold" style="font-size:.88rem;">
                                <?php echo e($log['action']); ?>
                            </td>
                            <td>
                                <?php if ($log['description']): ?>
                                    <span class="log-desc"><?php echo e($log['description']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small">
                                <?php echo $log['record_id'] ? '#' . e($log['record_id']) : '—'; ?>
                            </td>
                            <td>
                                <span class="ip-text" style="font-size:.78rem;font-family:inherit;">
                                    <?php echo e($log['admin_email'] ?? 'Unknown'); ?>
                                </span>
                            </td>
                            <td class="ip-text"><?php echo e($log['ip_address'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                <small class="text-muted">
                    Showing <?php echo ($offset + 1); ?>–<?php echo min($offset + $per_page, $total_rows); ?>
                    of <?php echo number_format($total_rows); ?> entries
                </small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page-1; ?>&module=<?php echo e($filter_module); ?>&admin=<?php echo e($filter_admin); ?>&date=<?php echo e($filter_date); ?>">
                                    &laquo;
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php for ($p = max(1,$page-2); $p <= min($total_pages,$page+2); $p++): ?>
                            <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $p; ?>&module=<?php echo e($filter_module); ?>&admin=<?php echo e($filter_admin); ?>&date=<?php echo e($filter_date); ?>">
                                    <?php echo $p; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?page=<?php echo $page+1; ?>&module=<?php echo e($filter_module); ?>&admin=<?php echo e($filter_admin); ?>&date=<?php echo e($filter_date); ?>">
                                    &raquo;
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>

</div>
