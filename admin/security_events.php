<?php
/**
 * admin/security_events.php
 * View, filter and analyze the security-events register (rate-limit hits,
 * CSRF rejections, webhook/callback failures, auth lockouts).
 *
 * Data source: security_events table (migration 057), written by
 * includes/security_events.php at every security choke point.
 */
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once __DIR__ . '/../includes/input_sanitize.php';

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_type     = trim($_GET['type']     ?? '');
$filter_severity = trim($_GET['severity'] ?? '');
$filter_ip       = trim($_GET['ip']       ?? '');
$filter_date     = trim($_GET['date']     ?? '');
$page            = max(1, (int)($_GET['page'] ?? 1));
$per_page        = 50;
$offset          = ($page - 1) * $per_page;

$where  = ['1=1'];
$params = [];
$types  = '';

if ($filter_type !== '') {
    $where[]  = 'event_type = ?';
    $params[] = $filter_type;
    $types   .= 's';
}
if ($filter_severity !== '') {
    $where[]  = 'severity = ?';
    $params[] = $filter_severity;
    $types   .= 's';
}
if ($filter_ip !== '') {
    $where[]  = 'ip_address LIKE ?';
    $params[] = '%' . $filter_ip . '%';
    $types   .= 's';
}
if ($filter_date !== '') {
    $where[]  = 'DATE(created_at) = ?';
    $params[] = $filter_date;
    $types   .= 's';
}

$where_sql = implode(' AND ', $where);

// Total count
$count_sql  = "SELECT COUNT(*) FROM security_events WHERE $where_sql";
$count_stmt = $conn->prepare($count_sql);
if ($types) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$count_stmt->bind_result($total_rows);
$count_stmt->fetch();
$count_stmt->close();
$total_pages = max(1, ceil($total_rows / $per_page));
if ($page > $total_pages) { $page = $total_pages; $offset = ($page - 1) * $per_page; }

// Fetch events
$sql = "SELECT id, event_type, severity, ip_address, actor, details, created_at
        FROM security_events
        WHERE $where_sql
        ORDER BY created_at DESC, id DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$result = $stmt->get_result();
$events = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Summary cards (unfiltered, last 24h + all-time) ──────────────────────────
$summary = ['rate_limit' => 0, 'csrf_reject' => 0, 'callback_reject' => 0, 'auth_lockout' => 0];
$r = $conn->query("SELECT event_type, COUNT(*) c FROM security_events WHERE created_at >= NOW() - INTERVAL 24 HOUR GROUP BY event_type");
if ($r) {
    while ($row = $r->fetch_assoc()) {
        if (isset($summary[$row['event_type']])) $summary[$row['event_type']] = (int) $row['c'];
    }
    $r->free();
}

// Top offending IPs (last 7 days)
$top_ips = [];
$r = $conn->query("SELECT ip_address, COUNT(*) c, MAX(created_at) last_seen
                   FROM security_events
                   WHERE ip_address IS NOT NULL AND ip_address <> ''
                     AND created_at >= NOW() - INTERVAL 7 DAY
                   GROUP BY ip_address
                   ORDER BY c DESC
                   LIMIT 8");
if ($r) {
    while ($row = $r->fetch_assoc()) $top_ips[] = $row;
    $r->free();
}

// Distinct event types + severities for filter dropdowns
$event_types = [];
$r = $conn->query("SELECT DISTINCT event_type FROM security_events ORDER BY event_type");
if ($r) { while ($row = $r->fetch_assoc()) $event_types[] = $row['event_type']; $r->free(); }

$sev_badge = [
    'critical' => 'bg-danger',
    'warning'  => 'bg-warning text-dark',
    'info'     => 'bg-secondary',
];
?>

<div class="container-fluid px-4 py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="h4 fw-bold mb-1"><i class="fas fa-shield-halved me-2 text-danger"></i>Security Events</h1>
            <p class="text-muted mb-0 small">Rate-limit hits, CSRF rejections, webhook/callback failures and auth lockouts recorded by the security telemetry layer.</p>
        </div>
        <a href="slow_pages.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-gauge-high me-1"></i>Slow Pages</a>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <?php
        $cards = [
            'rate_limit'      => ['Rate Limit Hits', 'fa-stopwatch', 'text-warning'],
            'csrf_reject'     => ['CSRF Rejections', 'fa-shield', 'text-danger'],
            'callback_reject' => ['Callback Failures', 'fa-money-bill-transfer', 'text-primary'],
            'auth_lockout'    => ['Auth Lockouts', 'fa-user-lock', 'text-success'],
        ];
        foreach ($cards as $key => [$label, $icon, $color]):
        ?>
        <div class="col-6 col-xl-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="d-flex align-items-center justify-content-between">
                        <div>
                            <div class="small text-muted text-uppercase fw-semibold"><?php echo $label; ?></div>
                            <div class="fs-4 fw-bold"><?php echo number_format($summary[$key]); ?></div>
                            <div class="small text-muted">last 24h</div>
                        </div>
                        <i class="fas <?php echo $icon; ?> fs-2 <?php echo $color; ?> opacity-50"></i>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Top IPs + filters -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-globe me-2 text-secondary"></i>Top Offending IPs (7 days)</div>
                <div class="card-body p-0">
                    <?php if (!$top_ips): ?>
                        <p class="text-muted small p-3 mb-0">No events with an IP recorded in the last 7 days.</p>
                    <?php else: ?>
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase text-muted">
                                <tr><th>IP</th><th class="text-center">Events</th><th class="text-end">Last seen</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($top_ips as $tip): ?>
                                <tr>
                                    <td class="font-monospace small">
                                        <a href="?ip=<?php echo urlencode($tip['ip_address']); ?>"><?php echo htmlspecialchars($tip['ip_address']); ?></a>
                                    </td>
                                    <td class="text-center"><span class="badge bg-danger"><?php echo (int) $tip['c']; ?></span></td>
                                    <td class="text-end small text-muted"><?php echo date('d M H:i', strtotime($tip['last_seen'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="fas fa-filter me-2 text-secondary"></i>Filters</div>
                <div class="card-body">
                    <form method="get" class="row g-2">
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">Event type</label>
                            <select name="type" class="form-select form-select-sm">
                                <option value="">All types</option>
                                <?php foreach ($event_types as $et): ?>
                                    <option value="<?php echo htmlspecialchars($et); ?>" <?php echo $filter_type === $et ? 'selected' : ''; ?>><?php echo htmlspecialchars($et); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">Severity</label>
                            <select name="severity" class="form-select form-select-sm">
                                <option value="">All severities</option>
                                <?php foreach (['critical', 'warning', 'info'] as $s): ?>
                                    <option value="<?php echo $s; ?>" <?php echo $filter_severity === $s ? 'selected' : ''; ?>><?php echo ucfirst($s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">IP address</label>
                            <input type="text" name="ip" value="<?php echo htmlspecialchars($filter_ip); ?>" class="form-control form-control-sm" placeholder="e.g. 192.168.1.1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-muted mb-1">Date</label>
                            <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 d-flex gap-2">
                            <button class="btn btn-primary btn-sm" type="submit"><i class="fas fa-magnifying-glass me-1"></i>Apply</button>
                            <a href="security_events.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Events table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="fas fa-list me-2 text-secondary"></i>Events</span>
            <span class="small text-muted"><?php echo number_format($total_rows); ?> match(es)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light small text-uppercase text-muted">
                    <tr>
                        <th>Type</th>
                        <th>Severity</th>
                        <th>Details</th>
                        <th>IP</th>
                        <th>Actor</th>
                        <th class="text-end">When</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$events): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No security events match the current filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($events as $ev): ?>
                    <tr>
                        <td><span class="font-monospace small fw-semibold"><?php echo htmlspecialchars($ev['event_type']); ?></span></td>
                        <td><span class="badge <?php echo $sev_badge[$ev['severity']] ?? 'bg-secondary'; ?>"><?php echo htmlspecialchars($ev['severity']); ?></span></td>
                        <td class="small"><?php echo htmlspecialchars($ev['details'] ?? ''); ?></td>
                        <td class="font-monospace small"><?php echo htmlspecialchars($ev['ip_address'] ?? ''); ?></td>
                        <td class="small text-muted"><?php echo htmlspecialchars($ev['actor'] ?? ''); ?></td>
                        <td class="text-end small text-muted"><?php echo date('d M Y H:i:s', strtotime($ev['created_at'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total_pages > 1): ?>
        <div class="card-footer bg-white">
            <nav>
                <ul class="pagination pagination-sm justify-content-center mb-0">
                    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
                        <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php
                                $qs = http_build_query(array_filter([
                                    'type' => $filter_type, 'severity' => $filter_severity,
                                    'ip' => $filter_ip, 'date' => $filter_date,
                                ], fn($v) => $v !== ''));
                                echo $qs ? $qs . '&' : ''; ?>page=<?php echo $p; ?>"><?php echo $p; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>
