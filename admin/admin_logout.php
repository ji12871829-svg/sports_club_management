<?php
require_once '../includes/session_config.php';
require_once '../includes/admin_2fa.php';

asc_session_start();
$_SESSION = [];
session_destroy();

header('Location: admin_login.php');
exit;
