<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sports Club Management</title>

    <!-- Cloudflare Turnstile -->
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Leaflet CSS (Free Maps - no API key needed) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

    <!-- Font Awesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <!-- Google reCAPTCHA (loaded only on pages that need it) -->
    <script src="https://www.google.com/recaptcha/api.js" async defer></script>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="/sports_club_management/public/css/style.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="/sports_club_management/public/index.php">
                <i class="fas fa-trophy me-2"></i>Sports Club
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#navbarNav" aria-controls="navbarNav"
                    aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/sports_club_management/public/view_sports.php">
                            <i class="fas fa-futbol me-1"></i>Sports
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/sports_club_management/public/view_facilities.php">
                            <i class="fas fa-map-marker-alt me-1"></i>Facilities
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/sports_club_management/public/view_coaches.php">
                            <i class="fas fa-user-tie me-1"></i>Coaches
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/sports_club_management/public/view_fixtures.php">
                            <i class="fas fa-calendar-alt me-1"></i>Fixtures &amp; Standings
                        </a>
                    </li>
                    <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/sports_club_management/public/team_registration.php">
                                <i class="fas fa-users me-1"></i>Teams
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/sports_club_management/public/dashboard.php">
                                <i class="fas fa-th-large me-1"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/sports_club_management/public/logout.php">
                                <i class="fas fa-sign-out-alt me-1"></i>Logout
                            </a>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/sports_club_management/public/login.php">
                                <i class="fas fa-sign-in-alt me-1"></i>Login
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link btn btn-outline-light btn-sm ms-2 px-3" href="/sports_club_management/public/register.php">
                                Register
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <div class="container mt-4">
