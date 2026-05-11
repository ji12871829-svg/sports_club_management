<?php
// Initialize the session
session_start();
 
// Unset all of the session variables
$_SESSION = array();
 
// Destroy the session.php
session_destroy();
 
// Redirect to login page
header("location: admin_login.php");
exit;
?>
