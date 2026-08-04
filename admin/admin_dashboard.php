<?php
// ============================================================
//  admin/admin_dashboard.php
//  Administrative console - club operations overview
// ============================================================
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once "../includes/feature_helpers.php";
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/mpesa.php'; // mpesa_callback_url_error() + payment constants

if (!function_exists('admin_scalar')) {
    function admin_scalar(mysqli $conn, string $sql, $default = 0)
    {
        if (!$result = $conn->query($sql)) {
            return $default;
        }
        $row = $result->fetch_row();
        $result->free();
        return $row ? $row[0] : $default;
    }
}

$has_memberships = db_table_exists($conn, 'member_memberships');
$has_leagues = db_table_exists($conn, 'leagues');

// ── Single consolidated stats query (1 round trip instead of 7) ──
$stats = [
    'members' => 0, 'pending_bookings' => 0, 'upcoming_bookings' => 0,
    'total_revenue' => 0, 'month_revenue' => 0, 'active_leagues' => 0, 'active_memberships' => 0,
];
$stat_subqueries = [
    "(SELECT COUNT(*) FROM members) AS members",
    "(SELECT COUNT(*) FROM bookings WHERE status = 'Pending') AS pending_bookings",
    "(SELECT COUNT(*) FROM bookings WHERE booking_date >= CURDATE() AND status IN ('Pending','Approved','Confirmed')) AS upcoming_bookings",
    "(SELECT COALESCE(SUM(amount), 0) FROM payments) AS total_revenue",
    "(SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month_revenue",
];
if ($has_leagues) $stat_subqueries[] = "(SELECT COUNT(*) FROM leagues WHERE status = 'Active') AS active_leagues";
if ($has_memberships) $stat_subqueries[] = "(SELECT COUNT(*) FROM member_memberships WHERE status = 'Active' AND end_date >= CURDATE()) AS active_memberships";
if ($stat_row = $conn->query("SELECT " . implode(",\n", $stat_subqueries))) {
    $row = $stat_row->fetch_assoc();
    $stat_row->free();
    foreach ($row as $k => $v) {
        if (array_key_exists($k, $stats)) $stats[$k] = $v;
    }
}

$show_default_password_warning = false;
if (isset($_SESSION["admin_id"])) {
    $stmt_pwd = $conn->prepare("SELECT password FROM admins WHERE admin_id = ?");
    if ($stmt_pwd) {
        $stmt_pwd->bind_param("i", $_SESSION["admin_id"]);
        $stmt_pwd->execute();
        $stmt_pwd->bind_result($admin_pwd_hash);
        if ($stmt_pwd->fetch()) {
            if ($admin_pwd_hash === '$2y$10$9r0GXFEDBp8KwQfbU12DDeD6AWv5LT6lipr7IqAIAlC4wIYVGdIzq') {
                $show_default_password_warning = true;
            }
        }
        $stmt_pwd->close();
    }
}

// ── MONTHLY REVENUE TREND (last 6 months) ─────────────────────────────
$monthly_revenue = [];
$mr_result = $conn->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS ym, COALESCE(SUM(amount), 0) AS total
                            FROM payments
                            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
                            GROUP BY ym ORDER BY ym");
$monthly_map = [];
if ($mr_result) {
    while ($row = $mr_result->fetch_assoc()) {
        $monthly_map[$row['ym']] = (float) $row['total'];
    }
    $mr_result->free();
}
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $monthly_revenue[] = ['ym' => $ym, 'total' => $monthly_map[$ym] ?? 0.0];
}
$rev_max = max(array_column($monthly_revenue, 'total'));
$rev_max = $rev_max > 0 ? $rev_max : 1;

// ── AI REVIEW STATS ────────────────────────────────────────────────────
$ai_pending_count = admin_scalar($conn, "SELECT COUNT(*) FROM bookings WHERE status = 'Pending'", 0);
$ai_last_cron_run = '';
$ai_cron_result = $conn->query("SELECT MAX(reviewed_at) AS last_time FROM ai_review_log WHERE admin_id = 0");
if ($ai_cron_result && $row = $ai_cron_result->fetch_assoc()) {
    $ai_last_cron_run = $row['last_time'] ?? '';
    $ai_cron_result->free();
}
$ai_review_count = admin_scalar($conn, "SELECT COUNT(*) FROM ai_review_log", 0);

$recent_bookings = [];
$sql = "SELECT b.booking_date, b.start_time, b.status,
               m.first_name, m.last_name,
               f.name AS facility_name,
               s.name AS sport_name
        FROM bookings b
        LEFT JOIN members m ON m.member_id = b.member_id
        LEFT JOIN facilities f ON f.facility_id = b.facility_id
        LEFT JOIN sports s ON s.sport_id = b.sport_id
        ORDER BY b.booking_date DESC, b.start_time DESC
        LIMIT 6";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $recent_bookings[] = $row;
    }
    $result->free();
}

$recent_payments = [];
$sql = "SELECT p.amount, p.payment_date, p.payment_method,
               m.first_name, m.last_name
        FROM payments p
        LEFT JOIN members m ON m.member_id = p.member_id
        ORDER BY p.payment_date DESC
        LIMIT 6";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $recent_payments[] = $row;
    }
    $result->free();
}

$popular_sports = [];
$sql = "SELECT s.name, COUNT(b.booking_id) AS bookings_count
        FROM sports s
        LEFT JOIN bookings b ON b.sport_id = s.sport_id
        GROUP BY s.sport_id, s.name
        ORDER BY bookings_count DESC, s.name
        LIMIT 8";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $popular_sports[] = $row;
    }
    $result->free();
}

// ── Today's schedule (all members' sessions) ────────────────────
$today_schedule = [];
$sql = "SELECT b.booking_id, b.start_time, b.end_time, b.status,
               m.first_name AS member_first, m.last_name AS member_last,
               s.name AS sport_name,
               f.name AS facility_name,
               COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'Not assigned') AS coach_name
        FROM bookings b
        LEFT JOIN members m ON b.member_id = m.member_id
        LEFT JOIN sports s ON b.sport_id = s.sport_id
        LEFT JOIN facilities f ON b.facility_id = f.facility_id
        LEFT JOIN coaches c ON b.coach_id = c.coach_id
        WHERE b.booking_date = CURDATE()
        ORDER BY b.start_time ASC";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $today_schedule[] = $row;
    }
    $result->free();
}

// ── Coach availability for today ────────────────────────────────
$today_dow = (int)date('w');
$coach_day_view = [];
$coach_has_avail = db_table_exists($conn, 'coach_availability');
if ($coach_has_avail) {
    $stmt = $conn->prepare("SELECT ca.*, c.first_name, c.last_name, c.specialization
        FROM coach_availability ca
        JOIN coaches c ON ca.coach_id = c.coach_id
        WHERE ca.day_of_week = ? AND ca.is_available = 1
        ORDER BY c.first_name, ca.start_time");
    $stmt->bind_param('i', $today_dow);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $coach_day_view[] = $row;
    }
    $stmt->close();
}

// Today's bookings grouped by coach_id for the "booked" indicators
$today_booked_coach_ids = [];
$r = $conn->query("SELECT DISTINCT coach_id FROM bookings WHERE booking_date = CURDATE() AND coach_id IS NOT NULL AND status NOT IN ('cancelled','rejected')");
if ($r) while ($row = $r->fetch_assoc()) $today_booked_coach_ids[(int)$row['coach_id']] = true;

// Count coaches scheduled today vs not
$coaches_today_count = 0;
$seen_coaches = [];
foreach ($coach_day_view as $c) {
    if (!isset($seen_coaches[$c['coach_id']])) {
        $seen_coaches[$c['coach_id']] = true;
        $coaches_today_count++;
    }
}

// Slow pages recorded in the last 7 days (performance monitor card)
$slow_page_count = 0;
if (db_table_exists($conn, 'page_timings')) {
    $sp = $conn->query("SELECT COUNT(*) FROM page_timings WHERE created_at >= NOW() - INTERVAL 7 DAY");
    if ($sp && $row = $sp->fetch_row()) { $slow_page_count = (int) $row[0]; }
    if ($sp) { $sp->free(); }
}

// ── PAYMENT HEALTH (payment-config checks + duplicate memberships) ──────
// Mirrors public/health.php's payment_config check so admins see payment
// risk at a glance without curling the health endpoint. NULL start/end
// dates are intentionally skipped by the overlap comparison (cannot prove
// an overlap without dates) — see manage_members.php for the same query.
$payment_health_problems = [];

$phMpesaCb = defined('MPESA_CALLBACK_URL') ? trim(MPESA_CALLBACK_URL) : '';
if ($phMpesaCb === '') {
    $payment_health_problems[] = 'MPESA_CALLBACK_URL is empty';
} elseif (function_exists('mpesa_callback_url_error')) {
    $phErr = mpesa_callback_url_error($phMpesaCb);
    if ($phErr !== null) $payment_health_problems[] = $phErr;
}
$phPaystackCb = defined('PAYSTACK_CALLBACK_URL') ? trim(PAYSTACK_CALLBACK_URL) : '';
if ($phPaystackCb === '' || preg_match('/your-ngrok-domain|example\.com|placeholder/i', $phPaystackCb)) {
    $payment_health_problems[] = 'PAYSTACK_CALLBACK_URL is empty or a placeholder domain';
}
if (!defined('MPESA_CONSUMER_KEY') || trim(MPESA_CONSUMER_KEY) === '' || MPESA_CONSUMER_KEY === 'test') {
    $payment_health_problems[] = 'MPESA_CONSUMER_KEY is not configured';
}
if (!defined('PAYSTACK_SECRET_KEY') || trim(PAYSTACK_SECRET_KEY) === '' || PAYSTACK_SECRET_KEY === 'sk_test_local') {
    $payment_health_problems[] = 'PAYSTACK_SECRET_KEY is not configured';
}

// Duplicate *overlapping* active memberships (same member + plan)
$dup_memberships = find_duplicate_memberships($conn);
$dup_member_ids  = array_unique(array_column($dup_memberships, 'member_id'));
$dup_member_count = count($dup_member_ids);

// Last payment-health alert sent (cron throttle window is 24h)
$payment_health_alert_at = '';
if (db_table_exists($conn, 'security_alert_log')) {
    $ph_r = $conn->query("SELECT MAX(sent_at) FROM security_alert_log WHERE alert_type = 'payment_health'");
    if ($ph_r && $row = $ph_r->fetch_row()) { $payment_health_alert_at = $row[0] ?? ''; }
    if ($ph_r) { $ph_r->free(); }
}

$conn->close();
?>

<div class="asc-dash">
    <!-- Security warning -->
    <?php if ($show_default_password_warning): ?>
    <div class="alert alert-danger border-0 shadow-sm rounded-3 d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4" role="alert">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-shield-halved"></i>
            <span class="fw-bold">Security warning: the default admin password is still in use.</span>
        </div>
        <a href="admin_profile.php" class="btn btn-sm btn-danger fw-semibold text-white text-decoration-none">
            <i class="fas fa-key me-1"></i> Update password
        </a>
    </div>
    <?php endif; ?>

    <!-- Page header + quick actions -->
    <?php
    $hour = (int) date('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
    // Display name: prefer the stored session username, else derive one from the email local part
    $admin_email = $_SESSION['admin_email'] ?? '';
    $admin_local = trim((string) str_replace(['.', '_', '-'], ' ', explode('@', $admin_email)[0] ?? ''));
    $admin_name = $_SESSION['admin_username'] ?? ($admin_local !== '' ? ucwords($admin_local) : 'Administrator');
    ?>
    <div class="asc-page-head">
        <div>
            <h1 class="asc-page-title">Administrative Console</h1>
            <p class="asc-page-sub"><?php echo $greeting; ?>, <span class="asc-accent"><?php echo e($admin_name); ?></span> - <?php echo e(date('l, j F Y')); ?></p>
        </div>
        <div class="asc-page-actions">
            <a href="manage_bookings.php" class="asc-btn asc-btn-ghost">
                <i class="fas fa-calendar-check"></i> Review Bookings
            </a>
            <a href="payments_overview.php" class="asc-btn asc-btn-outline">
                <i class="fas fa-arrow-right-arrow-left"></i> Payments Overview
            </a>
            <a href="manage_payments.php" class="asc-btn asc-btn-primary">
                <i class="fas fa-credit-card"></i> Manage Payments
            </a>
        </div>
    </div>

    <!-- AI review status -->
    <div class="asc-card mb-4">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3 py-3 px-4">
            <div class="d-flex align-items-center gap-3">
                <div class="asc-stat-icon asc-icon-brand">
                    <i class="fas fa-robot"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold asc-text-ink">AI Booking Review</h5>
                    <p class="mb-0 small asc-text-muted">
                        <?php echo (int) $ai_review_count; ?> decisions logged
                        <?php if ($ai_pending_count > 0): ?>
                            <span class="asc-badge asc-badge-warning ms-1"><?php echo (int) $ai_pending_count; ?> pending</span>
                        <?php endif; ?>
                        <?php if ($ai_last_cron_run): ?>
                            <span class="text-muted ms-1">Last cron: <?php echo date('d M H:i', strtotime($ai_last_cron_run)); ?></span>
                        <?php else: ?>
                            <span class="text-muted ms-1">Cron not yet run</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="cron_ai_settings.php" class="asc-btn asc-btn-ghost btn-sm">
                    <i class="fas fa-clock"></i> Cron Settings
                </a>
                <a href="ai_review_log.php" class="asc-btn asc-btn-ghost btn-sm">
                    <i class="fas fa-history"></i> Review Log
                </a>
                <a href="manage_bookings.php" class="asc-btn asc-btn-primary btn-sm">
                    <i class="fas fa-arrow-right"></i> Run AI Review
                </a>
            </div>
        </div>
    </div>

    <!-- Performance monitor quick link -->
    <div class="asc-card mb-4">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3 py-3 px-4">
            <div class="d-flex align-items-center gap-3">
                <div class="asc-stat-icon asc-icon-info"><i class="fas fa-gauge-high"></i></div>
                <div>
                    <h5 class="mb-0 fw-bold asc-text-ink">Performance Monitor</h5>
                    <p class="mb-0 small asc-text-muted">
                        <?php echo (int) $slow_page_count; ?> slow page<?php echo $slow_page_count === 1 ? '' : 's'; ?> recorded in the last 7 days
                        <span class="text-muted ms-1">(&gt;100 ms, from the request profiler)</span>
                    </p>
                </div>
            </div>
            <a href="slow_pages.php" class="asc-btn asc-btn-ghost btn-sm">
                <i class="fas fa-chart-line"></i> View Slow Pages
            </a>
        </div>
    </div>

    <!-- Payment health status -->
    <div class="asc-card mb-4">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-3 py-3 px-4">
            <div class="d-flex align-items-center gap-3">
                <div class="asc-stat-icon <?php echo (empty($payment_health_problems) && $dup_member_count === 0) ? 'asc-icon-success' : 'asc-icon-warning'; ?>">
                    <i class="fas fa-shield-heart"></i>
                </div>
                <div>
                    <h5 class="mb-0 fw-bold asc-text-ink">Payment Health</h5>
                    <p class="mb-0 small asc-text-muted">
                        <?php if (empty($payment_health_problems) && $dup_member_count === 0): ?>
                            All payment checks passing
                            <?php if ($payment_health_alert_at): ?>
                                <span class="text-muted ms-1">Last alert: <?php echo date('d M H:i', strtotime($payment_health_alert_at)); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php foreach ($payment_health_problems as $phProblem): ?>
                                <span class="asc-badge asc-badge-danger me-1"><?php echo e($phProblem); ?></span>
                            <?php endforeach; ?>
                            <?php if ($dup_member_count > 0): ?>
                                <span class="asc-badge asc-badge-warning me-1"><?php echo (int) $dup_member_count; ?> member<?php echo $dup_member_count === 1 ? '' : 's'; ?> with overlapping active membership<?php echo $dup_member_count === 1 ? '' : 's'; ?></span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a href="manage_members.php" class="asc-btn asc-btn-ghost btn-sm">
                    <i class="fas fa-users"></i> Members
                </a>
                <a href="../public/health.php" class="asc-btn asc-btn-ghost btn-sm">
                    <i class="fas fa-stethoscope"></i> Health Endpoint
                </a>
            </div>
        </div>
    </div>

    <!-- Metric cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top">
                    <span class="asc-stat-icon asc-icon-brand"><i class="fas fa-users"></i></span>
                </div>
                <p class="asc-stat-label">Members</p>
                <p class="asc-stat-value"><?php echo number_format((int) $stats['members']); ?></p>
                <p class="asc-stat-note">Registered members</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top">
                    <span class="asc-stat-icon asc-icon-warning"><i class="fas fa-hourglass-half"></i></span>
                </div>
                <p class="asc-stat-label">Pending Bookings</p>
                <p class="asc-stat-value"><?php echo number_format((int) $stats['pending_bookings']); ?></p>
                <p class="asc-stat-note">Awaiting approval</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top">
                    <span class="asc-stat-icon asc-icon-success"><i class="fas fa-chart-line"></i></span>
                </div>
                <p class="asc-stat-label">Revenue This Month</p>
                <p class="asc-stat-value">KES <?php echo number_format((float) $stats['month_revenue'], 0); ?></p>
                <p class="asc-stat-note">Month to date</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top">
                    <span class="asc-stat-icon asc-icon-info"><i class="fas fa-id-card"></i></span>
                </div>
                <p class="asc-stat-label">Active Memberships</p>
                <p class="asc-stat-value"><?php echo number_format((int) $stats['active_memberships']); ?></p>
                <p class="asc-stat-note">Current subscriptions</p>
            </div>
        </div>
    </div>

    <!-- Operations & League Engine -->
    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="asc-card h-100">
                <div class="asc-card-head">
                    <h4 class="asc-card-title">Core Operations</h4>
                    <i class="fas fa-sliders text-muted"></i>
                </div>
                <ul class="asc-ops">
                    <li>
                        <a href="manage_members.php">
                            <i class="fas fa-id-badge"></i>
                            <div>
                                <div class="asc-ops-name">Members</div>
                                <div class="asc-ops-desc">Manage member profiles</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_bookings.php">
                            <i class="fas fa-calendar-check"></i>
                            <div>
                                <div class="asc-ops-name">Reservations</div>
                                <div class="asc-ops-desc">Approve and manage bookings</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_facilities.php">
                            <i class="fas fa-building"></i>
                            <div>
                                <div class="asc-ops-name">Facilities</div>
                                <div class="asc-ops-desc">Manage club facilities</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_coaches.php">
                            <i class="fas fa-whistle"></i>
                            <div>
                                <div class="asc-ops-name">Coaches</div>
                                <div class="asc-ops-desc">Manage coaching staff</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a class="asc-ops-featured" href="ai_smart_scheduling.php">
                            <i class="fas fa-wand-magic-sparkles"></i>
                            <div>
                                <div class="asc-ops-name">AI Smart Scheduling</div>
                                <div class="asc-ops-desc">Auto-assign coaches</div>
                            </div>
                            <span class="asc-badge asc-badge-brand">AI</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="asc-card h-100">
                <div class="asc-card-head">
                    <h4 class="asc-card-title">League Engine</h4>
                    <i class="fas fa-trophy text-muted"></i>
                </div>
                <ul class="asc-ops">
                    <li>
                        <a href="manage_leagues.php">
                            <i class="fas fa-medal"></i>
                            <div>
                                <div class="asc-ops-name">Active Leagues</div>
                                <div class="asc-ops-desc">Manage leagues and seasons</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_fixtures.php">
                            <i class="fas fa-calendar-alt"></i>
                            <div>
                                <div class="asc-ops-name">Match Fixtures</div>
                                <div class="asc-ops-desc">Schedule matches</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_standings.php">
                            <i class="fas fa-list-ol"></i>
                            <div>
                                <div class="asc-ops-name">Table Standings</div>
                                <div class="asc-ops-desc">Update leaderboards</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_sports.php">
                            <i class="fas fa-futbol"></i>
                            <div>
                                <div class="asc-ops-name">Tracked Sports</div>
                                <div class="asc-ops-desc">Sports disciplines</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Financial snapshot -->
    <div class="asc-dark-panel mb-4">
        <div class="row g-4">
            <div class="col-sm-6 col-xl-3">
                <p class="asc-kpi-label">Total Revenue</p>
                <p class="asc-kpi-value">KES <?php echo number_format((float) $stats['total_revenue'], 0); ?></p>
                <p class="asc-kpi-note">Settled to date</p>
            </div>
            <div class="col-sm-6 col-xl-3">
                <p class="asc-kpi-label">This Month</p>
                <p class="asc-kpi-value asc-text-accent">KES <?php echo number_format((float) $stats['month_revenue'], 0); ?></p>
                <p class="asc-kpi-note">Settlements processing</p>
            </div>
            <div class="col-sm-6 col-xl-3">
                <p class="asc-kpi-label">Upcoming Bookings</p>
                <p class="asc-kpi-value"><?php echo number_format((int) $stats['upcoming_bookings']); ?></p>
                <p class="asc-kpi-note">Scheduled ahead</p>
            </div>
            <div class="col-sm-6 col-xl-3">
                <p class="asc-kpi-label">Active Leagues</p>
                <p class="asc-kpi-value"><?php echo number_format((int) $stats['active_leagues']); ?></p>
                <p class="asc-kpi-note">In-season play</p>
            </div>
        </div>
        <div class="asc-rev-chart">
            <?php foreach ($monthly_revenue as $mr):
                $pct = round(($mr['total'] / $rev_max) * 100);
                $short = date('M', strtotime($mr['ym']));
            ?>
            <div class="asc-rev-col" title="<?php echo e(date('M Y', strtotime($mr['ym']))); ?>: KES <?php echo number_format($mr['total'], 0); ?>">
                <div class="asc-rev-bar-track">
                    <div class="asc-rev-bar-fill" style="height:<?php echo (int) $pct; ?>%;"></div>
                </div>
                <span class="asc-rev-label"><?php echo e($short); ?></span>
                <span class="asc-rev-value"><?php echo number_format($mr['total'], 0); ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Recent bookings & payments -->
    <div class="row g-3 mb-4">
        <div class="col-xl-6">
            <div class="asc-card h-100">
                <div class="asc-card-head">
                    <h4 class="asc-card-title">Recent Bookings</h4>
                    <a href="manage_bookings.php" class="asc-card-link">View all</a>
                </div>
                <div class="asc-table-wrap">
                    <table class="asc-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Sport &amp; Facility</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_bookings)): ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="asc-empty"><i class="fas fa-calendar-xmark"></i><p>No bookings yet.</p></div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($recent_bookings as $booking): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e($booking['first_name'] . ' ' . $booking['last_name']); ?></td>
                                    <td class="text-muted"><?php echo e($booking['sport_name']); ?> / <?php echo e($booking['facility_name']); ?></td>
                                    <td class="text-muted"><?php echo e(date('d M', strtotime($booking['booking_date'])) . ' @ ' . date('H:i', strtotime($booking['start_time']))); ?></td>
                        <td>
                            <?php
                            $bStatus = strtolower($booking['status']);
                            $bClass = in_array($bStatus, ['completed', 'approved', 'confirmed', 'paid']) ? 'asc-badge-success'
                                : ($bStatus === 'pending' ? 'asc-badge-warning'
                                : (in_array($bStatus, ['cancelled', 'rejected', 'refunded', 'failed', 'declined']) ? 'asc-badge-danger'
                                : 'asc-badge-neutral'));
                            ?>
                            <span class="asc-badge <?php echo $bClass; ?>">
                                <?php echo e($booking['status']); ?>
                            </span>
                        </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="asc-card h-100">
                <div class="asc-card-head">
                    <h4 class="asc-card-title">Recent Payments</h4>
                    <a href="manage_payments.php" class="asc-card-link">View all</a>
                </div>
                <div class="asc-table-wrap">
                    <table class="asc-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_payments)): ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="asc-empty"><i class="fas fa-wallet"></i><p>No payments yet.</p></div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($recent_payments as $payment): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                    <td class="fw-bold asc-text-success">KES <?php echo number_format((float) $payment['amount'], 0); ?></td>
                                    <td class="text-muted"><?php echo e($payment['payment_method']); ?></td>
                                    <td class="text-muted"><?php echo e(date('M d, H:i', strtotime($payment['payment_date']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's schedule -->
    <div class="asc-card mb-4">
        <div class="asc-card-head">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-calendar-day asc-card-head-icon"></i>
                <h4 class="asc-card-title mb-0">Today's Schedule</h4>
            </div>
            <span class="text-muted small"><?php echo date('l, j F Y'); ?></span>
        </div>
        <div class="asc-table-wrap">
            <table class="asc-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Member</th>
                        <th>Sport</th>
                        <th>Facility</th>
                        <th>Coach</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($today_schedule)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="asc-empty"><i class="fas fa-calendar-xmark"></i><p>No sessions scheduled for today.</p></div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($today_schedule as $s):
                            $s_lower = strtolower($s['status']);
                            $badge = in_array($s_lower, ['completed','approved','confirmed','paid']) ? 'asc-badge-success'
                                : ($s_lower === 'pending' ? 'asc-badge-warning'
                                : (in_array($s_lower, ['cancelled','rejected']) ? 'asc-badge-danger'
                                : 'asc-badge-neutral'));
                        ?>
                        <tr>
                            <td class="mono"><?php echo e(substr($s['start_time'], 0, 5) . ' - ' . substr($s['end_time'], 0, 5)); ?></td>
                            <td class="fw-semibold"><?php echo e($s['member_first'] . ' ' . $s['member_last']); ?></td>
                            <td><span class="asc-badge asc-badge-neutral"><?php echo e($s['sport_name']); ?></span></td>
                            <td class="text-muted"><?php echo e($s['facility_name']); ?></td>
                            <td class="text-muted"><?php echo e($s['coach_name']); ?></td>
                            <td><span class="asc-badge <?php echo $badge; ?>"><?php echo e($s['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Coach availability today -->
    <?php if ($coach_has_avail): ?>
    <div class="asc-card mb-4">
        <div class="asc-card-head">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-whistle asc-card-head-icon"></i>
                <h4 class="asc-card-title mb-0">Coach Availability Today</h4>
            </div>
            <span class="text-muted small"><?php echo (int) $coaches_today_count; ?> coaches scheduled</span>
        </div>
        <div class="asc-table-wrap">
            <table class="asc-table">
                <thead>
                    <tr>
                        <th>Coach</th>
                        <th>Specialization</th>
                        <th>Schedule</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $displayed_coaches = [];
                    foreach ($coach_day_view as $c):
                        $cid = $c['coach_id'];
                        $is_booked = isset($today_booked_coach_ids[$cid]);
                        if (isset($displayed_coaches[$cid])) {
                            $is_first = false;
                        } else {
                            $displayed_coaches[$cid] = true;
                            $is_first = true;
                        }
                    ?>
                    <tr>
                        <td class="fw-semibold">
                            <?php echo e($c['first_name'] . ' ' . $c['last_name']); ?>
                            <?php if ($is_first && $is_booked): ?>
                                <span class="asc-badge asc-badge-info ms-1">Booked</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted"><?php echo e($c['specialization']); ?></td>
                        <td class="mono"><?php echo e(substr($c['start_time'], 0, 5) . ' - ' . substr($c['end_time'], 0, 5)); ?></td>
                        <td>
                            <span class="asc-badge <?php echo $is_booked ? 'asc-badge-warning' : 'asc-badge-success'; ?>">
                                <?php echo $is_booked ? 'Partially Booked' : 'Available'; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($coach_day_view)): ?>
                    <tr>
                        <td colspan="4">
                            <div class="asc-empty"><i class="fas fa-calendar-xmark"></i><p>No coaches scheduled for today.</p></div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Resource utilization -->
    <div class="asc-card mb-2">
        <div class="asc-card-head">
            <h4 class="asc-card-title">Most Booked Sports</h4>
        </div>
        <div class="card-body p-4">
            <?php if (empty($popular_sports)): ?>
                <div class="asc-empty"><i class="fas fa-chart-simple"></i><p>No utilization data available.</p></div>
            <?php else: ?>
                <?php
                $max_count = 0;
                foreach ($popular_sports as $sport) {
                    if ($sport['bookings_count'] > $max_count) $max_count = $sport['bookings_count'];
                }
                ?>
                <div class="row g-3">
                    <?php foreach ($popular_sports as $sport):
                        $percentage = ($max_count > 0) ? round($sport['bookings_count'] / $max_count * 100) : 0;
                    ?>
                    <div class="col-6 col-md-3 col-xl-3">
                        <div class="asc-sport-tile">
                            <p class="asc-sport-name" title="<?php echo e($sport['name']); ?>"><?php echo e($sport['name']); ?></p>
                            <div class="asc-progress-track mb-2"><div class="asc-progress-fill" style="width:<?php echo $percentage; ?>%;"></div></div>
                            <p class="asc-sport-count"><?php echo number_format((int) $sport['bookings_count']); ?> sessions</p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once("../includes/footer.php"); ?>
