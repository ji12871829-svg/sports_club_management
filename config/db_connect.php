<?php
require_once __DIR__ . '/api_config.php';
require_once __DIR__ . '/../includes/profiler.php';

// Start the request profiler as early as possible
AscProfiler::start();

$servername = DB_HOST;
$username = DB_USER;
$password = DB_PASSWORD;
$dbname = DB_NAME;

// Persistent connection — reuses existing connection across requests.
// AscMysqli behaves identically to mysqli but counts queries for the profiler.
$connClass = class_exists('AscMysqli') ? 'AscMysqli' : 'mysqli';
$conn = new $connClass("p:{$servername}", $username, $password, $dbname);

if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);
    die('Database connection failed. Please try again later.');
}

// Set connection charset once (avoids per-query SET NAMES)
$conn->set_charset('utf8mb4');
?>
