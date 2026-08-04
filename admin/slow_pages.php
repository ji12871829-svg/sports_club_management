<?php
/**
 * admin/slow_pages.php
 * Performance monitor — reads the page_timings table written by the
 * AscProfiler (includes/profiler.php) and shows the slowest pages so you
 * can spot bottlenecks at a glance.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/csrf.php';

// ── Purge action (CSRF-protected) ───────────────────────────────────────
$purge_done = false;
$purge_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purge_timings'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'slow_pages_purge')) {
        $purge_error = 'Security check failed. Please refresh and try again.';
    } else {
        $p = $conn->query('DELETE FROM page_timings');
        if ($p) {
            $purge_done = true;
        } else {
            $purge_error = 'Could not clear the timing log: ' . $conn->error;
        }
    }
}

// ── Filters ────────────────────────────────────────────────────────────
$f_page = trim((string) ($_GET['page'] ?? ''));
$f_min  = (int) ($_GET['min'] ?? 100);   // minimum duration in ms
$f_days = (int) ($_GET['days'] ?? 30);   // look-back window in days
$f_limit = (int) ($_GET['limit'] ?? 100);

$f_min  = max(0, min($f_min, 60000));
$f_days = max(1, min($f_days, 3650));
$f_limit = max(10, min($f_limit, 500));

$has_table = false;
$res = $conn->query("SHOW TABLES LIKE 'page_timings'");
$has_table = ($res && $res->num_rows > 0);
if ($res) { $res->free(); }

$rows   = [];
$totals = ['count' => 0, 'avg_ms' => 0, 'max_ms' => 0, 'slow_today' => 0, 'total_ms' => 0];

// Daily trend for the pure-CSS chart (counts per day across the window)
$trend = [];

if ($has_table) {
    $where  = ['created_at >= NOW() - INTERVAL ' . $f_days . ' DAY', 'duration_ms >= ' . $f_min];
    $params = [];
    $types  = '';
    if ($f_page !== '') {
        $where[]  = 'page LIKE ?';
        $types   .= 's';
        $params[] = '%' . $f_page . '%';
    }
    $where_sql = implode(' AND ', $where);

    // Summary row
    $s = $conn->prepare("SELECT COUNT(*) AS cnt, ROUND(AVG(duration_ms),1) AS avg_ms,
                                MAX(duration_ms) AS max_ms, COALESCE(SUM(duration_ms),0) AS total_ms
                         FROM page_timings WHERE $where_sql");
    if ($s) {
        if ($params) { $s->bind_param($types, ...$params); }
        $s->execute();
        $r = $s->get_result();
        if ($row = $r->fetch_assoc()) {
            $totals['count']    = (int) $row['cnt'];
            $totals['avg_ms']   = (float) $row['avg_ms'];
            $totals['max_ms']   = (float) $row['max_ms'];
            $totals['total_ms'] = (float) $row['total_ms'];
        }
        $r->free();
        $s->close();
    }

    // Slow today (any page > 500 ms today)
    $t = $conn->query("SELECT COUNT(*) FROM page_timings
                       WHERE duration_ms >= 500 AND created_at >= CURDATE()");
    if ($t && $row = $t->fetch_row()) {
        $totals['slow_today'] = (int) $row[0];
    }
    if ($t) { $t->free(); }

    // Daily trend — page counts per day over the look-back window.
    // Uses its own WHERE (int-only clauses) so the optional page LIKE ?
    // placeholder from $where_sql can't leak into a plain query().
    $trend_where = implode(' AND ', [
        'created_at >= NOW() - INTERVAL ' . $f_days . ' DAY',
        'duration_ms >= ' . $f_min,
    ]);
    $tr = $conn->query("SELECT DATE(created_at) AS d, COUNT(*) AS c, ROUND(MAX(duration_ms),1) AS mx
                        FROM page_timings WHERE $trend_where
                        GROUP BY DATE(created_at) ORDER BY d ASC");
    if ($tr) {
        while ($row = $tr->fetch_assoc()) {
            $trend[] = $row;
        }
        $tr->free();
    }

    // Detail rows
    $order = strtolower((string) ($_GET['sort'] ?? 'duration')) === 'date' ? 'created_at DESC' : 'duration_ms DESC';
    $list_sql = "SELECT page, duration_ms, query_count, memory_mb, created_at
                 FROM page_timings WHERE $where_sql
                 ORDER BY $order LIMIT $f_limit";
    $ls = $conn->prepare($list_sql);
    if ($ls) {
        if ($params) { $ls->bind_param($types, ...$params); }
        $ls->execute();
        $lr = $ls->get_result();
        while ($row = $lr->fetch_assoc()) {
            $rows[] = $row;
        }
        $lr->free();
        $ls->close();
    }
}

$conn->close();

// Preserve filters in link helper
function sp_query(array $overrides = []): string
{
    $q = array_merge($_GET, $overrides);
    unset($q['sort']);
    return http_build_query($q);
}
?>

<div class="asc-dash">
    <div class="asc-page-head">
        <div>
            <h1 class="asc-page-title">Slow Pages</h1>
            <p class="asc-page-sub">Pages over <?php echo (int) $f_min; ?> ms in the last <?php echo (int) $f_days; ?> days — from the request profiler</p>
        </div>
        <div class="asc-page-actions">
            <a href="system_health.php" class="asc-btn asc-btn-ghost">
                <i class="fas fa-server"></i> System Health
            </a>
            <button type="button" class="asc-btn asc-btn-ghost" data-bs-toggle="modal" data-bs-target="#purgeModal"
                    style="color:#dc2626;">
                <i class="fas fa-eraser"></i> Clear Log
            </button>
        </div>
    </div>

    <?php if ($purge_done): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 d-flex align-items-center gap-2 mb-3">
            <i class="fas fa-circle-check"></i> Timing log cleared.
        </div>
    <?php endif; ?>
    <?php if ($purge_error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-3 d-flex align-items-center gap-2 mb-3">
            <i class="fas fa-triangle-exclamation"></i> <?php echo e($purge_error); ?>
        </div>
    <?php endif; ?>

    <?php if (!$has_table): ?>
        <div class="asc-card">
            <div class="asc-empty">
                <i class="fas fa-gauge-high"></i>
                <p>No page timings recorded yet.</p>
                <p class="small text-muted">The profiler records pages slower than 100 ms (configurable via <code>ASC_PROFILER_SLOW_MS</code>). Visit a few admin pages, then come back.</p>
            </div>
        </div>
    <?php else: ?>

    <!-- Filter bar -->
    <form method="get" class="asc-card mb-4">
        <div class="card-body d-flex flex-wrap align-items-end gap-3 py-3 px-4">
            <div>
                <label class="asc-form-label d-block small fw-semibold mb-1" for="f_page">Page contains</label>
                <input type="text" name="page" id="f_page" class="form-control form-control-sm" style="min-width:200px;"
                       value="<?php echo e($f_page); ?>" placeholder="e.g. manage_bookings">
            </div>
            <div>
                <label class="asc-form-label d-block small fw-semibold mb-1" for="f_min">Min duration (ms)</label>
                <input type="number" name="min" id="f_min" class="form-control form-control-sm" style="width:120px;" value="<?php echo (int) $f_min; ?>" min="0">
            </div>
            <div>
                <label class="asc-form-label d-block small fw-semibold mb-1" for="f_days">Look back (days)</label>
                <input type="number" name="days" id="f_days" class="form-control form-control-sm" style="width:110px;" value="<?php echo (int) $f_days; ?>" min="1">
            </div>
            <div>
                <label class="asc-form-label d-block small fw-semibold mb-1" for="f_limit">Rows</label>
                <input type="number" name="limit" id="f_limit" class="form-control form-control-sm" style="width:90px;" value="<?php echo (int) $f_limit; ?>" min="10">
            </div>
            <button type="submit" class="asc-btn asc-btn-primary btn-sm"><i class="fas fa-filter"></i> Apply</button>
            <a href="slow_pages.php" class="asc-btn asc-btn-ghost btn-sm"><i class="fas fa-rotate-left"></i> Reset</a>
        </div>
    </form>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top"><span class="asc-stat-icon asc-icon-info"><i class="fas fa-layer-group"></i></span></div>
                <p class="asc-stat-label">Logged Pages</p>
                <p class="asc-stat-value"><?php echo number_format($totals['count']); ?></p>
                <p class="asc-stat-note">Slower than <?php echo (int) $f_min; ?> ms</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top"><span class="asc-stat-icon asc-icon-brand"><i class="fas fa-gauge-high"></i></span></div>
                <p class="asc-stat-label">Average</p>
                <p class="asc-stat-value"><?php echo number_format($totals['avg_ms'], 1); ?> <span class="small">ms</span></p>
                <p class="asc-stat-note">Across filtered set</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top"><span class="asc-stat-icon asc-icon-warning"><i class="fas fa-arrow-trend-up"></i></span></div>
                <p class="asc-stat-label">Slowest</p>
                <p class="asc-stat-value"><?php echo number_format($totals['max_ms'], 1); ?> <span class="small">ms</span></p>
                <p class="asc-stat-note">Worst single hit</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top"><span class="asc-stat-icon asc-icon-danger"><i class="fas fa-fire"></i></span></div>
                <p class="asc-stat-label">Slow Today (&gt;500 ms)</p>
                <p class="asc-stat-value"><?php echo number_format($totals['slow_today']); ?></p>
                <p class="asc-stat-note">Today's slowest requests</p>
            </div>
        </div>
    </div>

    <!-- Daily trend chart (pure CSS) -->
    <?php if (!empty($trend)): ?>
    <?php
    $trend_max = 1;
    foreach ($trend as $t) { if ((int) $t['c'] > $trend_max) $trend_max = (int) $t['c']; }
    ?>
    <div class="asc-card mb-4">
        <div class="asc-card-head">
            <h4 class="asc-card-title">Slow Pages Per Day</h4>
            <span class="text-muted small"><?php echo count($trend); ?> day<?php echo count($trend) === 1 ? '' : 's'; ?> with recorded pages</span>
        </div>
        <div class="card-body p-4">
            <div class="asc-rev-chart" style="height:120px;">
                <?php foreach ($trend as $t):
                    $pct = max(4, (int) round(((int) $t['c'] / $trend_max) * 100));
                ?>
                <div class="asc-rev-col" title="<?php echo e($t['d']); ?>: <?php echo (int) $t['c']; ?> page(s), slowest <?php echo number_format((float) $t['mx'], 1); ?> ms">
                    <div class="asc-rev-bar-track">
                        <div class="asc-rev-bar-fill" style="height:<?php echo (int) $pct; ?>%;background:linear-gradient(180deg,#6366f1,#8b5cf6);"></div>
                    </div>
                    <span class="asc-rev-label"><?php echo e(date('d M', strtotime($t['d']))); ?></span>
                    <span class="asc-rev-value"><?php echo (int) $t['c']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Detail table -->
    <div class="asc-card">
        <div class="asc-card-head">
            <h4 class="asc-card-title">Timings</h4>
            <span class="text-muted small">
                <a href="?<?php echo e(sp_query(['sort' => 'duration'])); ?>" class="text-decoration-none">By duration</a>
                &middot;
                <a href="?<?php echo e(sp_query(['sort' => 'date'])); ?>" class="text-decoration-none">By date</a>
            </span>
        </div>
        <div class="asc-table-wrap">
            <table class="asc-table">
                <thead>
                    <tr>
                        <th>Page</th>
                        <th class="text-end">Duration</th>
                        <th class="text-end">Queries</th>
                        <th class="text-end">Memory</th>
                        <th>Recorded</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="5"><div class="asc-empty"><i class="fas fa-circle-check"></i><p>No pages match the current filters.</p></div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($rows as $row):
                        $ms = (float) $row['duration_ms'];
                        $badge = $ms >= 2000 ? 'asc-badge-danger'
                            : ($ms >= 800 ? 'asc-badge-warning' : 'asc-badge-neutral');
                    ?>
                        <tr>
                            <td class="fw-semibold font-monospace small"><?php echo e($row['page']); ?></td>
                            <td class="text-end">
                                <span class="asc-badge <?php echo $badge; ?>"><?php echo number_format($ms, 1); ?> ms</span>
                            </td>
                            <td class="text-end text-muted"><?php echo number_format((int) $row['query_count']); ?></td>
                            <td class="text-end text-muted"><?php echo number_format((float) $row['memory_mb'], 1); ?> MB</td>
                            <td class="text-muted"><?php echo e(date('d M Y H:i', strtotime($row['created_at']))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Purge confirm modal -->
<div class="modal fade" id="purgeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-eraser me-2 text-danger"></i>Clear timing log?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This permanently deletes all recorded page timings. The profiler will start recording again on the next slow request.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="post" action="slow_pages.php" class="d-inline">
                    <?php echo csrf_field('slow_pages_purge'); ?>
                    <input type="hidden" name="purge_timings" value="1">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>Clear Log</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include_once("../includes/footer.php"); ?>
