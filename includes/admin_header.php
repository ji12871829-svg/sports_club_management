<?php 
require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/admin_auth.php';
require_once __DIR__ . '/assets.php';

// Get current page filename to highlight active link
$current_page = basename($_SERVER['PHP_SELF']);

// ── CSRF Enforcement (defense-in-depth for state-changing requests) ──
// Every admin POST must carry a valid CSRF token. Pages that already
// verify their own per-form tokens pass automatically (csrf_valid_any);
// AJAX/fetch POSTs are stamped client-side by the interceptor below.
// Exempt pages use their own challenge flows (login, 2FA).
require_once __DIR__ . '/csrf.php';

$csrf_exempt_pages = [
    'admin_login.php',
    'admin_verify_2fa.php',
    'admin_setup_2fa.php',
    'admin_logout.php',
];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && !in_array($current_page, $csrf_exempt_pages, true)
    && !csrf_valid_any($_POST['csrf_token'] ?? null)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Invalid or missing CSRF token. Please reload the page and try again.');
}

// ── Permission Enforcement ──────────────────────────────────────────
// Check if the current page requires a specific permission
$__admin_id = (int)($_SESSION['admin_id'] ?? 0);
if ($__admin_id > 0 && !in_array($current_page, ['admin_login.php','admin_logout.php','admin_verify_2fa.php','admin_setup_2fa.php'], true)) {
    require_once __DIR__ . '/../config/db_connect.php';
    require_once __DIR__ . '/roles.php';

    $page_permissions = [
        'manage_members.php' => 'members.view',
        'manage_bookings.php' => 'bookings.view',
        'booking_overview.php' => 'bookings.view',
        'manage_payments.php' => 'payments.view',
        'payments_overview.php' => 'payments.view',
        'manage_refunds.php' => 'payments.refund',
        'manage_promo_codes.php' => 'payments.view',
        'manage_expenses.php' => 'payments.view',
        'revenue_dashboard.php' => 'revenue.view',
        'membership_reminders.php' => 'members.view',
        'bulk_email.php' => 'members.view',
        'export_payments.php' => 'payments.export',
        'export_fixtures.php' => 'fixtures.manage',
        'manage_sports.php' => 'sports.manage',
        'manage_facilities.php' => 'facilities.manage',
        'manage_equipment.php' => 'equipment.manage',
        'manage_equipment_borrow.php' => 'equipment.manage',
        'manage_maintenance.php' => 'facilities.manage',
        'manage_damage_reports.php' => 'equipment.manage',
        'manage_leagues.php' => 'leagues.manage',
        'manage_fixtures.php' => 'fixtures.manage',
        'manage_standings.php' => 'standings.manage',
        'manage_tickets.php' => 'tickets.manage',
        'live_score_control.php' => 'fixtures.manage',
        'manage_match_events.php' => 'fixtures.manage',
        'manage_lineups.php' => 'fixtures.manage',
        'manage_coaches.php' => 'bookings.view',
        'coach_session_notes.php' => 'bookings.view',
        'manage_coach_availability.php' => 'bookings.view',
        'manage_attendance.php' => 'members.view',
        'ai_match_reports.php' => 'fixtures.manage',
        'ai_predictions.php' => 'fixtures.manage',
        'ai_smart_scheduling.php' => 'bookings.view',
        'ai_review_log.php' => 'bookings.view',
        'cron_ai_settings.php' => 'settings.edit',
        'season_wizard.php' => 'leagues.manage',
        'event_checklist.php' => 'fixtures.manage',
        'churn_prediction.php' => 'members.view',
        'todo_list.php' => 'bookings.view',
        'manage_announcements.php' => 'announcements.manage',
        'manage_polls.php' => 'polls.manage',
        'manage_forum.php' => 'forum.moderate',
        'manage_sponsors.php' => 'sponsors.manage',
        'manage_volunteers.php' => 'volunteers.manage',
        'manage_waiting_list.php' => 'members.view',
        'manage_injuries.php' => 'members.view',
        'activity_log.php' => 'logs.view',
        'security_events.php' => 'logs.view',
        'backup_database.php' => 'backup.create',
        'manage_roles.php' => 'roles.manage',
        'manage_gallery.php' => 'announcements.manage',
        'gemini_hub.php' => 'settings.edit',
        'system_health.php' => 'settings.view',
        'slow_pages.php' => 'settings.view',
        'upload_storage.php' => 'settings.view',
        'notifications.php' => 'settings.view',
    ];

    $required_perm = $page_permissions[$current_page] ?? null;
    if ($required_perm !== null) {
        if (!asc_has_permission($conn, $required_perm, 'admin', $__admin_id)) {
            http_response_code(403);
            echo '<!DOCTYPE html><html lang="en"><head><title>Access Denied - Apex Sports Club</title>';
            echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">';
            echo '<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">';
            echo '<style>body{background:#f8fafc;display:flex;align-items:center;justify-content:center;min-height:100vh;font-family:system-ui,sans-serif;margin:0;}.error-card{background:white;border-radius:16px;padding:3rem;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,.1);max-width:480px;margin:1rem;}.error-card .icon{font-size:3.5rem;color:#ef4444;margin-bottom:1rem;}.error-card h1{font-size:1.75rem;font-weight:700;color:#0f172a;margin-bottom:.5rem;}.error-card p{color:#64748b;margin-bottom:1.5rem;}.error-card .btn{background:#2563eb;color:white;border:none;border-radius:8px;padding:.6rem 1.5rem;text-decoration:none;font-weight:600;display:inline-block;}</style>';
            echo '</head><body><div class="error-card">';
            echo '<div class="icon"><i class="fas fa-shield-halved"></i></div>';
            echo '<h1>Access Denied</h1>';
            echo '<p>You do not have the required permission to access this page. Contact your administrator if you need access.</p>';
            echo '<a href="admin_dashboard.php" class="btn"><i class="fas fa-arrow-left me-1"></i> Back to Dashboard</a>';
            echo '</div></body></html>';
            exit;
        }
    }
}

// Admin initials for avatar fallback
$admin_name  = $_SESSION['admin_username'] ?? 'Admin';
$admin_initials = '';
$name_parts = explode(' ', $admin_name, 2);
$admin_initials = strtoupper(substr($name_parts[0], 0, 1));
if (isset($name_parts[1])) {
    $admin_initials .= strtoupper(substr($name_parts[1], 0, 1));
}
if (!$admin_initials) $admin_initials = 'A';

// ── Notification badge count (pending bookings) ──────────────────────
$pending_count = 0;
if (isset($conn) && $conn instanceof mysqli) {
    $pc = $conn->query("SELECT COUNT(*) AS cnt FROM bookings WHERE status = 'Pending'");
    if ($pc) $pending_count = (int) $pc->fetch_assoc()['cnt'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Apex Sports Club</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- CSRF token for client-side form/fetch stamping (see interceptor below) -->
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_ensure('admin_csrf'), ENT_QUOTES, 'UTF-8'); ?>">
    
    <link rel="stylesheet" href="<?php echo asc_asset('../public/css/style.css', __DIR__ . '/../public/css/style.css'); ?>">

    <link rel="stylesheet" href="<?php echo asc_asset('../public/css/admin.css', __DIR__ . '/../public/css/admin.css'); ?>">

    <!-- PWA: manifest + theme -->
    <link rel="manifest" href="<?php echo asc_asset('manifest.json', __DIR__ . '/manifest.json'); ?>">
    <meta name="theme-color" content="#0f172a">
    <meta name="application-name" content="Apex Admin">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Apex Admin">

    <!-- Brand mark + favicons -->
    <link rel="icon" href="<?php echo asc_asset('../public/assets/favicon-32.png', __DIR__ . '/../public/assets/favicon-32.png'); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo asc_asset('../public/assets/favicon-16.png', __DIR__ . '/../public/assets/favicon-16.png'); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo asc_asset('../public/assets/favicon-32.png', __DIR__ . '/../public/assets/favicon-32.png'); ?>">
    <link rel="icon" type="image/png" sizes="48x48" href="<?php echo asc_asset('../public/assets/favicon-48.png', __DIR__ . '/../public/assets/favicon-48.png'); ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo asc_asset('../public/assets/favicon-180.png', __DIR__ . '/../public/assets/favicon-180.png'); ?>">
</head>
<body>

<!-- Skip to main content (accessibility) -->
<a href="#main-content" class="visually-hidden-focusable position-absolute top-0 start-0 m-2 px-3 py-2 rounded-bottom" style="z-index:10000;background:#6366f1;color:#fff;font-weight:600;text-decoration:none;">Skip to content</a>

<!-- ══ Loading Overlay ══ -->
<div id="page-loader">
    <div style="text-align:center;">
        <div class="loader-ring" style="margin:0 auto;"></div>
        <div class="loader-text">Loading…</div>
    </div>
</div>

<script>
// ── PWA service worker (secure contexts only: https or localhost) ───────
if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js').catch(function () {
            // Registration is best-effort — never block the admin console on it.
        });
    });
}

// ── CSRF auto-stamp ─────────────────────────────────────────────────────
// Server-side, admin_header.php rejects admin POSTs without a valid CSRF
// token. This interceptor transparently adds the token to every same-origin
// POST form and fetch() call so the enforcement never breaks legitimate UI.
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    if (!meta) return;
    var token = meta.getAttribute('content') || '';

    // Forms: stamp any POST form that has no csrf_token field yet.
    document.addEventListener('submit', function (e) {
        var f = e.target;
        if (!f || f.tagName !== 'FORM') return;
        if ((f.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (f.querySelector('input[name="csrf_token"]')) return;
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'csrf_token';
        input.value = token;
        f.appendChild(input);
    }, true);

    // fetch(): append csrf_token to POST bodies.
    var origFetch = window.fetch;
    window.fetch = function (url, opts) {
        opts = opts || {};
        var method = (opts.method || 'GET').toUpperCase();
        if (method === 'POST') {
            if (opts.body && typeof opts.body.append === 'function') {
                if (!opts.body.has('csrf_token')) opts.body.append('csrf_token', token);
            } else if (!opts.body) {
                opts.body = new FormData();
                opts.body.append('csrf_token', token);
            }
        }
        return origFetch.call(this, url, opts);
    };
})();

// ── Loading overlay ─────────────────────────────────────────────────────
document.addEventListener('click', function(e) {
    var link = e.target.closest('a');
    if (!link) return;
    var href = link.getAttribute('href');
    if (!href || href === 'javascript:void(0)' || href.indexOf('#') === 0) return;
    if (link.getAttribute('target') === '_blank') return;
    if (link.getAttribute('data-bs-toggle')) return;
    document.getElementById('page-loader').classList.add('show');
}, true);

// ── Keyboard shortcut: Ctrl+K / Cmd+K opens search ─────────────────────
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        openSearch();
    }
    // Escape closes search
    if (e.key === 'Escape') {
        var modal = document.getElementById('searchModal');
        if (modal && modal.style.display === 'block') {
            closeSearch();
        }
    }
});

// ── Quick Search ────────────────────────────────────────────────────────
var searchTimer = null;
var searchIndex = -1;
var searchResults = [];

function openSearch() {
    document.getElementById('searchModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
    var inp = document.getElementById('searchInput');
    inp.value = '';
    inp.focus();
    searchIndex = -1;
    searchResults = [];
    document.getElementById('searchResults').innerHTML = '<div class="search-empty"><i class="fas fa-search"></i><br>Type to search members, coaches, fixtures…</div>';
}

function closeSearch() {
    document.getElementById('searchModal').style.display = 'none';
    document.body.style.overflow = '';
    if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
    searchIndex = -1;
}

// Arrow key navigation inside search
var prevSearchResults = [];
document.addEventListener('keydown', function(e) {
    var modal = document.getElementById('searchModal');
    if (!modal || modal.style.display !== 'block') return;
    var items = modal.querySelectorAll('.search-result-item');
    if (items.length === 0) return;
    
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        searchIndex = Math.min(searchIndex + 1, items.length - 1);
        highlightSearchItem(items, searchIndex);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        searchIndex = Math.max(searchIndex - 1, 0);
        highlightSearchItem(items, searchIndex);
    } else if (e.key === 'Enter' && searchIndex >= 0 && items[searchIndex]) {
        e.preventDefault();
        items[searchIndex].click();
    }
});

function highlightSearchItem(items, idx) {
    for (var i = 0; i < items.length; i++) {
        items[i].style.background = (i === idx) ? '#eef2ff' : '';
    }
    if (items[idx]) {
        items[idx].scrollIntoView({ block: 'nearest' });
    }
}

function doSearch() {
    var q = document.getElementById('searchInput').value.trim();
    var el = document.getElementById('searchDynamicResults');
    var quickLinks = document.querySelectorAll('.search-quick-link');
    if (q.length < 1) {
        el.innerHTML = '';
        for (var i = 0; i < quickLinks.length; i++) quickLinks[i].style.display = '';
        return;
    }
    // Hide quick links when searching
    for (var i = 0; i < quickLinks.length; i++) quickLinks[i].style.display = 'none';
    el.innerHTML = '<div class="search-loading" style="padding:1rem;text-align:center;color:var(--asc-faint);"><div class="spinner-border spinner-border-sm me-2"></div>Searching…</div>';
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(function() {
        fetch('ajax_search.php?q=' + encodeURIComponent(q) + '&limit=10')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.results || data.results.length === 0) {
                    el.innerHTML = '<div class="search-empty"><i class="fas fa-search"></i><br>No results found for "' + escHtml(q) + '"</div>';
                    return;
                }
                var html = '<div class="search-section-label">Results</div>';
                for (var i = 0; i < data.results.length; i++) {
                    var r = data.results[i];
                    html += '<a class="search-quick-link" href="' + escHtml(r.url) + '" onclick="closeSearch()" onmouseenter="searchIndex=' + i + '">';
                    html += '<i class="fas ' + escHtml(r.icon || 'fa-circle') + '"></i>';
                    html += '<div><div style="font-weight:600;">' + escHtml(r.title) + '</div>';
                    html += '<div style="font-size:.72rem;color:var(--asc-faint);margin-top:1px;">' + escHtml(r.subtitle) + '</div></div>';
                    html += '<span style="margin-left:auto;font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--asc-faint);">' + escHtml(r.type) + '</span>';
                    html += '</a>';
                }
                el.innerHTML = html;
                searchIndex = -1;
            })
            .catch(function() {
                el.innerHTML = '<div class="search-empty" style="color:#ef4444;"><i class="fas fa-exclamation-circle"></i><br>Search failed. Try again.</div>';
            });
    }, 200);
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Dark Mode Toggle ────────────────────────────────────────────────────
(function() {
    var theme = localStorage.getItem('apex-theme');
    if (theme === 'dark') {
        document.body.classList.add('theme-dark');
    }
    // Sync button icon once DOM is ready
    function syncThemeBtn() {
        var btn = document.getElementById('themeBtn');
        if (!btn) { setTimeout(syncThemeBtn, 50); return; }
        btn.innerHTML = document.body.classList.contains('theme-dark')
            ? '<i class="fas fa-sun"></i>'
            : '<i class="fas fa-moon"></i>';
    }
    syncThemeBtn();
})();

// ── Platform-aware shortcut label ────────────────────────────────────────
(function() {
    var isMac = navigator.platform.indexOf('Mac') === 0 || navigator.platform.indexOf('iPhone') === 0 || navigator.platform.indexOf('iPad') === 0;
    var badges = document.querySelectorAll('.search-shortcut');
    for (var i = 0; i < badges.length; i++) {
        badges[i].textContent = isMac ? '⌘K' : 'Ctrl+K';
    }
})();

function toggleTheme() {
    var body = document.body;
    var btn = document.getElementById('themeBtn');
    body.classList.toggle('theme-dark');
    if (body.classList.contains('theme-dark')) {
        localStorage.setItem('apex-theme', 'dark');
        if (btn) btn.innerHTML = '<i class="fas fa-sun"></i>';
    } else {
        localStorage.setItem('apex-theme', 'light');
        if (btn) btn.innerHTML = '<i class="fas fa-moon"></i>';
    }
}
</script>

<!-- ══ COMMAND PALETTE ══ -->
<div id="searchModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(11,17,32,.45);backdrop-filter:blur(6px);z-index:1050;" onclick="if(event.target===this)closeSearch()">
    <div style="max-width:580px;margin:10vh auto 0;padding:0 1rem;">
        <div class="search-palette">
            <div class="search-palette-header">
                <i class="fas fa-search"></i>
                <input type="text" id="searchInput" placeholder="Search members, coaches, fixtures, pages…" autocomplete="off" oninput="doSearch()">
                <kbd style="flex-shrink:0;">Esc</kbd>
            </div>
            <div class="search-palette-shortcuts">
                <kbd>↑</kbd><kbd>↓</kbd><span>navigate</span>
                <kbd>↵</kbd><span>open</span>
            </div>
            <div id="searchResults" class="search-palette-body">
                <div class="search-section-label">Quick Links</div>
                <a class="search-quick-link" href="admin_dashboard.php" onclick="closeSearch()"><i class="fas fa-grid-2"></i>Dashboard</a>
                <a class="search-quick-link" href="manage_members.php" onclick="closeSearch()"><i class="fas fa-id-badge"></i>Members Directory</a>
                <a class="search-quick-link" href="manage_bookings.php" onclick="closeSearch()"><i class="fas fa-calendar-check"></i>Facility Bookings</a>
                <a class="search-quick-link" href="payments_overview.php" onclick="closeSearch()"><i class="fas fa-arrow-right-arrow-left"></i>Payments Overview</a>
                <a class="search-quick-link" href="revenue_dashboard.php" onclick="closeSearch()"><i class="fas fa-chart-line"></i>Revenue Dashboard</a>
                <a class="search-quick-link" href="manage_fixtures.php" onclick="closeSearch()"><i class="fas fa-calendar-alt"></i>Match Fixtures</a>
                <a class="search-quick-link" href="gemini_hub.php" onclick="closeSearch()"><i class="fas fa-wand-magic-sparkles"></i>Gemini AI Hub</a>
                <div id="searchDynamicResults"></div>
            </div>
        </div>
    </div>
</div>

<!-- ══ NAVBAR ══ -->
<nav class="navbar navbar-expand-lg navbar-apex sticky-top">
    <div class="container-fluid px-lg-4">

        <!-- Brand -->
        <a class="brand-wrap" href="admin_dashboard.php">
            <span class="brand-icon">
                <img src="<?php echo asc_asset('../public/assets/logo.png', __DIR__ . '/../public/assets/logo.png'); ?>" alt="Apex Sports Club logo" class="apex-logo-img">
            </span>
            <div>
                <div class="brand-text">Apex Sports</div>
                <div class="brand-sub">Admin Panel</div>
            </div>
        </a>

        <!-- Toggler -->
        <button class="navbar-toggler navbar-toggler-apex" type="button" data-bs-toggle="collapse" data-bs-target="#adminNavbar" aria-controls="adminNavbar" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Collapsible -->
        <div class="collapse navbar-collapse" id="adminNavbar">
            <ul class="navbar-nav mx-auto mb-2 mb-lg-0 gap-lg-1">

                <!-- Dashboard -->
                <li class="nav-item">
                    <a class="nav-link <?php echo ($current_page == 'admin_dashboard.php') ? 'active' : ''; ?>" href="admin_dashboard.php">
                        <i class="fas fa-grid-2"></i>Dashboard
                    </a>
                </li>

                <!-- Operations (merged People + Operations) -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo (in_array($current_page, ['manage_members.php','manage_coaches.php','coach_session_notes.php','churn_prediction.php','manage_sports.php','manage_facilities.php','manage_equipment.php','manage_bookings.php','booking_overview.php','manage_coach_availability.php','manage_maintenance.php','todo_list.php','cron_ai_settings.php','ai_smart_scheduling.php','ai_review_log.php','manage_equipment_borrow.php'])) ? 'active' : ''; ?>" href="javascript:void(0)" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-sliders"></i>Operations
                    </a>
                    <ul class="dropdown-menu">
                        <div class="nav-group-label">People</div>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_members.php') ? 'active' : ''; ?>" href="manage_members.php"><i class="fas fa-id-badge"></i>Members Directory</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'churn_prediction.php') ? 'active' : ''; ?>" href="churn_prediction.php"><i class="fas fa-heartbeat" style="color:#ef4444;"></i>Churn Prediction <span class="badge bg-danger ms-1" style="font-size:0.55rem;">AI</span></a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_coaches.php') ? 'active' : ''; ?>" href="manage_coaches.php"><i class="fas fa-whistle"></i>Coaching Staff</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'coach_session_notes.php') ? 'active' : ''; ?>" href="coach_session_notes.php"><i class="fas fa-sticky-note"></i>Coach Session Notes</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <div class="nav-group-label">Facilities &amp; Gear</div>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_sports.php') ? 'active' : ''; ?>" href="manage_sports.php"><i class="fas fa-futbol"></i>Sports Disciplines</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_facilities.php') ? 'active' : ''; ?>" href="manage_facilities.php"><i class="fas fa-building"></i>Club Facilities</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_equipment.php') ? 'active' : ''; ?>" href="manage_equipment.php"><i class="fas fa-dumbbell"></i>Inventory &amp; Gear</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_equipment_borrow.php') ? 'active' : ''; ?>" href="manage_equipment_borrow.php"><i class="fas fa-exchange-alt"></i>Equipment Loans</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <div class="nav-group-label">Bookings &amp; Maintenance</div>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_bookings.php') ? 'active' : ''; ?>" href="manage_bookings.php"><i class="fas fa-calendar-check"></i>Facility Bookings</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'booking_overview.php') ? 'active' : ''; ?>" href="booking_overview.php"><i class="fas fa-calendar-week"></i>Booking Calendar</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_coach_availability.php') ? 'active' : ''; ?>" href="manage_coach_availability.php"><i class="fas fa-user-clock"></i>Coach Availability</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_maintenance.php') ? 'active' : ''; ?>" href="manage_maintenance.php"><i class="fas fa-tools"></i>Facility Maintenance</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'todo_list.php') ? 'active' : ''; ?>" href="todo_list.php"><i class="fas fa-list-check"></i>To-Do List</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'ai_smart_scheduling.php') ? 'active' : ''; ?>" href="ai_smart_scheduling.php"><i class="fas fa-wand-magic-sparkles" style="color:#7c3aed;"></i>AI Smart Scheduling <span class="badge bg-danger ms-1" style="font-size:0.5rem;">NEW</span></a></li>
                    </ul>
                </li>

                <!-- Competitions -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo (in_array($current_page, ['manage_leagues.php','manage_fixtures.php','manage_standings.php','manage_tickets.php','live_score_control.php','manage_match_events.php','manage_lineups.php','ai_match_reports.php','season_wizard.php','event_checklist.php','manage_attendance.php'])) ? 'active' : ''; ?>" href="javascript:void(0)" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-trophy"></i>Competitions
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_leagues.php') ? 'active' : ''; ?>" href="manage_leagues.php"><i class="fas fa-medal"></i>Leagues Registry</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_fixtures.php') ? 'active' : ''; ?>" href="manage_fixtures.php"><i class="fas fa-calendar-alt"></i>Match Fixtures</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'season_wizard.php') ? 'active' : ''; ?>" href="season_wizard.php"><i class="fas fa-magic"></i>Season Wizard</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'event_checklist.php') ? 'active' : ''; ?>" href="event_checklist.php"><i class="fas fa-tasks"></i>Event Checklist</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_lineups.php') ? 'active' : ''; ?>" href="manage_lineups.php"><i class="fas fa-people-group"></i>Lineup Builder</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_standings.php') ? 'active' : ''; ?>" href="manage_standings.php"><i class="fas fa-list-ol"></i>Leaderboards</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_match_events.php') ? 'active' : ''; ?>" href="manage_match_events.php"><i class="fas fa-futbol"></i>Goals &amp; Cards</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'ai_match_reports.php') ? 'active' : ''; ?>" href="ai_match_reports.php"><i class="fas fa-newspaper"></i>AI Match Reports</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'live_score_control.php' ? 'active' : ''; ?>" href="live_score_control.php"><i class="fas fa-broadcast-tower text-danger"></i>Live Score Panel <span class="badge bg-danger ms-auto" style="font-size:0.6rem;padding:2px 5px;">LIVE</span></a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_attendance.php') ? 'active' : ''; ?>" href="manage_attendance.php"><i class="fas fa-clipboard-check"></i>Attendance</a></li>
                        <li><a class="dropdown-item <?php echo ($current_page == 'manage_tickets.php') ? 'active' : ''; ?>" href="manage_tickets.php"><i class="fas fa-ticket-alt"></i>Gate Ticketing</a></li>
                    </ul>
                </li>

                <!-- Finance -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle <?php echo (in_array($current_page, ['manage_payments.php','payments_overview.php','manage_promo_codes.php','manage_refunds.php','membership_reminders.php','bulk_email.php','revenue_dashboard.php','manage_expenses.php'])) ? 'active' : ''; ?>" href="javascript:void(0)" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-credit-card"></i>Finance
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item <?php echo $current_page === 'revenue_dashboard.php' ? 'active' : ''; ?>" href="revenue_dashboard.php"><i class="fas fa-chart-line"></i>Revenue Dashboard</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'payments_overview.php' ? 'active' : ''; ?>" href="payments_overview.php"><i class="fas fa-arrow-right-arrow-left"></i>Payments Overview</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'manage_expenses.php' ? 'active' : ''; ?>" href="manage_expenses.php"><i class="fas fa-receipt"></i>Expenses</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'manage_payments.php' ? 'active' : ''; ?>" href="manage_payments.php"><i class="fas fa-wallet"></i>Payments</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'manage_refunds.php' ? 'active' : ''; ?>" href="manage_refunds.php"><i class="fas fa-undo text-danger"></i>Refunds</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'manage_promo_codes.php' ? 'active' : ''; ?>" href="manage_promo_codes.php"><i class="fas fa-tag"></i>Promo Codes</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'bulk_email.php' ? 'active' : ''; ?>" href="bulk_email.php"><i class="fas fa-envelope"></i>Bulk Email</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'membership_reminders.php' ? 'active' : ''; ?>" href="membership_reminders.php"><i class="fas fa-bell text-warning"></i>Renewal Reminders</a></li>
                    </ul>
                </li>

                <!-- Engage -->
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="javascript:void(0)" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="fas fa-bullhorn"></i>Engage</a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="manage_announcements.php"><i class="fas fa-bullhorn"></i>Announcements</a></li>
                        <li><a class="dropdown-item" href="manage_sponsors.php"><i class="fas fa-handshake"></i>Sponsors</a></li>
                        <li><a class="dropdown-item" href="manage_polls.php"><i class="fas fa-poll"></i>Polls</a></li>
                        <li><a class="dropdown-item" href="manage_forum.php"><i class="fas fa-comments"></i>Forum Moderation</a></li>
                        <li><a class="dropdown-item" href="manage_gallery.php"><i class="fas fa-images"></i>Gallery</a></li>
                        <li><a class="dropdown-item" href="manage_volunteers.php"><i class="fas fa-hands-helping"></i>Volunteers</a></li>
                        <li><a class="dropdown-item" href="manage_waiting_list.php"><i class="fas fa-hourglass-half"></i>Waiting List</a></li>
                        <li><a class="dropdown-item" href="manage_injuries.php"><i class="fas fa-notes-medical"></i>Injury Log</a></li>
                    </ul>
                </li>

            </ul>

            <!-- Right side: search + theme + admin profile dropdown -->
            <div class="d-flex align-items-center gap-2 ms-lg-3 mt-3 mt-lg-0">

                <!-- Search -->
                <button class="search-btn" onclick="openSearch()" title="Quick Search (Ctrl+K)" style="position:relative;">
                    <i class="fas fa-search"></i>
                    <kbd class="search-shortcut">⌘K</kbd>
                </button>

                <!-- Theme Toggle -->
                <button class="theme-btn" onclick="toggleTheme()" id="themeBtn" title="Toggle Dark Mode">
                    <i class="fas fa-moon"></i>
                </button>

                <!-- Admin Profile Dropdown -->
                <div class="dropdown ms-1">
                    <a href="javascript:void(0)" class="admin-avatar-wrap" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" style="cursor:pointer;">
                        <div class="admin-avatar" style="position:relative;">
                            <?php echo htmlspecialchars($admin_initials); ?>
                            <?php if ($pending_count > 0): ?>
                                <span class="avatar-badge"><?php echo $pending_count > 99 ? '99+' : $pending_count; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="d-none d-xl-block">
                            <div class="admin-name"><?php echo htmlspecialchars($admin_name); ?></div>
                            <div class="admin-role">Administrator</div>
                        </div>
                        <i class="fas fa-chevron-down d-none d-xl-inline" style="font-size:.6rem;color:rgba(255,255,255,.35);margin-left:.15rem;"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end profile-dropdown-menu">
                        <li class="px-1 pt-1"><div class="profile-card-header">
                            <div class="admin-avatar"><?php echo htmlspecialchars($admin_initials); ?></div>
                            <div class="profile-card-info">
                                <div class="profile-card-name"><?php echo htmlspecialchars($admin_name); ?></div>
                                <div class="profile-card-email"><?php echo htmlspecialchars($_SESSION['admin_email'] ?? ''); ?></div>
                                <div class="profile-card-role"><i class="fas fa-shield-halved" style="font-size:.5rem;"></i> Administrator</div>
                            </div>
                        </div></li>
                        <li class="px-1"><hr class="profile-dropdown-divider"></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'admin_profile.php' ? 'active-item' : ''; ?>" href="admin_profile.php"><i class="fas fa-user-circle"></i>My Profile</a></li>
                        <li class="px-1"><hr class="profile-dropdown-divider"></li>
                        <div class="profile-dropdown-header">AI & Tools</div>
                        <li><a class="dropdown-item <?php echo $current_page === 'gemini_hub.php' ? 'active-item' : ''; ?>" href="gemini_hub.php"><i class="fas fa-wand-magic-sparkles"></i>Gemini AI Hub</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'cron_ai_settings.php' ? 'active-item' : ''; ?>" href="cron_ai_settings.php"><i class="fas fa-clock"></i>AI Cron Settings</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'ai_review_log.php' ? 'active-item' : ''; ?>" href="ai_review_log.php"><i class="fas fa-robot"></i>AI Review Log</a></li>
                        <li class="px-1"><hr class="profile-dropdown-divider"></li>
                        <div class="profile-dropdown-header">System</div>
                        <li><a class="dropdown-item <?php echo $current_page === 'admin_setup_2fa.php' ? 'active-item' : ''; ?>" href="admin_setup_2fa.php"><i class="fas fa-shield-halved"></i>Two-Factor Auth</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'activity_log.php' ? 'active-item' : ''; ?>" href="activity_log.php"><i class="fas fa-clock-rotate-left"></i>Activity Log</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'security_events.php' ? 'active-item' : ''; ?>" href="security_events.php"><i class="fas fa-shield-halved"></i>Security Events <span class="badge bg-danger ms-1" style="font-size:0.55rem;">SEC</span></a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'backup_database.php' ? 'active-item' : ''; ?>" href="backup_database.php"><i class="fas fa-database"></i>Backup Database</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'system_health.php' ? 'active-item' : ''; ?>" href="system_health.php"><i class="fas fa-heartbeat"></i>System Health</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'slow_pages.php' ? 'active-item' : ''; ?>" href="slow_pages.php"><i class="fas fa-gauge-high"></i>Slow Pages <span class="badge bg-info ms-1" style="font-size:0.55rem;">PROF</span></a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'upload_storage.php' ? 'active-item' : ''; ?>" href="upload_storage.php"><i class="fas fa-cloud-arrow-up"></i>Object Storage</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'manage_roles.php' ? 'active-item' : ''; ?>" href="manage_roles.php"><i class="fas fa-users-cog"></i>Roles &amp; Permissions</a></li>
                        <li><a class="dropdown-item <?php echo $current_page === 'notifications.php' ? 'active-item' : ''; ?>" href="notifications.php"><i class="fas fa-bell"></i>Notifications</a></li>
                        <li class="px-1"><hr class="profile-dropdown-divider"></li>
                        <li><a class="dropdown-item logout-item" href="admin_logout.php"><i class="fas fa-power-off"></i>Sign Out</a></li>
                    </ul>
                </div>

            </div>
        </div>
    </div>
</nav>

<?php
// ── Breadcrumb mapper ───────────────────────────────────────────────────
$breadcrumb_labels = [
    'admin_dashboard.php' => 'Dashboard',
    'manage_members.php' => 'Members',
    'manage_coaches.php' => 'Coaches',
    'coach_session_notes.php' => 'Coach Notes',
    'manage_sports.php' => 'Sports',
    'manage_facilities.php' => 'Facilities',
    'manage_equipment.php' => 'Equipment',
    'manage_equipment_borrow.php' => 'Equipment Loans',
    'manage_bookings.php' => 'Bookings',
    'booking_overview.php' => 'Booking Calendar',
    'manage_coach_availability.php' => 'Coach Availability',
    'manage_maintenance.php' => 'Maintenance',
    'manage_leagues.php' => 'Leagues',
    'manage_fixtures.php' => 'Fixtures',
    'manage_standings.php' => 'Standings',
    'manage_tickets.php' => 'Tickets',
    'live_score_control.php' => 'Live Scores',
    'manage_match_events.php' => 'Match Events',
    'manage_lineups.php' => 'Lineups',
    'manage_payments.php' => 'Payments',
    'payments_overview.php' => 'Payments Overview',
    'manage_refunds.php' => 'Refunds',
    'manage_announcements.php' => 'Announcements',
    'manage_sponsors.php' => 'Sponsors',
    'manage_polls.php' => 'Polls',
    'manage_forum.php' => 'Forum',
    'manage_gallery.php' => 'Gallery',
    'manage_volunteers.php' => 'Volunteers',
    'manage_waiting_list.php' => 'Waiting List',
    'manage_injuries.php' => 'Injuries',
    'manage_expenses.php' => 'Expenses',
    'revenue_dashboard.php' => 'Revenue',
    'manage_attendance.php' => 'Attendance',
    'manage_damage_reports.php' => 'Damage Reports',
    'bulk_email.php' => 'Bulk Email',
    'membership_reminders.php' => 'Renewals',
    'ai_match_reports.php' => 'AI Match Reports',
    'ai_predictions.php' => 'AI Predictions',
    'ai_smart_scheduling.php' => 'AI Scheduling',
    'ai_review_log.php' => 'AI Review Log',
    'cron_ai_settings.php' => 'AI Cron Settings',
    'todo_list.php' => 'To-Do List',
    'activity_log.php' => 'Activity Log',
    'security_events.php' => 'Security Events',
    'admin_setup_2fa.php' => '2FA Setup',
    'gemini_hub.php' => 'Gemini Hub',
    'admin_login.php' => 'Login',
    'export_fixtures.php' => 'Export Fixtures',
    'export_payments.php' => 'Export Payments',
    'backup_database.php' => 'Backup',
    'system_health.php' => 'System Health',
    'slow_pages.php' => 'Slow Pages',
    'upload_storage.php' => 'Object Storage',
    'event_checklist.php' => 'Event Checklist',
    'season_wizard.php' => 'Season Wizard',
    'notifications.php' => 'Notifications',
    'churn_prediction.php' => 'Churn Prediction',
    'manage_roles.php' => 'Roles & Permissions',
];
$page_label = isset($breadcrumb_labels[$current_page]) ? $breadcrumb_labels[$current_page] : ucfirst(str_replace(['.php', '_'], ['', ' '], $current_page));
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb">
    <ol class="breadcrumb breadcrumb-apex mb-0 px-3 px-lg-4 pt-2 pb-0">
        <li class="breadcrumb-item"><a href="admin_dashboard.php"><i class="fas fa-home me-1" style="font-size:0.7rem;"></i>Home</a></li>
        <?php if ($current_page !== 'admin_dashboard.php'): ?>
            <li class="breadcrumb-item active" aria-current="page"><?php echo htmlspecialchars($page_label); ?></li>
        <?php endif; ?>
    </ol>
</nav>

<main id="main-content" class="container-fluid px-lg-4 mb-5">
