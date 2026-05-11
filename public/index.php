<?php
session_start();
include_once("../includes/header.php");
?>

<div class="jumbotron text-center bg-light p-5 rounded">
    <h1 class="display-4">Welcome to Sports Club Management!</h1>
    <p class="lead">Your ultimate solution for managing sports club activities, members, bookings, and more.</p>
    <hr class="my-4">
    <p>Join our community today or log in to manage your activities.</p>
    <p class="lead">
        <?php if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true): ?>
            <a class="btn btn-primary btn-lg" href="register.php" role="button">Register</a>
            <a class="btn btn-secondary btn-lg" href="login.php" role="button">Login</a>
        <?php else: ?>
            <a class="btn btn-primary btn-lg" href="dashboard.php" role="button">Go to Dashboard</a>
        <?php endif; ?>
    </p>
</div>

<?php
include_once("../includes/footer.php");
?>
