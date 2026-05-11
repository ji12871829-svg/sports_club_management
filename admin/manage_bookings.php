<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";

$message = "";

// Handle booking status update
if (isset($_POST["action"]) && $_POST["action"] == "update_status" && isset($_POST["booking_id"]) && isset($_POST["status"])) {
    $booking_id = $_POST["booking_id"];
    $status = $_POST["status"];

    $sql = "UPDATE bookings SET status = ? WHERE booking_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("si", $status, $booking_id);
        if ($stmt->execute()) {
            $message = "<div class=\'alert alert-success\'>Booking status updated successfully.</div>";
        } else {
            $message = "<div class=\'alert alert-danger\'>Error updating booking status: " . $stmt->error . "</div>";
        }
        $stmt->close();
    }
}

// Fetch all bookings with member, facility, coach, and sport details
$bookings = [];
$sql = "SELECT b.booking_id, m.first_name, m.last_name, f.name AS facility_name, c.first_name AS coach_first_name, c.last_name AS coach_last_name, s.name AS sport_name, b.booking_date, b.start_time, b.end_time, b.status 
        FROM bookings b
        LEFT JOIN members m ON b.member_id = m.member_id
        LEFT JOIN facilities f ON b.facility_id = f.facility_id
        LEFT JOIN coaches c ON b.coach_id = c.coach_id
        LEFT JOIN sports s ON b.sport_id = s.sport_id
        ORDER BY b.booking_date DESC, b.start_time DESC";

if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $bookings[] = $row;
    }
    $result->free();
}
$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Manage Bookings</h2>
            </div>
            <div class="card-body">
                <?php echo $message; ?>

                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Member</th>
                            <th>Facility</th>
                            <th>Coach</th>
                            <th>Sport</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($bookings) > 0): ?>
                            <?php foreach ($bookings as $booking): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($booking["booking_id"]); ?></td>
                                    <td><?php echo htmlspecialchars($booking["first_name"] . " " . $booking["last_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($booking["facility_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($booking["coach_first_name"] . " " . $booking["coach_last_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($booking["sport_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($booking["booking_date"]); ?></td>
                                    <td><?php echo htmlspecialchars(date("H:i", strtotime($booking["start_time"])) . " - " . date("H:i", strtotime($booking["end_time"]))); ?></td>
                                    <td>
                                        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline">
                                            <input type="hidden" name="booking_id" value="<?php echo $booking["booking_id"]; ?>">
                                            <select name="status" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                                                <option value="Pending" <?php echo ($booking["status"] == "Pending") ? "selected" : ""; ?>>Pending</option>
                                                <option value="Approved" <?php echo ($booking["status"] == "Approved") ? "selected" : ""; ?>>Approved</option>
                                                <option value="Rejected" <?php echo ($booking["status"] == "Rejected") ? "selected" : ""; ?>>Rejected</option>
                                                <option value="Completed" <?php echo ($booking["status"] == "Completed") ? "selected" : ""; ?>>Completed</option>
                                            </select>
                                            <input type="hidden" name="action" value="update_status">
                                        </form>
                                    </td>
                                    <td>
                                        <!-- Add more actions like view details if needed -->
                                        <a href="#" class="btn btn-info btn-sm disabled">Details</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9">No bookings found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
