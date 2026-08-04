<?php
session_start();

// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';

$member_id = $_SESSION["member_id"];
$bookings = [];

// Prepare a select statement to fetch bookings for the logged-in member
// Use JOIN to get sport name and facility name instead of just IDs
$sql = "SELECT
            b.booking_id,
            s.name AS sport_name,
            f.name AS facility_name,
            f.location AS facility_location,
            b.booking_date,
            b.start_time,
            b.end_time,
            b.status
        FROM
            bookings b
        LEFT JOIN
            sports s ON b.sport_id = s.sport_id
        LEFT JOIN
            facilities f ON b.facility_id = f.facility_id
        WHERE
            b.member_id = ?
        ORDER BY
            b.booking_date DESC, b.start_time DESC";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $member_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }
    } else {
        echo "Oops! Something went wrong. Please try again later.";
    }
    $stmt->close();
}
$conn->close();
?>

<?php include '../includes/header.php'; ?>

<div class="py-4">
    <div class="md-page-head">
        <div>
            <h1 class="md-page-title">My Bookings</h1>
            <p class="md-page-sub">Your session bookings across club facilities</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="md-card">
                <div class="md-card-head">
                    <div>
                        <h4 class="md-card-title"><i class="fas fa-calendar-check"></i>Booking History</h4>
                        <small class="text-muted"><?php echo count($bookings); ?> booking<?php echo count($bookings) === 1 ? '' : 's'; ?> on record</small>
                    </div>
                </div>
                <?php if (empty($bookings)): ?>
                    <div class="md-empty"><i class="fas fa-calendar-day"></i>You have no bookings yet. Book a court or class to get started.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table md-table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Sport</th>
                                <th>Facility</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bookings as $booking):
                                $sc = strtolower($booking['status']);
                                $pill = in_array($sc, ['confirmed', 'approved']) ? 'md-pill-green'
                                      : ($sc === 'pending' ? 'md-pill-amber'
                                      : ($sc === 'cancelled' ? 'md-pill-red' : 'md-pill-gray'));
                            ?>
                            <tr>
                                <td class="fw-semibold"><?php echo htmlspecialchars($booking['sport_name']); ?></td>
                                <td><?php echo htmlspecialchars($booking['facility_name'] . ($booking['facility_location'] ? ' (' . $booking['facility_location'] . ')' : '')); ?></td>
                                <td><?php echo htmlspecialchars(date('d M Y', strtotime($booking['booking_date']))); ?></td>
                                <td class="md-time"><?php echo htmlspecialchars(date('g:i A', strtotime($booking['start_time'])) . ' – ' . date('g:i A', strtotime($booking['end_time']))); ?></td>
                                <td><span class="md-pill <?php echo $pill; ?>"><?php echo htmlspecialchars($booking['status']); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <?php include __DIR__ . '/../includes/member_quick_actions.php'; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
