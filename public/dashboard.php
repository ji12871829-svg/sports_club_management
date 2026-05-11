<!-- public/dashboard.php -->
<?php
// Initialize the session
session_start();

// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

include_once("../includes/header.php");
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Welcome, <?php echo htmlspecialchars($_SESSION["first_name"]); ?>!</h2>
            </div>
            <div class="card-body">
                <p>This is your member dashboard. Here you can manage your bookings, view your membership details, and more.</p>
                <p>Your email: <b><?php echo htmlspecialchars($_SESSION["email"]); ?></b></p>
                <hr>
                <h3>Quick Actions</h3>
                <div class="list-group">
                    <a href="view_bookings.php" class="list-group-item list-group-item-action">View My Bookings</a>
                    <a href="update_profile.php" class="list-group-item list-group-item-action">Update Profile</a>
                    <a href="booking.php" class="list-group-item list-group-item-action">Make a New Booking</a>
                        <a href="team_registration.php" class="list-group-item list-group-item-action">Join a League Team</a>
                        <a href="view_sports.php" class="list-group-item list-group-item-action">View Sports</a>
                        <a href="view_facilities.php" class="list-group-item list-group-item-action">View Facilities</a>
                        <a href="view_coaches.php" class="list-group-item list-group-item-action">View Coaches</a>
                        <a href="logout.php" class="list-group-item list-group-item-action text-danger">Logout</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
