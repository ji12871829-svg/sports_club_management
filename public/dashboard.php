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

<!-- Member Dashboard — reference-faithful design layer -->
<style>
    /* ── Palette / tokens (from reference) ─────────────────────────── */
    .md-banner {
        background: linear-gradient(120deg, #1e293b 0%, #3b0764 100%);
        border-radius: 16px;
        color: #fff;
        padding: 2.25rem 2.5rem;
        position: relative;
        overflow: hidden;
    }
    .md-banner::after {
        content: '';
        position: absolute;
        right: -80px; top: -80px;
        width: 260px; height: 260px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(129, 140, 248, 0.28) 0%, rgba(129, 140, 248, 0) 70%);
    }
    .md-banner::before {
        content: '';
        position: absolute;
        left: 30%; bottom: -110px;
        width: 220px; height: 220px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(56, 189, 248, 0.18) 0%, rgba(56, 189, 248, 0) 70%);
    }
    .md-banner h1 { font-weight: 800; letter-spacing: -0.5px; margin-bottom: 0.25rem; }
    .md-banner .md-banner-sub { color: #a5b4fc; font-size: 0.95rem; }

    .md-card {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
    }
    .md-card-head {
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #f1f3f5;
        display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    }
    .md-card-title { font-size: 1.05rem; font-weight: 700; color: #111827; margin: 0; }
    .md-card-title i { color: #6366f1; margin-right: 0.5rem; }

    /* ── Summary stat cards ──────────────────────────────────────── */
    .md-stat {
        display: flex; align-items: center; gap: 1rem;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 1.25rem 1.5rem;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04);
        transition: transform 0.15s ease, box-shadow 0.15s ease;
        height: 100%;
    }
    .md-stat:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(16, 24, 40, 0.07); }
    .md-stat-icon {
        width: 50px; height: 50px; flex-shrink: 0;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.25rem;
    }
    .md-stat-icon-blue   { background: #eff6ff; color: #2563eb; }
    .md-stat-icon-amber  { background: #fffbeb; color: #d97706; }
    .md-stat-icon-green  { background: #ecfdf5; color: #059669; }
    .md-stat-label { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; color: #6b7280; text-transform: uppercase; }
    .md-stat-value { font-size: 1.45rem; font-weight: 800; color: #111827; line-height: 1.2; }
    .md-stat-sub { font-size: 0.82rem; color: #6b7280; }
    .md-plan-badge {
        display: inline-block;
        background: #eff6ff; color: #2563eb;
        border: 1px solid #bfdbfe;
        border-radius: 8px;
        padding: 0.15rem 0.7rem;
        font-weight: 700; font-size: 0.95rem;
    }

    /* ── Schedule table ──────────────────────────────────────────── */
    .md-table th {
        font-size: 0.72rem; font-weight: 700; letter-spacing: 0.07em;
        text-transform: uppercase; color: #6b7280;
        background: #f9fafb;
        border-bottom: 1px solid #e5e7eb !important;
        padding: 0.75rem 1.5rem;
    }
    .md-table td {
        padding: 0.9rem 1.5rem;
        border-bottom: 1px solid #f1f3f5;
        font-size: 0.9rem;
        color: #374151;
        vertical-align: middle;
    }
    .md-table tbody tr:last-child td { border-bottom: none; }
    .md-table tbody tr:hover { background: #fafbfc; }
    .md-time { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 600; color: #111827; }

    .md-pill {
        display: inline-block;
        padding: 0.22rem 0.75rem;
        border-radius: 999px;
        font-size: 0.72rem; font-weight: 700;
        letter-spacing: 0.02em;
    }
    .md-pill-green { background: #dcfce7; color: #15803d; }
    .md-pill-amber { background: #fef3c7; color: #b45309; }
    .md-pill-red   { background: #fee2e2; color: #b91c1c; }
    .md-pill-gray  { background: #f3f4f6; color: #6b7280; }

    /* ── Activity timeline ───────────────────────────────────────── */
    .md-timeline { position: relative; padding-left: 1.25rem; }
    .md-timeline::before {
        content: '';
        position: absolute;
        left: 25px; top: 12px; bottom: 12px;
        width: 2px;
        background: #e5e7eb;
    }
    .md-activity {
        position: relative;
        display: flex; gap: 1rem;
        padding-bottom: 1.4rem;
    }
    .md-activity:last-child { padding-bottom: 0; }
    .md-activity-icon {
        width: 50px; height: 50px; flex-shrink: 0;
        border-radius: 12px;
        background: #fff;
        border: 1px solid #e5e7eb;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.05rem;
        color: #6b7280;
        position: relative; z-index: 1;
    }
    .md-activity.booking  .md-activity-icon { color: #2563eb; background: #eff6ff; border-color: #dbeafe; }
    .md-activity.payment  .md-activity-icon { color: #059669; background: #ecfdf5; border-color: #d1fae5; }
    .md-activity.ticket   .md-activity-icon { color: #d97706; background: #fffbeb; border-color: #fde68a; }
    .md-activity-ts { font-size: 0.72rem; font-weight: 600; color: #9ca3af; }
    .md-activity-title { font-weight: 700; color: #111827; font-size: 0.92rem; }
    .md-activity-desc { font-size: 0.82rem; color: #6b7280; }

    /* ── Quick actions hub ───────────────────────────────────────── */
    .md-hub-group-label {
        font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em;
        text-transform: uppercase; color: #6b7280;
    }
    .md-action {
        display: flex; align-items: center; gap: 0.75rem;
        width: 100%;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 0.7rem 1rem;
        color: #374151;
        font-weight: 600; font-size: 0.9rem;
        text-decoration: none;
        transition: border-color 0.15s ease, color 0.15s ease, background 0.15s ease, transform 0.15s ease;
    }
    .md-action i { color: #6366f1; width: 18px; text-align: center; font-size: 0.95rem; }
    .md-action:hover {
        border-color: #c7d2fe;
        color: #4f46e5;
        background: #fafaff;
        transform: translateX(3px);
        text-decoration: none;
    }

    /* ── Empty states ────────────────────────────────────────────── */
    .md-empty {
        text-align: center;
        color: #9ca3af;
        padding: 2rem 1rem;
        font-size: 0.9rem;
    }
    .md-empty i { font-size: 1.6rem; margin-bottom: 0.5rem; display: block; color: #d1d5db; }
</style>

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
                    <span class="md-pill d-none d-md-inline-block" style="background:#eef2ff;color:#4f46e5;"><?php echo count($todays_sessions); ?> session<?php echo count($todays_sessions) === 1 ? '' : 's'; ?></span>
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

        <!-- ── Right column: Quick Actions Hub ────────────────────── -->
        <div class="col-lg-4">
            <div class="md-card">
                <div class="md-card-head">
                    <div>
                        <h4 class="md-card-title"><i class="fas fa-bolt"></i>Quick Actions Hub</h4>
                        <small class="text-muted">Jump straight to what you need</small>
                    </div>
                </div>
                <div class="card-body p-4">
                    <div class="mb-4">
                        <div class="md-hub-group-label mb-2">Reservations Engine</div>
                        <div class="d-flex flex-column gap-2">
                            <a href="booking.php" class="md-action"><i class="fas fa-calendar-plus"></i>Book a Court</a>
                            <a href="book_training.php" class="md-action"><i class="fas fa-person-chalkboard"></i>Schedule a Class</a>
                            <a href="view_coaches.php" class="md-action"><i class="fas fa-user-tie"></i>Find a Coach</a>
                        </div>
                    </div>
                    <div class="mb-4">
                        <div class="md-hub-group-label mb-2">Ticketing &amp; Finances</div>
                        <div class="d-flex flex-column gap-2">
                            <a href="payments.php" class="md-action"><i class="fas fa-file-invoice-dollar"></i>View Invoices</a>
                            <a href="tickets.php" class="md-action"><i class="fas fa-ticket"></i>Buy Event Tickets</a>
                            <a href="payments.php" class="md-action"><i class="fas fa-wallet"></i>Payment Methods</a>
                        </div>
                    </div>
                    <div>
                        <div class="md-hub-group-label mb-2">Profile</div>
                        <div class="d-flex flex-column gap-2">
                            <a href="update_profile.php" class="md-action"><i class="fas fa-user-pen"></i>Update Personal Details</a>
                            <a href="memberships.php" class="md-action"><i class="fas fa-medal"></i>Manage Membership</a>
                            <a href="fitness_dashboard.php" class="md-action"><i class="fas fa-heart-pulse"></i>Health Stats</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
