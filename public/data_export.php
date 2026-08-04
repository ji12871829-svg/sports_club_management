<?php
/**
 * public/data_export.php
 * GDPR-style personal data export — members can download all their data as JSON.
 */
require_once '../includes/session_config.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
asc_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

$member_id = (int) $_SESSION['member_id'];

// ── Handle export download (must run before any HTML output) ─────────────────
if (($_GET['action'] ?? '') === 'download') {
    require_once '../config/db_connect.php';
    $stmt = $conn->prepare('SELECT * FROM members WHERE member_id = ?');
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    unset($profile['password']);

    $bookings = $conn->query("
        SELECT b.*, s.name AS sport, f.name AS facility
        FROM bookings b
        LEFT JOIN sports s ON s.sport_id = b.sport_id
        LEFT JOIN facilities f ON f.facility_id = b.facility_id
        WHERE b.member_id = $member_id
        ORDER BY b.booking_date DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $payments = $conn->query("
        SELECT payment_id, amount, payment_method, description, payment_date, payment_status
        FROM payments WHERE member_id = $member_id ORDER BY payment_date DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $memberships = $conn->query("
        SELECT mm.*, mp.name AS plan_name, mp.price
        FROM member_memberships mm
        JOIN membership_plans mp ON mp.plan_id = mm.plan_id
        WHERE mm.member_id = $member_id
        ORDER BY mm.start_date DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $teams = $conn->query("
        SELECT t.name AS team_name, l.name AS league_name, tm.registered_at
        FROM team_memberships tm
        JOIN teams t ON t.team_id = tm.team_id
        JOIN leagues l ON l.league_id = t.league_id
        WHERE tm.member_id = $member_id
    ")->fetch_all(MYSQLI_ASSOC);

    $tickets = $conn->query("
        SELECT tk.ticket_code, tk.status, to2.total_amount, to2.created_at
        FROM tickets tk
        JOIN ticket_orders to2 ON to2.order_id = tk.order_id
        WHERE to2.member_id = $member_id
        ORDER BY to2.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $match_events = $conn->query("
        SELECT me.event_type, me.minute, me.created_at,
               h.name AS home_team, a.name AS away_team, f.match_date
        FROM match_events me
        JOIN fixtures f ON f.fixture_id = me.fixture_id
        JOIN teams h ON h.team_id = f.home_team_id
        JOIN teams a ON a.team_id = f.away_team_id
        WHERE me.member_id = $member_id
        ORDER BY me.created_at DESC
    ")->fetch_all(MYSQLI_ASSOC);

    $conn->close();

    $export = [
        'export_info' => [
            'generated_at'    => date('Y-m-d H:i:s'),
            'requested_by'    => 'member_id:' . $member_id,
            'data_controller' => 'Apex Sports Club',
        ],
        'profile'      => $profile,
        'memberships'  => $memberships,
        'bookings'     => $bookings,
        'payments'     => $payments,
        'teams'        => $teams,
        'tickets'      => $tickets,
        'match_events' => $match_events,
    ];

    $filename = 'apex_my_data_' . date('Ymd_His') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

include_once '../includes/header.php';
?>

<div class="container py-5" style="max-width:680px;">
    <h2 class="fw-bold mb-1">🔒 My Data & Privacy</h2>
    <p class="text-muted mb-4">Download a copy of all personal data we hold about you</p>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">What's included in your export</h5>
            <ul class="list-unstyled mb-0" style="line-height:2.2;">
                <li>👤 <strong>Profile</strong> — name, email, phone, address, join date</li>
                <li>🏅 <strong>Memberships</strong> — all plans and periods</li>
                <li>📅 <strong>Bookings</strong> — all facility bookings you've made</li>
                <li>💳 <strong>Payments</strong> — payment history (amounts, methods, dates)</li>
                <li>👕 <strong>Teams</strong> — teams you've joined</li>
                <li>🎟️ <strong>Tickets</strong> — match tickets purchased</li>
                <li>⚽ <strong>Match Events</strong> — goals and cards recorded in your name</li>
            </ul>
        </div>
    </div>

    <div class="alert alert-info mb-4">
        <i class="fas fa-info-circle me-2"></i>
        Your data will download as a <strong>JSON file</strong>. This is a machine-readable format
        you can open in any text editor or share with another service.
        <br><small class="text-muted mt-1 d-block">Your password is never included in the export.</small>
    </div>

    <a href="?action=download" class="btn btn-primary btn-lg w-100 mb-3">
        <i class="fas fa-download me-2"></i>Download My Data
    </a>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">Your other rights</h5>
            <p class="text-muted small mb-2">Under data protection law, you also have the right to:</p>
            <ul class="text-muted small mb-3" style="line-height:2;">
                <li><strong>Correction</strong> — update your profile in <a href="update_profile.php">My Profile</a></li>
                <li><strong>Deletion</strong> — contact the club admin to request account deletion</li>
                <li><strong>Objection</strong> — contact us to object to how your data is used</li>
            </ul>
            <p class="text-muted small mb-0">
                Contact: <a href="mailto:<?php echo e(defined('CLUB_EMAIL_FROM') ? CLUB_EMAIL_FROM : 'admin@sportsclub.com'); ?>">
                    <?php echo e(defined('CLUB_EMAIL_FROM') ? CLUB_EMAIL_FROM : 'admin@sportsclub.com'); ?>
                </a>
            </p>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>
