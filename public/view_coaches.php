<!-- public/view_coaches.php -->
<?php
session_start();
include_once("../includes/header.php");
require_once "../config/db_connect.php";

// Fetch all coaches
$coaches = [];
$sql = "SELECT coach_id, first_name, last_name, email, phone_number, specialization FROM coaches ORDER BY first_name";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $coaches[] = $row;
    }
    $result->free();
}
$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Our Coaches</h2>
            </div>
            <div class="card-body">
                <?php if (count($coaches) > 0): ?>
                    <div class="row">
                        <?php foreach ($coaches as $coach): ?>
                            <div class="col-md-4 mb-4">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <h5 class="card-title"><?php echo htmlspecialchars($coach["first_name"] . " " . $coach["last_name"]); ?></h5>
                                        <p class="card-text"><strong>Specialization:</strong> <?php echo htmlspecialchars($coach["specialization"]); ?></p>
                                        <p class="card-text"><strong>Email:</strong> <?php echo htmlspecialchars($coach["email"]); ?></p>
                                        <p class="card-text"><strong>Phone:</strong> <?php echo htmlspecialchars($coach["phone_number"]); ?></p>
                                        <!-- Add a link to book a session with this coach if booking functionality exists -->
                                        <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                                            <a href="booking.php?coach_id=<?php echo $coach["coach_id"]; ?>" class="btn btn-primary">Book Session</a>
                                        <?php else: ?>
                                            <a href="login.php" class="btn btn-secondary">Login to Book Session</a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p>No coaches currently available.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
