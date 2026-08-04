<?php require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/assets.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apex Sports Club</title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Leaflet CSS (Free Maps - no API key needed) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo asc_asset(BASE_URL . '/public/css/style.css', __DIR__ . '/../public/css/style.css'); ?>">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-public">
        <div class="container">
            <a class="brand-public" href="<?php echo BASE_URL; ?>/public/index.php">
                <span class="brand-icon-public"><i class="fas fa-trophy"></i></span>
                <span class="brand-name">Apex Sports Club</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarNav" aria-controls="navbarNav"
                    aria-expanded="false" aria-label="Toggle navigation">
                <i class="fas fa-bars"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-lg-center">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/public/view_sports.php">
                            <i class="fas fa-futbol me-1"></i>Sports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/public/view_facilities.php">
                            <i class="fas fa-map-marker-alt me-1"></i>Facilities
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/public/view_coaches.php">
                            <i class="fas fa-user-tie me-1"></i>Coaches
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>/public/view_fixtures.php">
                            <i class="fas fa-calendar-alt me-1"></i>Fixtures &amp; Standings
                        </a>
                    </li>
                    <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo BASE_URL; ?>/public/team_registration.php">
                                <i class="fas fa-users me-1"></i>Teams
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo BASE_URL; ?>/public/dashboard.php">
                                <i class="fas fa-th-large me-1"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item ms-lg-2">
                            <a class="btn-logout-public" href="<?php echo BASE_URL; ?>/public/logout.php">
                                <i class="fas fa-sign-out-alt me-1"></i>Logout
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item ms-lg-2">
                            <a class="btn-login" href="<?php echo BASE_URL; ?>/public/login.php">
                                <i class="fas fa-sign-in-alt me-1"></i>Login
                            </a>
                        </li>
                        <li class="nav-item ms-lg-2">
                            <a class="btn-register" href="<?php echo BASE_URL; ?>/public/register.php">
                                <i class="fas fa-user-plus me-1"></i>Register
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
