<?php
// Initialize the session
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();

// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/feature_helpers.php';

$member_id = (int) $_SESSION["member_id"];
$active_membership = get_active_membership($conn, $member_id);
$upcoming_bookings = 0;
$total_paid = 0.0;

if ($stmt = $conn->prepare("SELECT COUNT(*) FROM bookings WHERE member_id = ? AND booking_date >= CURDATE() AND status IN ('Pending', 'Approved', 'Confirmed')")) {
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $stmt->bind_result($upcoming_bookings);
    $stmt->fetch();
    $stmt->close();
}

if ($stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE member_id = ?")) {
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $stmt->bind_result($total_paid);
    $stmt->fetch();
    $stmt->close();
}

// ── Recent Activity Feed ─────────────────────────────────────────
$recent_activities = [];

// Recent bookings
$stmt = $conn->prepare("
    SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.status,
           COALESCE(s.name, 'Sport') AS sport_name,
           COALESCE(f.name, 'Facility') AS facility_name
    FROM bookings b
    LEFT JOIN sports s ON b.sport_id = s.sport_id
    LEFT JOIN facilities f ON b.facility_id = f.facility_id
    WHERE b.member_id = ?
    ORDER BY b.booking_date DESC, b.start_time DESC
    LIMIT 5
");
if ($stmt) {
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ts = strtotime($row['booking_date'] . ' ' . ($row['start_time'] ?? '00:00:00'));
        $recent_activities[] = [
            'type' => 'booking',
            'ts' => $ts,
            'date_label' => date('M j, Y', $ts),
            'label' => htmlspecialchars($row['sport_name']) . ' @ ' . htmlspecialchars($row['facility_name']),
            'detail' => date('g:i A', $ts) . ' - ' . date('g:i A', strtotime($row['end_time'])),
            'status' => $row['status'],
            'url' => 'view_bookings.php',
        ];
    }
    $stmt->close();
}

// Recent payments
$stmt = $conn->prepare("
    SELECT payment_id, amount, payment_method, payment_status, payment_date
    FROM payments
    WHERE member_id = ?
    ORDER BY payment_date DESC
    LIMIT 5
");
if ($stmt) {
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ts = strtotime($row['payment_date']);
        $recent_activities[] = [
            'type' => 'payment',
            'ts' => $ts,
            'date_label' => date('M j, Y', $ts),
            'label' => 'Payment of KES ' . number_format((float)$row['amount'], 0),
            'detail' => 'Via ' . ucfirst($row['payment_method'] ?? 'Unknown'),
            'status' => $row['payment_status'],
            'url' => 'payments.php',
        ];
    }
    $stmt->close();
}

// Recent ticket orders
$stmt = $conn->prepare("
    SELECT order_id, fixture_id, quantity, total_amount, status, created_at
    FROM ticket_orders
    WHERE member_id = ?
    ORDER BY created_at DESC
    LIMIT 5
");
if ($stmt) {
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ts = strtotime($row['created_at']);
        $recent_activities[] = [
            'type' => 'ticket',
            'ts' => $ts,
            'date_label' => date('M j, Y', $ts),
            'label' => $row['quantity'] . ' ticket(s) for Fixture #' . $row['fixture_id'],
            'detail' => 'KES ' . number_format((float)$row['total_amount'], 0),
            'status' => $row['status'],
            'url' => 'my_tickets.php',
        ];
    }
    $stmt->close();
}

// Sort by timestamp descending, take top 10
usort($recent_activities, fn($a, $b) => $b['ts'] - $a['ts']);
$recent_activities = array_slice($recent_activities, 0, 10);

// ── Today's Schedule ────────────────────────────────────────────
$todays_sessions = [];
$stmt = $conn->prepare("
    SELECT b.booking_id, b.start_time, b.end_time, b.status,
           COALESCE(s.name, 'Sport') AS sport_name,
           COALESCE(f.name, 'Facility') AS facility_name,
           COALESCE(CONCAT(c.first_name, ' ', c.last_name), '—') AS coach_name
    FROM bookings b
    LEFT JOIN sports s ON b.sport_id = s.sport_id
    LEFT JOIN facilities f ON b.facility_id = f.facility_id
    LEFT JOIN coaches c ON b.coach_id = c.coach_id
    WHERE b.member_id = ? AND b.booking_date = CURDATE()
    ORDER BY b.start_time ASC
");
if ($stmt) {
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $todays_sessions[] = $row;
    }
    $stmt->close();
}

$conn->close();

include_once("../includes/header.php");
?>

<!-- High-End Corporate Minimalist Design Token Layer -->
<style>
    body { 
        background-color: #f8fafc !important; 
        color: #334155 !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    
    /* Hero Workspace Interface */
    .dash-hero-card {
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #ffffff;
        border: none;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
    }
    
    .brand-accent-line {
        width: 40px;
        height: 4px;
        background-color: #38bdf8;
        border-radius: 2px;
        margin-bottom: 1rem;
    }

    .profile-context-box {
        background: rgba(255, 255, 255, 0.04);
        border: 1px solid rgba(255, 255, 255, 0.08);
        border-radius: 8px;
        padding: 0.75rem 1.25rem;
    }

    /* Analytic Info Cards */
    .stat-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
    }

    .stat-label-text {
        letter-spacing: 0.5px; 
        font-size: 0.75rem; 
        font-weight: 700;
        color: #64748b;
    }

    .badge-plan {
        background-color: #eff6ff;
        color: #2563eb;
        font-weight: 700;
        font-size: 0.85rem;
        padding: 0.25rem 0.75rem;
        border-radius: 6px;
        display: inline-block;
        border: 1px solid #bfdbfe;
    }

    .currency-value {
        font-size: 1.85rem; 
        font-weight: 800; 
        color: #16a34a !important;
    }

    /* Activity Feed Timeline */
    .activity-timeline {
        position: relative;
        padding-left: 2rem;
    }
    .activity-timeline::before {
        content: '';
        position: absolute;
        left: 8px;
        top: 8px;
        bottom: 8px;
        width: 2px;
        background: #e2e8f0;
    }
    .activity-item {
        position: relative;
        padding-bottom: 1.25rem;
    }
    .activity-item:last-child {
        padding-bottom: 0;
    }
    .activity-dot {
        position: absolute;
        left: -2rem;
        top: 4px;
        width: 18px;
        height: 18px;
        border-radius: 50%;
        border: 3px solid #fff;
        box-shadow: 0 0 0 2px #e2e8f0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.55rem;
    }
    .activity-dot.booking { background: #dbeafe; color: #2563eb; border-color: #bfdbfe; }
    .activity-dot.payment { background: #dcfce7; color: #16a34a; border-color: #bbf7d0; }
    .activity-dot.ticket { background: #fef3c7; color: #d97706; border-color: #fde68a; }
    .activity-status {
        font-size: 0.65rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.1rem 0.45rem;
        border-radius: 4px;
    }
    .activity-date-label {
        font-size: 0.7rem;
        font-weight: 600;
        color: #94a3b8;
        letter-spacing: 0.3px;
    }
    .activity-link {
        color: inherit;
        text-decoration: none;
        transition: color 0.15s ease;
    }
    .activity-link:hover {
        color: #2563eb !important;
    }
    .activity-link:hover .activity-label {
        color: #2563eb;
    }

    /* Workspace Action Hub Grid */
    .hub-header-bar {
        border-bottom: 1px solid #f1f5f9;
        padding-bottom: 1.25rem;
    }

    .section-title {
        font-size: 1.25rem;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: -0.5px;
    }

    .action-category-title {
        font-size: 0.72rem; 
        font-weight: 700; 
        letter-spacing: 0.5px;
        color: #64748b;
    }

    .action-item {
        border-radius: 6px !important;
        margin-bottom: 8px;
        border: 1px solid #e2e8f0 !important;
        padding: 0.75rem 1rem;
        transition: all 0.15s ease;
        font-weight: 600;
        font-size: 0.9rem;
        color: #334155 !important;
        display: flex;
        align-items: center;
        background-color: #ffffff;
        text-decoration: none;
    }
    
    .action-item:hover {
        background-color: #f8fafc !important;
        color: #2563eb !important;
        border-color: #cbd5e1 !important;
        transform: translateX(4px);
    }
    
    .action-logout {
        border-color: #fee2e2 !important;
        background-color: #fffdfd !important;
    }
    
    .action-logout:hover {
        background-color: #fee2e2 !important;
        color: #dc2626 !important;
        border-color: #fca5a5 !important;
    }
</style>

<div class="container py-4">
    
    <!-- Welcome-back alert -->
    <?php if (!empty($_SESSION['last_login'])): 
        $last_ts = strtotime($_SESSION['last_login']);
        $days_since = floor((time() - $last_ts) / 86400);
    ?>
    <div class="alert d-flex align-items-center gap-3 mb-4 px-4 py-3" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;color:#166534;">
        <div style="width:40px;height:40px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;">
            <i class="fas fa-hand-sparkles"></i>
        </div>
        <div>
            <strong style="font-weight:700;">Welcome back, <?php echo htmlspecialchars($_SESSION["first_name"]); ?>!</strong>
            <?php if ($days_since > 0): ?>
                <span class="ms-1" style="color:#15803d;">It's been <strong><?php echo $days_since; ?></strong> day<?php echo $days_since !== 1 ? 's' : ''; ?> since your last visit.</span>
            <?php else: ?>
                <span class="ms-1" style="color:#15803d;">Great to see you again today!</span>
            <?php endif; ?>
        </div>
        <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close" style="opacity:0.5;"></button>
    </div>
    <?php endif; ?>

    <!-- Workspace Head Context Module -->
    <div class="card dash-hero-card mb-4">
        <div class="card-body p-4 p-md-5">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <div class="brand-accent-line"></div>
                    <span class="text-uppercase small" style="color: #38bdf8; font-weight: 700; letter-spacing: 0.5px;">Member Dashboard</span>
                    <h1 class="mt-1 mb-2" style="font-size: 2rem; font-weight: 800; letter-spacing: -0.5px;">Welcome, <?php echo htmlspecialchars($_SESSION["first_name"]); ?>!</h1>
                    <p class="mb-0" style="font-size: 0.95rem; color: #94a3b8;">Manage your bookings, track your payments, and explore club facilities from one place.</p>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <div class="profile-context-box d-inline-block text-start">
                        <small class="d-block text-uppercase" style="font-size: 0.65rem; color: #64748b; font-weight: 700; letter-spacing: 0.5px;">Signed in as</small>
                        <span style="font-size: 0.9rem; font-family: SFMono-Regular, monospace; font-weight: 600; color: #f8fafc;"><?php echo htmlspecialchars($_SESSION["email"]); ?></span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Operational State Metrics Matrix -->
    <div class="row mb-4 g-3">
        
        <!-- Parameter Card: Membership -->
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="stat-label-text text-uppercase mb-0">Membership State</h5>
                            <i class="fas fa-gem" style="font-size:1.25rem;color:#2563eb;"></i>
                        </div>
                        <?php if ($active_membership): ?>
                            <div class="my-2">
                                <span class="badge-plan"><?php echo htmlspecialchars($active_membership['plan_name']); ?></span>
                            </div>
                        <?php else: ?>
                            <p class="h6 mb-0 my-2" style="font-weight: 600; color: #64748b;">No active plan</p>
                        <?php endif; ?>
                    </div>
                    <div class="pt-3">
                        <?php if ($active_membership): ?>
                            <small class="text-muted d-block" style="font-size:0.85rem;">Valid Until: <span class="text-dark" style="font-weight: 600;"><?php echo htmlspecialchars(date('d M Y', strtotime($active_membership['end_date']))); ?></span></small>
                        <?php else: ?>
                            <small class="text-muted d-block" style="font-size:0.85rem;">Subscribe to unlock club facilities</small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Parameter Card: Reservations -->
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="stat-label-text text-uppercase mb-0">Upcoming Bookings</h5>
                            <i class="fas fa-calendar-check" style="font-size:1.25rem;color:#d97706;"></i>
                        </div>
                        <h2 class="my-2" style="font-size: 2.2rem; font-weight: 800; color: #0f172a;"><?php echo (int) $upcoming_bookings; ?></h2>
                    </div>
                    <div class="pt-3">
                        <small class="text-muted d-block" style="font-size:0.85rem;">Active reservation segments matching filters</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Parameter Card: Financials -->
        <div class="col-md-4">
            <div class="card stat-card h-100">
                <div class="card-body p-4 d-flex flex-column justify-content-between">
                    <div>
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h5 class="stat-label-text text-uppercase mb-0">Total Paid</h5>
                            <i class="fas fa-credit-card" style="font-size:1.25rem;color:#16a34a;"></i>
                        </div>
                        <h2 class="my-2 currency-value">KES <?php echo number_format((float) $total_paid, 2); ?></h2>
                    </div>
                    <div class="pt-3">
                        <small class="text-muted d-block" style="font-size:0.85rem;">Aggregated settlement confirmations</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Schedule Widget -->
    <?php if (!empty($todays_sessions)): ?>
    <div class="card stat-card mb-4" style="border-color:#e0e7ff !important;">
        <div class="card-header bg-white p-4 hub-header-bar border-bottom-0 d-flex align-items-center justify-content-between">
            <div>
                <h3 class="section-title mb-0"><i class="fas fa-calendar-day me-2" style="color:#6366f1;"></i>Today's Schedule</h3>
                <p class="text-muted small mb-0 mt-1" style="font-size:0.88rem;">Your sessions booked for today</p>
            </div>
            <span class="badge" style="background:#eef2ff;color:#4f46e5;font-weight:700;"><?php echo count($todays_sessions); ?> session(s)</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0" style="font-size:0.85rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Time</th>
                            <th>Sport</th>
                            <th>Facility</th>
                            <th>Coach</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($todays_sessions as $s): 
                            $sc = strtolower($s['status']);
                            $sc_color = in_array($sc, ['approved','confirmed']) ? '#16a34a' : ($sc === 'pending' ? '#d97706' : ($sc === 'cancelled' ? '#dc2626' : '#64748b'));
                        ?>
                        <tr>
                            <td class="font-monospace fw-semibold"><?php echo $s['start_time']; ?> – <?php echo $s['end_time']; ?></td>
                            <td><?php echo htmlspecialchars($s['sport_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['facility_name']); ?></td>
                            <td><?php echo htmlspecialchars($s['coach_name']); ?></td>
                            <td><span class="badge" style="background:<?php echo $sc_color; ?>15;color:<?php echo $sc_color; ?>;font-size:0.65rem;font-weight:700;"><?php echo htmlspecialchars($s['status']); ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Activity Timeline -->
    <?php if (!empty($recent_activities)): ?>
    <div class="card stat-card mb-4">
        <div class="card-header bg-white p-4 hub-header-bar border-bottom-0">
            <h3 class="section-title mb-0"><i class="far fa-clock me-2" style="color:#64748b;"></i>Recent Activity</h3>
            <p class="text-muted small mb-0 mt-1" style="font-size:0.88rem;">Your latest bookings, payments, and ticket purchases</p>
        </div>
        <div class="card-body p-4">
            <div class="activity-timeline">
                <?php foreach ($recent_activities as $act): 
                    $s = strtolower($act['status']);
                    $status_color = in_array($s, ['completed','approved','confirmed','paid','successful']) ? '#16a34a' : ($s === 'pending' ? '#d97706' : (in_array($s, ['cancelled','rejected','failed']) ? '#dc2626' : '#64748b'));
                    $t = $act['type'];
                    $icon_class = $t === 'booking' ? 'fa-calendar-check' : ($t === 'payment' ? 'fa-credit-card' : ($t === 'ticket' ? 'fa-ticket-alt' : 'fa-circle'));
                ?>
                <div class="activity-item d-flex align-items-start gap-3">
                    <div class="activity-dot <?php echo $act['type']; ?>"></div>
                    <div class="flex-grow-1 min-width-0">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <a href="<?php echo $act['url']; ?>" class="activity-link">
                                <span class="fw-semibold small activity-label" style="color:#0f172a;">
                                    <i class="fas <?php echo $icon_class; ?> me-1" style="color:#64748b;"></i><?php echo $act['label']; ?>
                                </span>
                            </a>
                            <span class="activity-status" style="background:<?php echo $status_color; ?>15;color:<?php echo $status_color; ?>;">
                                <?php echo htmlspecialchars($act['status']); ?>
                            </span>
                        </div>
                        <div class="d-flex align-items-center gap-2 mt-1">
                            <small class="activity-date-label"><?php echo $act['date_label']; ?></small>
                            <small class="text-muted" style="font-size:0.7rem;">·</small>
                            <small class="text-muted" style="font-size:0.7rem;"><?php echo htmlspecialchars($act['detail']); ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Target Functional Workspace Hub -->
    <div class="card stat-card mb-4">
        <div class="card-header bg-white p-4 hub-header-bar border-bottom-0">
            <h3 class="section-title mb-0">Quick Actions Hub</h3>
            <p class="text-muted small mb-0 mt-1" style="font-size:0.88rem;">Select an active operational workspace destination parameter below</p>
        </div>
        <div class="card-body p-4">
            <div class="row g-4">
                
                <!-- Navigation Column: Reservations -->
                <div class="col-lg-4 col-md-6">
                    <h6 class="action-category-title text-uppercase mb-3">Reservations Engine</h6>
                    <div class="d-flex flex-column">
                        <a href="booking.php" class="action-item"><i class="fas fa-wand-magic-sparkles me-2"></i>Make a New Booking</a>
                        <a href="ai_booking_suggestions.php" class="action-item" style="border-color:#e9d5ff !important;background:#faf5ff !important;color:#7c3aed !important;"><i class="fas fa-robot me-2"></i>AI Booking Suggestions</a>
                        <a href="view_bookings.php" class="action-item"><i class="fas fa-search me-2"></i>View My Bookings</a>
                        <a href="booking_calendar.php" class="action-item"><i class="fas fa-calendar-alt me-2"></i>View Booking Calendar</a>
                    </div>
                </div>

                <!-- Navigation Column: Financial Framework -->
                <div class="col-lg-4 col-md-6">
                    <h6 class="action-category-title text-uppercase mb-3">Ticketing & Finances</h6>
                    <div class="d-flex flex-column">
                        <a href="tickets.php" class="action-item"><i class="fas fa-ticket-alt me-2"></i>Buy Match Tickets</a>
                        <a href="my_tickets.php" class="action-item"><i class="fas fa-ticket me-2"></i>View My Tickets</a>
                        <a href="payments.php" class="action-item"><i class="fas fa-money-bill me-2"></i>Make a Payment</a>
                    </div>
                </div>

                <!-- Navigation Column: Corporate Registries -->
                <div class="col-lg-4 col-md-12">
                    <h6 class="action-category-title text-uppercase mb-3">Directories & Profile</h6>
                    <div class="d-flex flex-column">
                        <a href="memberships.php" class="action-item"><i class="fas fa-medal me-2"></i>View Membership Plans</a>
                        <a href="team_registration.php" class="action-item"><i class="fas fa-futbol me-2"></i>Join a League Team</a>
                        <a href="view_sports.php" class="action-item"><i class="fas fa-basketball-ball me-2"></i>View Sports Directory</a>
                        <a href="view_facilities.php" class="action-item"><i class="fas fa-building me-2"></i>View Facilities</a>
                        <a href="view_coaches.php" class="action-item"><i class="fas fa-shoe-prints me-2"></i>View Certified Coaches</a>
                        <a href="update_profile.php" class="action-item"><i class="fas fa-user me-2"></i>Update Account Profile</a>
                        <a href="delete_account.php" class="action-item text-danger"><i class="fas fa-trash me-2"></i>Delete Account</a>
                        <a href="logout.php" class="action-item action-logout text-danger"><i class="fas fa-stop-circle me-2"></i>Sign Out of Account</a>
                    </div>
                </div>
                
            </div>
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
