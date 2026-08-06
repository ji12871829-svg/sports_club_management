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
            'label' => 'Court Booking Payment',
            'detail' => 'Payment of KES ' . number_format((float)$row['amount'], 2) . ' via ' . ucfirst($row['payment_method'] ?? 'card'),
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
            'label' => 'Ticket Order',
            'detail' => $row['quantity'] . 'x ' . ($row['quantity'] > 1 ? 'tickets' : 'ticket') . ' — KES ' . number_format((float)$row['total_amount'], 2),
            'status' => $row['status'],
            'url' => 'my_tickets.php',
        ];
    }
    $stmt->close();
}

// Sort by timestamp descending, take top 6
usort($recent_activities, fn($a, $b) => $b['ts'] - $a['ts']);
$recent_activities = array_slice($recent_activities, 0, 6);

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

// Member display identity
$member_first = $_SESSION["first_name"] ?? 'Member';
$member_last  = $_SESSION["last_name"] ?? '';
$member_name  = trim($member_first . ' ' . $member_last);
$member_email = $_SESSION["email"] ?? '';

include_once("../includes/header.php");
?>

<!-- Member Dashboard — design tokens now live in public/css/portal.css -->

<div class="py-4">

    <!-- Welcome banner -->
    <div class="md-banner mb-4">
        <h1 class="h3">Welcome back, <?php echo htmlspecialchars($member_name); ?></h1>
        <?php if ($member_email !== ''): ?>
            <div class="md-banner-sub"><?php echo htmlspecialchars($member_email); ?></div>
        <?php endif; ?>
    </div>

    <!-- Summary cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="md-stat">
                <div class="md-stat-icon md-stat-icon-blue"><i class="fas fa-shield-halved"></i></div>
                <div>
                    <div class="md-stat-label">Membership Status</div>
                    <?php if ($active_membership): ?>
                        <div class="md-stat-value"><span class="md-plan-badge"><?php echo htmlspecialchars($active_membership['plan_name']); ?></span></div>
                        <div class="md-stat-sub">Valid to <?php echo htmlspecialchars(date('M j, Y', strtotime($active_membership['end_date']))); ?></div>
                    <?php else: ?>
                        <div class="md-stat-value">No active plan</div>
                        <div class="md-stat-sub"><a href="memberships.php" class="link-primary">View membership plans</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="md-stat">
                <div class="md-stat-icon md-stat-icon-amber"><i class="fas fa-calendar-check"></i></div>
                <div>
                    <div class="md-stat-label">Upcoming Bookings</div>
                    <div class="md-stat-value"><?php echo (int) $upcoming_bookings; ?></div>
                    <div class="md-stat-sub">Sessions booked ahead</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="md-stat">
                <div class="md-stat-icon md-stat-icon-green"><i class="fas fa-wallet"></i></div>
                <div>
                    <div class="md-stat-label">Total Paid</div>
                    <div class="md-stat-value">KES <?php echo number_format((float) $total_paid, 2); ?></div>
                    <div class="md-stat-sub">Lifetime contributions</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main grid: content (left) + quick actions (right) -->
    <div class="row g-4">

        <!-- ── Left column ────────────────────────────────────────── -->
        <div class="col-lg-8">

            <!-- Today's Schedule -->
            <div class="md-card mb-4">
                <div class="md-card-head">
                    <div>
                        <h4 class="md-card-title"><i class="fas fa-calendar-day"></i>Today's Schedule</h4>
                        <small class="text-muted">Your sessions booked for today</small>
                    </div>
                    <span class="md-pill d-none d-md-inline-block" style="background:#e8f1f8;color:#14497a;"><?php echo count($todays_sessions); ?> session<?php echo count($todays_sessions) === 1 ? '' : 's'; ?></span>
                </div>
                <?php if (empty($todays_sessions)): ?>
                    <div class="md-empty"><i class="fas fa-calendar-day"></i>No sessions booked for today. Enjoy your day!</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table md-table align-middle mb-0">
                        <thead>
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
                                $pill = in_array($sc, ['confirmed', 'approved']) ? 'md-pill-green'
                                      : ($sc === 'pending' ? 'md-pill-amber'
                                      : ($sc === 'cancelled' ? 'md-pill-red' : 'md-pill-gray'));
                                $time = date('g:i A', strtotime($s['start_time']));
                            ?>
                            <tr>
                                <td class="md-time"><?php echo $time; ?></td>
                                <td class="fw-semibold"><?php echo htmlspecialchars($s['sport_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['facility_name']); ?></td>
                                <td><?php echo htmlspecialchars($s['coach_name']); ?></td>
                                <td><span class="md-pill <?php echo $pill; ?>"><?php echo htmlspecialchars($s['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Recent Activity -->
            <div class="md-card">
                <div class="md-card-head">
                    <div>
                        <h4 class="md-card-title"><i class="far fa-clock"></i>Recent Activity</h4>
                        <small class="text-muted">Your latest bookings, payments, and ticket purchases</small>
                    </div>
                </div>
                <?php if (empty($recent_activities)): ?>
                    <div class="md-empty"><i class="fas fa-history"></i>No recent activity yet.</div>
                <?php else: ?>
                <div class="card-body p-4">
                    <div class="md-timeline">
                        <?php foreach ($recent_activities as $act):
                            $sc = strtolower($act['status']);
                            $pill = in_array($sc, ['completed', 'approved', 'confirmed', 'paid', 'successful']) ? 'md-pill-green'
                                  : ($sc === 'pending' ? 'md-pill-amber'
                                  : (in_array($sc, ['cancelled', 'rejected', 'failed']) ? 'md-pill-red' : 'md-pill-gray'));
                            $icon = $act['type'] === 'booking' ? 'fa-calendar-check'
                                  : ($act['type'] === 'payment' ? 'fa-credit-card' : 'fa-ticket');
                        ?>
                        <div class="md-activity <?php echo $act['type']; ?>">
                            <div class="md-activity-icon"><i class="fas <?php echo $icon; ?>"></i></div>
                            <div class="flex-grow-1 min-width-0">
                                <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
                                    <div>
                                        <div class="md-activity-title">
                                            <a href="<?php echo $act['url']; ?>" style="color:inherit;text-decoration:none;"><?php echo $act['label']; ?></a>
                                        </div>
                                        <div class="md-activity-desc"><?php echo $act['detail']; ?></div>
                                        <div class="md-activity-ts mt-1"><?php echo $act['date_label']; ?></div>
                                    </div>
                                    <span class="md-pill <?php echo $pill; ?>"><?php echo htmlspecialchars($act['status']); ?></span>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Right column: Quick Actions Hub (shared include) ─────── -->
        <div class="col-lg-4">
            <?php include __DIR__ . '/../includes/member_quick_actions.php'; ?>
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
