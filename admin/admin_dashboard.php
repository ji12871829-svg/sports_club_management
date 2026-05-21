<?php
include_once("../includes/admin_header.php");
include_once("../config/db_connect.php");
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Admin Dashboard</h2>
            </div>
            <div class="card-body">
                <p>Welcome to the Admin Panel, <?php echo htmlspecialchars($_SESSION["admin_email"]); ?>!</p>
                <p>Use the navigation bar above to manage different sections of Apex Sports Club.</p>
                <hr>
                <h3>Quick Access</h3>
                <div class="row">
                    <div class="col-md-4 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">Manage Members</h5>
                                <p class="card-text">View, add, and delete club members.</p>
                                <a href="manage_members.php" class="btn btn-primary">Go to Members</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">Manage Sports</h5>
                                <p class="card-text">Add, edit, and remove sports offered by the club.</p>
                                <a href="manage_sports.php" class="btn btn-primary">Go to Sports</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">Manage Coaches</h5>
                                <p class="card-text">Handle coach information and assignments.</p>
                                <a href="manage_coaches.php" class="btn btn-primary">Go to Coaches</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">Manage Facilities</h5>
                                <p class="card-text">Oversee club facilities and their availability.</p>
                                <a href="manage_facilities.php" class="btn btn-primary">Go to Facilities</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">Manage Equipment</h5>
                                <p class="card-text">Track and manage sports equipment.</p>
                                <a href="manage_equipment.php" class="btn btn-primary">Go to Equipment</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">Manage Bookings</h5>
                                <p class="card-text">Review and approve member bookings.</p>
                                <a href="manage_bookings.php" class="btn btn-primary">Go to Bookings</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">Manage Leagues</h5>
                                <p class="card-text">Manage leagues, teams, and team registrations.</p>
                                <a href="manage_leagues.php" class="btn btn-primary">Go to Leagues</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card text-center">
                            <div class="card-body">
                                <h5 class="card-title">Manage Payments</h5>
                                <p class="card-text">View and record member payments.</p>
                                <a href="manage_payments.php" class="btn btn-primary">Go to Payments</a>
                            </div>
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
