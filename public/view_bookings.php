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

<div class="row justify-content-center">
    <div class="col-md-10">
        <div class="card">
            <div class="card-header"><h2>My Bookings</h2></div>
            <div class="card-body">
                <?php if (empty($bookings)): ?>
                    <div class="alert alert-info">You have no bookings yet.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
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
                                <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($booking["sport_name"]); ?></td>
                                        <td><?php echo htmlspecialchars($booking["facility_name"] . " (" . $booking["facility_location"] . ")"); ?></td>
                                        <td><?php echo htmlspecialchars($booking["booking_date"]); ?></td>
                                        <td><?php echo htmlspecialchars(date("H:i", strtotime($booking["start_time"])) . " - " . date("H:i", strtotime($booking["end_time"]))); ?></td>
                                        <td><?php echo htmlspecialchars($booking["status"]); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
