<?php
/**
 * PHPUnit bootstrap — loads the application's shared helpers
 * and DB connection for integration tests.
 */

require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/csrf.php';

// Start session for CSRF tests
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Test DB helper ------------------------------------------------
function getTestDb(): mysqli {
    static $conn = null;
    if ($conn === null) {
        $host = getenv('MYSQL_HOST') ?: '127.0.0.1';
        $port = getenv('MYSQL_PORT') ?: '3306';
        $user = getenv('MYSQL_USER') ?: 'root';
        $pass = getenv('MYSQL_PASS') ?: '';
        $db   = getenv('MYSQL_DB')  ?: 'sports_club_db_test';

        $conn = new mysqli($host, $user, $pass, $db, (int)$port);
        if ($conn->connect_error) {
            throw new RuntimeException('Cannot connect to test DB: ' . $conn->connect_error);
        }
    }
    return $conn;
}