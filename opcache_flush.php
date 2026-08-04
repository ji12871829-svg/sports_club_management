<?php
/**
 * opcache_flush.php
 * 
 * Flushes the OPcache so newly edited PHP files are picked up immediately.
 * Run after making code changes: php opcache_flush.php
 * 
 * Works from CLI and from web (if opcache.enable_cli=1 or via a web request).
 */

// Try to clear OPcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo "✓ OPcache reset successfully." . PHP_EOL;
} elseif (function_exists('opcache_invalidate')) {
    // Fallback: invalidate all files
    $files = glob(__DIR__ . '/{admin,public,includes,config}/*.php', GLOB_BRACE);
    $count = 0;
    foreach ($files as $file) {
        if (opcache_invalidate($file, true)) {
            $count++;
        }
    }
    echo "✓ Invalidated $count PHP files from OPcache." . PHP_EOL;
} else {
    echo "⚠ OPcache extension not loaded. No action needed." . PHP_EOL;
    echo "  (OPcache is only active in the web server, not CLI.)" . PHP_EOL;
}

// Also show current OPcache status if available
if (function_exists('opcache_get_status')) {
    $status = opcache_get_status(false);
    if ($status) {
        echo PHP_EOL . "=== OPcache Status ===" . PHP_EOL;
        echo "  Enabled: " . ($status['opcache_enabled'] ? 'yes' : 'no') . PHP_EOL;
        echo "  Memory used: " . round($status['memory_usage']['used_memory'] / 1024 / 1024, 2) . " MB" . PHP_EOL;
        echo "  Memory free: " . round($status['memory_usage']['free_memory'] / 1024 / 1024, 2) . " MB" . PHP_EOL;
        echo "  Cached scripts: " . $status['opcache_statistics']['num_cached_scripts'] . PHP_EOL;
        echo "  Hit rate: " . round($status['opcache_statistics']['opcache_hit_rate'], 1) . "%" . PHP_EOL;
    }
}
