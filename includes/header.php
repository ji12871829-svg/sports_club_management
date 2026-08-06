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
    <link rel="stylesheet" href="<?php echo asc_asset(BASE_URL . '/public/css/portal.css', __DIR__ . '/../public/css/portal.css'); ?>">

    <!-- Brand mark + favicon -->
    <link rel="icon" type="image/svg+xml" href="<?php echo asc_asset(BASE_URL . '/public/assets/logo.svg', __DIR__ . '/../public/assets/logo.svg'); ?>">
</head>
<body>
    <?php $asc_logged_in = isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true; ?>
    <nav class="navbar navbar-expand-lg navbar-public <?php echo $asc_logged_in ? 'navbar-portal' : ''; ?>">
        <div class="container">
            <?php if ($asc_logged_in): ?>
                <!-- Portal brand (stacked APEX / ATHLETIC CLUB + logo mark) -->
                <a class="portal-brand" href="<?php echo BASE_URL; ?>/public/dashboard.php">
                    <span class="portal-brand-logo">
                        <img src="<?php echo asc_asset(BASE_URL . '/public/assets/logo.svg', __DIR__ . '/../public/assets/logo.svg'); ?>" alt="Apex Sports Club logo">
                    </span>
                    <span class="portal-brand-text">
                        <span class="portal-brand-apex">Apex</span>
                        <span class="portal-brand-sub">Athletic Club</span>
                    </span>
                </a>
            <?php else: ?>
                <a class="brand-public" href="<?php echo BASE_URL; ?>/public/index.php">
                    <span class="brand-icon-public">
                        <img src="<?php echo asc_asset(BASE_URL . '/public/assets/logo.svg', __DIR__ . '/../public/assets/logo.svg'); ?>" alt="Apex Sports Club logo" class="apex-logo-img">
                    </span>
                    <span class="brand-name">Apex Sports Club</span>
                </a>
            <?php endif; ?>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarNav" aria-controls="navbarNav"
                    aria-expanded="false" aria-label="Toggle navigation">
                <i class="fas fa-bars"></i>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <?php if ($asc_logged_in): ?>
                    <!-- Centered search (desktop) -->
                    <form class="portal-search-wrap" action="<?php echo BASE_URL; ?>/public/member_directory.php" method="get" role="search">
                        <input type="text" name="search" class="portal-search" placeholder="Search" aria-label="Search">
                        <i class="fas fa-magnifying-glass"></i>
                    </form>
                    <ul class="navbar-nav ms-auto align-items-lg-center">
                        <li class="nav-item">
                            <a class="portal-bell" href="<?php echo BASE_URL; ?>/public/activity_feed.php" aria-label="Notifications">
                                <i class="far fa-bell"></i>
                                <span class="portal-bell-dot"></span>
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="portal-user dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <span class="portal-avatar">
                                    <?php
                                    $asc_f = isset($_SESSION['first_name']) ? trim($_SESSION['first_name']) : '';
                                    $asc_l = isset($_SESSION['last_name']) ? trim($_SESSION['last_name']) : '';
                                    echo htmlspecialchars(strtoupper(mb_substr($asc_f, 0, 1) . mb_substr($asc_l, 0, 1)));
                                    ?>
                                </span>
                                <span class="portal-name d-none d-lg-inline">
                                    <?php echo htmlspecialchars(trim($asc_f . ' ' . $asc_l)); ?>
                                </span>
                                <i class="fas fa-chevron-down portal-chevron d-none d-lg-inline"></i>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end portal-dropdown">
                                <li><h6 class="dropdown-header">My Portal</h6></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/dashboard.php"><i class="fas fa-th-large me-2"></i>Dashboard</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/view_bookings.php"><i class="fas fa-calendar-check me-2"></i>My Bookings</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/payments.php"><i class="fas fa-credit-card me-2"></i>Payments</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/my_tickets.php"><i class="fas fa-ticket me-2"></i>My Tickets</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/update_profile.php"><i class="fas fa-user me-2"></i>Profile</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><h6 class="dropdown-header">Explore</h6></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/view_sports.php"><i class="fas fa-futbol me-2"></i>Sports</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/view_facilities.php"><i class="fas fa-map-marker-alt me-2"></i>Facilities</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/view_coaches.php"><i class="fas fa-user-tie me-2"></i>Coaches</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/view_fixtures.php"><i class="fas fa-calendar-alt me-2"></i>Fixtures &amp; Standings</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>/public/team_registration.php"><i class="fas fa-users me-2"></i>Teams</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item text-danger" href="<?php echo BASE_URL; ?>/public/logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
                            </ul>
                        </li>
                    </ul>
                <?php else: ?>
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
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
