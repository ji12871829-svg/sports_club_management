    </div>
    <?php
    require_once __DIR__ . '/../config/api_config.php';
    require_once __DIR__ . '/assets.php';
    require_once __DIR__ . '/profiler.php';
    ?>
    <!-- Footer with privacy link -->
    <footer class="container mt-5 pt-4 border-top text-center text-muted small">
        <p class="mb-1">&copy; <?php echo date('Y'); ?> Apex Sports Club. All rights reserved.</p>
        <p class="mb-0">
            <a href="<?php echo BASE_URL; ?>/public/privacy.php">Privacy Policy</a>
            &middot; <a href="<?php echo BASE_URL; ?>/public/index.php">Home</a>
            <?php if (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] === true): ?>
                &middot; <a href="<?php echo BASE_URL; ?>/admin/admin_dashboard.php">Admin Dashboard</a>
            <?php elseif (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true): ?>
                &middot; <a href="<?php echo BASE_URL; ?>/public/dashboard.php">My Dashboard</a>
            <?php endif; ?>
        </p>
    </footer>
    <!-- Bootstrap JS and dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Custom JS -->
    <script src="<?php echo asc_asset(BASE_URL . '/public/js/script.js', __DIR__ . '/../public/js/script.js'); ?>"></script>

    <?php
    // Performance badge — shown on admin pages (and when ASC_DEBUG is on).
    if (class_exists('AscProfiler') && AscProfiler::isActive()) {
        $showBadge = (getenv('ASC_DEBUG') === '1')
            || (isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] === true);
        if ($showBadge) {
            echo AscProfiler::badge();
            AscProfiler::maybeLog();
        }
    }
    ?>
</body>
</html>
