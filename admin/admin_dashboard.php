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

// AI decision accuracy (share of logged decisions that were APPROVE)
$ai_accuracy = 0;
if ($ai_review_count > 0) {
    $ai_approves = admin_scalar($conn, "SELECT COUNT(*) FROM ai_review_log WHERE decision = 'APPROVE'", 0);
    $ai_accuracy = (int) round(($ai_approves / $ai_review_count) * 100);
}

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
$sql = "SELECT p.amount, p.payment_date, p.payment_method, p.payment_status, p.description,
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
$total_bookings_all = admin_scalar($conn, "SELECT COUNT(*) FROM bookings", 0);
$total_bookings_all = $total_bookings_all > 0 ? $total_bookings_all : 1;

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

// Coaches NOT scheduled today — shown as "Off Duty" (grey) rows
$coach_off_duty = [];
if ($coach_has_avail) {
    $stmt = $conn->prepare("SELECT c.first_name, c.last_name, c.specialization
        FROM coaches c
        LEFT JOIN coach_availability ca
               ON ca.coach_id = c.coach_id AND ca.day_of_week = ? AND ca.is_available = 1
        WHERE ca.coach_id IS NULL
        ORDER BY c.first_name
        LIMIT 3");
    $stmt->bind_param('i', $today_dow);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $coach_off_duty[] = $row;
    }
    $stmt->close();
}

// Slow pages recorded in the last 7 days (performance monitor card)
$slow_page_count = 0;
$avg_load_ms = 0;
if (db_table_exists($conn, 'page_timings')) {
    $sp = $conn->query("SELECT COUNT(*) FROM page_timings WHERE created_at >= NOW() - INTERVAL 7 DAY");
    if ($sp && $row = $sp->fetch_row()) { $slow_page_count = (int) $row[0]; }
    if ($sp) { $sp->free(); }
    $avg_load_ms = (int) round((float) admin_scalar($conn, "SELECT COALESCE(AVG(duration_ms), 0) FROM page_timings WHERE created_at >= NOW() - INTERVAL 7 DAY", 0));
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
if ($phPaystackCb === '' || preg_match('/your-ngrok-domain|example\\.com|placeholder/i', $phPaystackCb)) {
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

$payment_health_ok = empty($payment_health_problems) && $dup_member_count === 0;

// ── Derived headline stats (reference dashboard copy) ──────────────────
// Member growth this month
$members_this_month = admin_scalar($conn, "SELECT COUNT(*) FROM members WHERE date_joined >= DATE_FORMAT(CURDATE(), '%Y-%m-01')", 0);

// Revenue growth vs last month
$last_month_revenue = admin_scalar($conn, "SELECT COALESCE(SUM(amount), 0) FROM payments
    WHERE payment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
      AND payment_date <  DATE_FORMAT(CURDATE(), '%Y-%m-01')", 0);
$rev_growth_pct = null;
if ($last_month_revenue > 0) {
    $rev_growth_pct = (int) round((($stats['month_revenue'] - $last_month_revenue) / $last_month_revenue) * 100);
}

// Membership utilization vs member base
$utilization_pct = ($stats['members'] > 0 && $has_memberships)
    ? (int) round($stats['active_memberships'] / $stats['members'] * 100) : 0;

// YTD revenue + outstanding (unsettled) invoices
$ytd_revenue = admin_scalar($conn, "SELECT COALESCE(SUM(amount), 0) FROM payments WHERE YEAR(payment_date) = YEAR(CURDATE())", 0);
$outstanding_invoices = admin_scalar($conn, "SELECT COALESCE(SUM(amount), 0) FROM payments
    WHERE LOWER(payment_status) NOT IN ('paid','completed','success','refunded')", 0);

// Descriptor counts used by the operations lists
$teams_count     = admin_scalar($conn, "SELECT COUNT(*) FROM teams", 0);
$facilities_count = admin_scalar($conn, "SELECT COUNT(*) FROM facilities", 0);
$coaches_total   = admin_scalar($conn, "SELECT COUNT(*) FROM coaches", 0);

// Sport → icon mapping for the utilization tiles
function asc_sport_icon(string $name): string
{
    $n = strtolower($name);
    if (strpos($n, 'tennis') !== false || strpos($n, 'badminton') !== false) return 'fa-table-tennis-paddle-ball';
    if (strpos($n, 'basketball') !== false) return 'fa-basketball';
    if (strpos($n, 'swim') !== false) return 'fa-person-swimming';
    if (strpos($n, 'gym') !== false || strpos($n, 'fitness') !== false) return 'fa-dumbbell';
    if (strpos($n, 'football') !== false || strpos($n, 'soccer') !== false) return 'fa-futbol';
    if (strpos($n, 'rugby') !== false) return 'fa-football';
    if (strpos($n, 'hockey') !== false) return 'fa-hockey-stick';
    if (strpos($n, 'volley') !== false) return 'fa-volleyball';
    if (strpos($n, 'chess') !== false) return 'fa-chess-knight';
    if (strpos($n, 'horse') !== false || strpos($n, 'rid') !== false) return 'fa-horse';
    return 'fa-medal';
}

// Compact currency label for the chart ("50k" / "52.8k")
function asc_compact_kes(float $amount): string
{
    if ($amount >= 1000) {
        $k = $amount / 1000;
        return rtrim(rtrim(number_format($k, 1, '.', ''), '0'), '.') . 'k';
    }
    return number_format($amount, 0);
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
            <h1 class="asc-page-title">Administrative Console Overview</h1>
            <p class="asc-page-sub"><?php echo $greeting; ?>, <span class="asc-accent"><?php echo e($admin_name); ?></span></p>
            <p class="asc-page-sub-date"><i class="fas fa-calendar-day"></i> <?php echo e(date('F j, Y')); ?></p>
        </div>
        <div class="asc-page-actions">
            <a href="manage_bookings.php" class="asc-btn asc-btn-blue-soft">
                <i class="fas fa-calendar-check"></i> Review Bookings
            </a>
            <a href="payments_overview.php" class="asc-btn asc-btn-blue">
                <i class="fas fa-arrow-right-arrow-left"></i> Payments Overview
            </a>
            <a href="manage_payments.php" class="asc-btn asc-btn-blue-deep">
                <i class="fas fa-credit-card"></i> Manage Payments
            </a>
        </div>
    </div>

    <!-- Status cards: AI review / performance / payment health -->
    <div class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="asc-status-card">
                <div class="asc-status-icon asc-icon-brand"><i class="fas fa-robot"></i></div>
                <div class="asc-status-body">
                    <h5 class="asc-status-title">AI Booking Review</h5>
                    <p class="asc-status-sub">
                        <strong><?php echo (int) $ai_accuracy; ?>% Accuracy</strong>, <?php echo (int) $ai_pending_count; ?> Flagged
                        <?php if ($ai_review_count > 0): ?>
                            <span class="asc-tbl-sub">· <?php echo (int) $ai_review_count; ?> decisions logged</span>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="manage_bookings.php" class="asc-btn asc-btn-primary asc-status-action">
                    <i class="fas fa-arrow-right"></i> Run AI
                </a>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="asc-status-card">
                <div class="asc-status-icon asc-icon-info"><i class="fas fa-gauge-high"></i></div>
                <div class="asc-status-body">
                    <h5 class="asc-status-title">Performance Monitor</h5>
                    <p class="asc-status-sub">
                        <?php if ($slow_page_count > 0 || $avg_load_ms > 0): ?>
                            Avg Slow Load <strong><?php echo (int) $avg_load_ms; ?>ms</strong>, <?php echo (int) $slow_page_count; ?> Slow Page<?php echo $slow_page_count === 1 ? '' : 's'; ?>
                        <?php else: ?>
                            No slow pages recorded in the last 7 days
                        <?php endif; ?>
                    </p>
                </div>
                <a href="slow_pages.php" class="asc-status-pill <?php echo $slow_page_count > 0 ? 'pill-warning' : 'pill-ok'; ?>">
                    <span class="dot"></span> Check
                </a>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="asc-status-card">
                <div class="asc-status-icon <?php echo $payment_health_ok ? 'asc-icon-success' : 'asc-icon-warning'; ?>">
                    <i class="fas fa-shield-heart"></i>
                </div>
                <div class="asc-status-body">
                    <h5 class="asc-status-title">Payment Health</h5>
                    <p class="asc-status-sub">
                        <?php if ($payment_health_ok): ?>
                            All Systems Configured, No Errors
                            <?php if ($payment_health_alert_at): ?>
                                <span class="asc-tbl-sub">· Last alert: <?php echo date('d M H:i', strtotime($payment_health_alert_at)); ?></span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?php foreach ($payment_health_problems as $phProblem): ?>
                                <span class="asc-badge asc-badge-danger me-1"><?php echo e($phProblem); ?></span>
                            <?php endforeach; ?>
                            <?php if ($dup_member_count > 0): ?>
                                <span class="asc-badge asc-badge-warning me-1"><?php echo (int) $dup_member_count; ?> member<?php echo $dup_member_count === 1 ? '' : 's'; ?> with overlapping memberships</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="manage_payments.php" class="asc-status-pill <?php echo $payment_health_ok ? 'pill-secure' : 'pill-warning'; ?>">
                    <span class="dot"></span> <?php echo $payment_health_ok ? 'Secure' : 'Attention'; ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Metric cards -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top">
                    <span class="asc-stat-label">Total Members</span>
                    <span class="asc-stat-icon asc-icon-success"><i class="fas fa-chart-line"></i></span>
                </div>
                <p class="asc-stat-value"><?php echo number_format((int) $stats['members']); ?></p>
                <p class="asc-stat-note asc-note-up"><i class="fas fa-arrow-up"></i> +<?php echo number_format((int) $members_this_month); ?> This Month</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card asc-stat-gold">
                <div class="asc-stat-top">
                    <span class="asc-stat-label">Pending Bookings</span>
                    <span class="asc-stat-icon asc-icon-glass"><i class="fas fa-hourglass-half"></i></span>
                </div>
                <p class="asc-stat-value"><?php echo number_format((int) $stats['pending_bookings']); ?></p>
                <p class="asc-stat-note">Awaiting approval</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card asc-stat-green">
                <div class="asc-stat-top">
                    <span class="asc-stat-label">Revenue This Month</span>
                    <span class="asc-stat-icon asc-icon-glass"><i class="fas fa-coins"></i></span>
                </div>
                <p class="asc-stat-value">KES <?php echo number_format((float) $stats['month_revenue'], 0); ?></p>
                <p class="asc-stat-note">
                    <?php if ($rev_growth_pct !== null): ?>
                        <i class="fas fa-arrow-up"></i> +<?php echo (int) $rev_growth_pct; ?>% from last month
                    <?php else: ?>
                        Month to date
                    <?php endif; ?>
                </p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card asc-stat-blue">
                <div class="asc-stat-top">
                    <span class="asc-stat-label">Active Memberships</span>
                    <span class="asc-stat-icon asc-icon-glass"><i class="fas fa-handshake"></i></span>
                </div>
                <p class="asc-stat-value"><?php echo number_format((int) $stats['active_memberships']); ?></p>
                <p class="asc-stat-note"><?php echo (int) $utilization_pct; ?>% Utilization</p>
            </div>
        </div>
    </div>

    <!-- Core operations & league engine (left) / recent activity (right) -->
    <div class="row g-3 mb-4">
        <div class="col-xl-6 d-flex flex-column">
            <div class="asc-card flex-grow-1">
                <div class="asc-card-head">
                    <h4 class="asc-card-title">Core Operations</h4>
                    <i class="fas fa-sliders text-muted"></i>
                </div>
                <ul class="asc-ops">
                    <li>
                        <a href="manage_members.php">
                            <i class="fas fa-user"></i>
                            <div>
                                <div class="asc-ops-name">Member Profiles</div>
                                <div class="asc-ops-desc">Manage <?php echo number_format((int) $stats['members']); ?> profiles</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_bookings.php">
                            <i class="fas fa-calendar-check"></i>
                            <div>
                                <div class="asc-ops-name">Reservations</div>
                                <div class="asc-ops-desc">View <?php echo number_format((int) $stats['upcoming_bookings']); ?> active bookings</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_facilities.php">
                            <i class="fas fa-building"></i>
                            <div>
                                <div class="asc-ops-name">Facilities</div>
                                <div class="asc-ops-desc">Configure <?php echo (int) $facilities_count; ?> resources</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_coaches.php">
                            <i class="fas fa-whistle"></i>
                            <div>
                                <div class="asc-ops-name">Coaches</div>
                                <div class="asc-ops-desc">Manage <?php echo (int) $coaches_total; ?> coaching staff</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a class="asc-ops-featured" href="ai_smart_scheduling.php">
                            <i class="fas fa-brain"></i>
                            <div>
                                <div class="asc-ops-name">AI Smart Scheduling</div>
                                <div class="asc-ops-desc">Optimize facility usage</div>
                            </div>
                            <span class="asc-badge asc-badge-brand">AI</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="asc-card mt-3">
                <div class="asc-card-head">
                    <h4 class="asc-card-title">League Engine</h4>
                    <i class="fas fa-trophy text-muted"></i>
                </div>
                <ul class="asc-ops">
                    <li>
                        <a href="manage_leagues.php">
                            <i class="fas fa-trophy"></i>
                            <div>
                                <div class="asc-ops-name">Leagues</div>
                                <div class="asc-ops-desc">Manage <?php echo (int) $stats['active_leagues']; ?> active leagues</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_leagues.php">
                            <i class="fas fa-users"></i>
                            <div>
                                <div class="asc-ops-name">Teams &amp; Rosters</div>
                                <div class="asc-ops-desc">Oversee <?php echo number_format((int) $teams_count); ?> teams</div>
                            </div>
                            <span class="asc-ops-meta"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </li>
                    <li>
                        <a href="manage_fixtures.php">
                            <i class="fas fa-clipboard-list"></i>
                            <div>
                                <div class="asc-ops-name">Fixtures &amp; Results</div>
                                <div class="asc-ops-desc">Update match outcomes</div>
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
                </ul>
            </div>
        </div>

        <div class="col-xl-6 d-flex flex-column">
            <div class="asc-card flex-grow-1">
                <div class="asc-card-head">
                    <h4 class="asc-card-title">Recent Bookings</h4>
                    <a href="manage_bookings.php" class="asc-card-link">View all</a>
                </div>
                <div class="asc-table-wrap">
                    <table class="asc-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Facility</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_bookings)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="asc-empty"><i class="fas fa-calendar-xmark"></i><p>No bookings yet.</p></div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($recent_bookings as $booking): ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e($booking['first_name'] . ' ' . $booking['last_name']); ?></td>
                                    <td>
                                        <?php echo e($booking['facility_name']); ?>
                                        <?php if (!empty($booking['sport_name'])): ?>
                                            <span class="asc-tbl-sub"><?php echo e($booking['sport_name']); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="mono"><?php echo e(date('d/m/y', strtotime($booking['booking_date']))); ?></td>
                                    <td class="mono"><?php echo e(date('g:i A', strtotime($booking['start_time']))); ?></td>
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

            <div class="asc-card mt-3">
                <div class="asc-card-head">
                    <h4 class="asc-card-title">Recent Payments</h4>
                    <a href="manage_payments.php" class="asc-card-link">View all</a>
                </div>
                <div class="asc-table-wrap">
                    <table class="asc-table">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_payments)): ?>
                                <tr>
                                    <td colspan="5">
                                        <div class="asc-empty"><i class="fas fa-wallet"></i><p>No payments yet.</p></div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($recent_payments as $payment):
                                $ps = strtolower($payment['payment_status'] ?? 'paid');
                                if (in_array($ps, ['paid', 'completed', 'success', 'processed'])) {
                                    $psLabel = 'Processed'; $psClass = 'asc-badge-success';
                                } elseif ($ps === 'pending') {
                                    $psLabel = 'Pending'; $psClass = 'asc-badge-warning';
                                } elseif (in_array($ps, ['failed', 'declined', 'rejected'])) {
                                    $psLabel = 'Failed'; $psClass = 'asc-badge-danger';
                                } elseif ($ps === 'refunded') {
                                    $psLabel = 'Refunded'; $psClass = 'asc-badge-neutral';
                                } else {
                                    $psLabel = $payment['payment_status'] ? ucfirst(strtolower($payment['payment_status'])) : 'Processed';
                                    $psClass = 'asc-badge-neutral';
                                }
                            ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo e($payment['first_name'] . ' ' . $payment['last_name']); ?></td>
                                    <td>
                                        <?php echo e($payment['description'] ?: ($payment['payment_method'] ?: 'Membership fee')); ?>
                                    </td>
                                    <td class="fw-bold asc-text-success">KES <?php echo number_format((float) $payment['amount'], 2); ?></td>
                                    <td class="mono"><?php echo e(date('d/m/y', strtotime($payment['payment_date']))); ?></td>
                                    <td><span class="asc-badge <?php echo $psClass; ?>"><?php echo e($psLabel); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Financial overview (dark) + today's schedule / coach availability -->
    <div class="row g-3 mb-4">
        <div class="col-xl-7">
            <div class="asc-dark-panel asc-finance-panel h-100">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                    <h4 class="asc-finance-title mb-0">Financial Overview</h4>
                    <a href="revenue_dashboard.php" class="asc-btn asc-btn-ghost btn-sm">
                        <i class="fas fa-chart-pie"></i> Revenue Dashboard
                    </a>
                </div>
                <div class="row g-4 mb-4">
                    <div class="col-sm-6">
                        <p class="asc-kpi-label">Total YTD Revenue</p>
                        <p class="asc-kpi-value">KES <?php echo number_format((float) $ytd_revenue, 0); ?></p>
                    </div>
                    <div class="col-sm-6">
                        <p class="asc-kpi-label">Outstanding Invoices</p>
                        <p class="asc-kpi-value asc-kpi-amber">KES <?php echo number_format((float) $outstanding_invoices, 0); ?></p>
                    </div>
                </div>
                <p class="asc-finance-sub">Monthly Revenue Trend</p>
                <?php
                // Trend line points: one per month, at bar-top height (0 = bottom, 100 = top of track).
                // No circle markers: under preserveAspectRatio="none" they would stretch into ellipses.
                $line_points = [];
                foreach ($monthly_revenue as $i => $mr) {
                    $pct = round(($mr['total'] / $rev_max) * 100);
                    $line_points[] = round((($i + 0.5) / 6) * 100, 1) . ',' . round(100 - $pct, 1);
                }
                ?>
                <div class="asc-rev-values">
                    <?php foreach ($monthly_revenue as $mr): ?>
                    <div class="asc-rev-col"><span class="asc-rev-value"><?php echo e(asc_compact_kes((float) $mr['total'])); ?></span></div>
                    <?php endforeach; ?>
                </div>
                <div class="asc-rev-plot">
                    <svg class="asc-rev-line" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                        <polyline points="<?php echo implode(' ', $line_points); ?>" />
                    </svg>
                    <div class="asc-rev-chart">
                        <?php foreach ($monthly_revenue as $mr):
                            $pct = round(($mr['total'] / $rev_max) * 100);
                        ?>
                        <div class="asc-rev-col" title="<?php echo e(date('M Y', strtotime($mr['ym']))); ?>: KES <?php echo number_format($mr['total'], 0); ?>">
                            <div class="asc-rev-bar-track">
                                <div class="asc-rev-bar-fill" style="height:<?php echo (int) $pct; ?>%;"></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="asc-rev-labels">
                    <?php foreach ($monthly_revenue as $mr): ?>
                    <div class="asc-rev-col"><span class="asc-rev-label"><?php echo e(date('M', strtotime($mr['ym']))); ?></span></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="asc-card">
                <div class="asc-card-head">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-calendar-day asc-card-head-icon"></i>
                        <h4 class="asc-card-title mb-0">Today's Schedule</h4>
                    </div>
                    <span class="text-muted small"><?php echo date('l, j F'); ?></span>
                </div>
                <div class="asc-table-wrap">
                    <table class="asc-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Title</th>
                                <th>Activity</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($today_schedule)): ?>
                                <tr>
                                    <td colspan="3">
                                        <div class="asc-empty"><i class="fas fa-calendar-xmark"></i><p>No sessions scheduled for today.</p></div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($today_schedule as $s): ?>
                                <tr>
                                    <td class="mono fw-semibold"><?php echo e(date('g:i A', strtotime($s['start_time']))); ?></td>
                                    <td>
                                        <?php echo e($s['sport_name'] ?: 'Session'); ?>
                                        <span class="asc-tbl-sub"><?php echo e($s['member_first'] . ' ' . $s['member_last']); ?></span>
                                    </td>
                                    <td>
                                        <?php if (!empty($s['facility_name'])): ?><span class="fw-semibold"><?php echo e($s['facility_name']); ?></span>, <?php endif; ?><?php echo e($s['coach_name']); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($coach_has_avail): ?>
            <div class="asc-card mt-3">
                <div class="asc-card-head">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-whistle asc-card-head-icon"></i>
                        <h4 class="asc-card-title mb-0">Coach Availability</h4>
                    </div>
                    <span class="text-muted small"><?php echo (int) $coaches_today_count; ?>/<?php echo (int) $coaches_total; ?> scheduled</span>
                </div>
                <div class="asc-table-wrap">
                    <table class="asc-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // One row per coach (a coach may have several availability slots today)
                            $displayed_coaches = [];
                            foreach ($coach_day_view as $c):
                                $cid = $c['coach_id'];
                                if (isset($displayed_coaches[$cid])) { continue; }
                                $displayed_coaches[$cid] = true;
                                $is_booked = isset($today_booked_coach_ids[$cid]);
                            ?>
                            <tr>
                                <td>
                                    <?php echo e($c['first_name'] . ' ' . $c['last_name']); ?>
                                    <span class="asc-tbl-sub"><?php echo e($c['specialization']); ?></span>
                                </td>
                                <td>
                                    <span class="asc-status-pill <?php echo $is_booked ? 'pill-warning' : 'pill-ok'; ?>">
                                        <span class="dot"></span> <?php echo $is_booked ? 'In Session' : 'Available'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($coach_day_view) && empty($coach_off_duty)): ?>
                            <tr>
                                <td colspan="2">
                                    <div class="asc-empty"><i class="fas fa-calendar-xmark"></i><p>No coaches scheduled for today.</p></div>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php foreach ($coach_off_duty as $c): ?>
                            <tr>
                                <td>
                                    <?php echo e($c['first_name'] . ' ' . $c['last_name']); ?>
                                    <span class="asc-tbl-sub"><?php echo e($c['specialization']); ?></span>
                                </td>
                                <td>
                                    <span class="asc-status-pill pill-off">
                                        <span class="dot"></span> Off Duty
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="asc-card-foot">
                    <a href="manage_coach_availability.php" class="asc-card-link">
                        <i class="fas fa-user-clock me-1"></i>Manage Availability
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Resource utilization -->
    <div class="asc-card mb-2">
        <div class="asc-card-head">
            <h4 class="asc-card-title">Most Booked Sports</h4>
            <span class="text-muted small">Share of all bookings</span>
        </div>
        <div class="card-body p-4">
            <?php if (empty($popular_sports)): ?>
                <div class="asc-empty"><i class="fas fa-chart-simple"></i><p>No utilization data available.</p></div>
            <?php else: ?>
                <?php $is_first_sport = true; ?>
                <div class="row g-3">
                    <?php foreach (array_slice($popular_sports, 0, 4) as $sport):
                        $share = (int) round(($sport['bookings_count'] / $total_bookings_all) * 100);
                        $icon = asc_sport_icon($sport['name']);
                        $fillClass = $is_first_sport ? 'asc-fill-green' : '';
                        $is_first_sport = false;
                    ?>
                    <div class="col-6 col-md-3">
                        <div class="asc-sport-util">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="asc-sport-icon"><i class="fas <?php echo $icon; ?>"></i></span>
                                <div class="min-w-0">
                                    <p class="asc-sport-name mb-0" title="<?php echo e($sport['name']); ?>"><?php echo e($sport['name']); ?></p>
                                    <p class="asc-sport-pct mb-0"><?php echo (int) $share; ?>% Utilized</p>
                                </div>
                            </div>
                            <div class="asc-progress-track">
                                <div class="asc-progress-fill <?php echo $fillClass; ?>" style="width:<?php echo (int) $share; ?>%;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once("../includes/footer.php"); ?>
