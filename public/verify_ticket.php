<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();

require_once '../config/db_connect.php';
require_once '../includes/ticket_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

if (empty($_SESSION['verify_ticket_csrf'])) {
    $_SESSION['verify_ticket_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['verify_ticket_csrf'];

$schema_ready = ticketing_ensure_schema($conn);
$code = trim($_GET['code'] ?? $_POST['code'] ?? '');
$message = '';
$is_admin = isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] === true;

if ($schema_ready && $is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $posted)) {
        $message = "<div class='alert alert-danger'>Security check failed.</div>";
    } elseif ($code !== '') {
        $stmt = $conn->prepare("UPDATE tickets SET status = 'Used', used_at = NOW() WHERE ticket_code = ? AND status = 'Valid'");
        if ($stmt) {
            $stmt->bind_param('s', $code);
            $stmt->execute();
            $message = $stmt->affected_rows > 0
                ? "<div class='alert alert-success'>Ticket marked as used.</div>"
                : "<div class='alert alert-warning'>Ticket was not valid or had already been used.</div>";
            $stmt->close();
        }
    }
}

$ticket = null;
if ($schema_ready && $code !== '') {
    $sql = "SELECT t.ticket_id, t.ticket_code, t.ticket_price, t.status, t.issued_at, t.used_at,
                   st.name AS supported_team,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, ''))), ''), o.buyer_name, 'Fan') AS holder_name,
                   COALESCE(m.email, o.buyer_email) AS email,
                   f.match_date, f.match_time, f.venue, f.matchday,
                   h.name AS home_team, a.name AS away_team,
                   l.name AS league_name, s.name AS sport_name
            FROM tickets t
            JOIN ticket_orders o ON o.order_id = t.order_id
            LEFT JOIN members m ON m.member_id = t.member_id
            JOIN fixtures f ON f.fixture_id = t.fixture_id
            JOIN teams h ON h.team_id = f.home_team_id
            JOIN teams a ON a.team_id = f.away_team_id
            JOIN leagues l ON l.league_id = f.league_id
            JOIN sports s ON s.sport_id = l.sport_id
            LEFT JOIN teams st ON st.team_id = t.supported_team_id
            WHERE t.ticket_code = ?
            LIMIT 1";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $ticket = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$conn->close();
include '../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h2 class="mb-0">Ticket Verification</h2>
            </div>
            <div class="card-body">
                <?php echo $message; ?>

                <?php if (!$schema_ready): ?>
                    <div class="alert alert-danger">Ticketing tables are not ready.</div>
                <?php elseif ($code === ''): ?>
                    <div class="alert alert-info">Scan a ticket QR code or enter a ticket code in the URL.</div>
                <?php elseif (!$ticket): ?>
                    <div class="alert alert-danger">Ticket not found.</div>
                <?php else: ?>
                    <?php $is_valid = $ticket['status'] === 'Valid'; ?>
                    <div class="text-center mb-3">
                        <span class="badge fs-6 <?php echo $is_valid ? 'bg-success' : 'bg-secondary'; ?>">
                            <?php echo e($ticket['status']); ?>
                        </span>
                    </div>

                    <table class="table table-bordered">
                        <tbody>
                            <tr>
                                <th>Ticket Code</th>
                                <td><?php echo e($ticket['ticket_code']); ?></td>
                            </tr>
                            <tr>
                                <th>Fixture</th>
                                <td><?php echo e($ticket['home_team'] . ' vs ' . $ticket['away_team']); ?></td>
                            </tr>
                            <tr>
                                <th>Competition</th>
                                <td><?php echo e($ticket['sport_name'] . ' - ' . $ticket['league_name']); ?></td>
                            </tr>
                            <tr>
                                <th>Date</th>
                                <td><?php echo e(date('d M Y', strtotime($ticket['match_date'])) . ' ' . substr((string) $ticket['match_time'], 0, 5)); ?></td>
                            </tr>
                            <tr>
                                <th>Venue</th>
                                <td><?php echo e($ticket['venue'] ?: 'Venue TBC'); ?></td>
                            </tr>
                            <tr>
                                <th>Holder</th>
                                <td><?php echo e($is_admin ? $ticket['holder_name'] : 'Verified ticket'); ?></td>
                            </tr>
                            <?php if ($is_admin): ?>
                            <tr>
                                <th>Email</th>
                                <td><?php echo e($ticket['email']); ?></td>
                            </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Supported Team</th>
                                <td><?php echo e($ticket['supported_team'] ?: 'Not selected'); ?></td>
                            </tr>
                            <?php if ($ticket['used_at']): ?>
                                <tr>
                                    <th>Used At</th>
                                    <td><?php echo e(date('d M Y, H:i', strtotime($ticket['used_at']))); ?></td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if ($is_admin && $is_valid): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                            <input type="hidden" name="code" value="<?php echo e($ticket['ticket_code']); ?>">
                            <button type="submit" class="btn btn-success">Mark Ticket Used</button>
                        </form>
                    <?php elseif (!$is_admin): ?>
                        <p class="text-muted mb-0">Only an admin can mark a ticket as used.</p>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
