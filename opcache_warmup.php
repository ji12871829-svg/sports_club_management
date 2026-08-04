<?php
/**
 * opcache_warmup.php
 *
 * Pre-warms OPcache with every PHP entry point in the project so the first
 * real user request never pays the cold-start parse cost. Run after
 * deployment or after clearing OPcache.
 *
 * Usage:  php opcache_warmup.php
 *
 * NOTE: OPcache only runs in the web server (mod_php). CLI needs
 * opcache.enable_cli=1 to be useful; the script still scans and validates
 * every file with php -l semantics even if OPcache is unavailable.
 */

// ── 1. Discover all PHP files (skip vendor, .freebuff, tests, tmp) ──────
$root = __DIR__;
$skipDirs = ['.git', '.freebuff', 'vendor', 'node_modules', 'tests', 'presentation', 'dev', 'backup'];
$skipFiles = ['opcache_warmup.php', 'opcache_flush.php', 'tmp_', 'test_', 'seed_ai_settings.php'];

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php') {
        continue;
    }
    // Compare against the path RELATIVE to the project root, so a worktree
    // checkout living under .freebuff/… still scans its own files correctly.
    $abs = str_replace('\\', '/', $file->getPathname());
    $rel = str_replace('\\', '/', $root) . '/';
    $relPath = strpos($abs, $rel) === 0 ? substr($abs, strlen($rel)) : $abs;
    foreach ($skipDirs as $skip) {
        if (strpos('/' . $relPath, '/' . $skip . '/') !== false) {
            continue 2;
        }
    }
    $base = $file->getFilename();
    foreach ($skipFiles as $skip) {
        if (strpos($base, $skip) !== false) {
            continue 2;
        }
    }
    $files[] = $file->getPathname();
}

sort($files);

// ── 2. Warm OPcache (web mode) or validate syntax (CLI) ─────────────────
// OPcache is only truly active under mod_php. Detect a *running* instance:
// opcache_compile_file() exists but throws "not properly started" notices
// when opcache.enable_cli=Off. Check the runtime flag instead.
$opcacheActive = false;
if (function_exists('opcache_get_status')) {
    $st = @opcache_get_status(false);
    $opcacheActive = is_array($st) && !empty($st['opcache_enabled']);
}
$hasOpcache = function_exists('opcache_compile_file') && $opcacheActive;
$warmed = 0;
$skipped = 0;
$failed = 0;
$failures = [];

foreach ($files as $file) {
    // Validate syntax first (cheap, catches broken files before warming)
    $out = [];
    $code = 0;
    exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($file) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        $failed++;
        $failures[] = $file . ' — ' . trim(implode(' ', $out));
        continue;
    }

    if ($hasOpcache) {
        // Compile into OPcache without executing
        try {
            if (@opcache_compile_file($file)) {
                $warmed++;
            } else {
                $skipped++;
            }
        } catch (Throwable $e) {
            $skipped++;
        }
    } else {
        // CLI or no OPcache: the php -l above already validated syntax,
        // count it as "warmed" (will be compiled by the web server on first hit).
        $warmed++;
    }
}

// ── 3. Report ───────────────────────────────────────────────────────────
echo "=== OPcache Warmup Complete ===" . PHP_EOL;
echo "  PHP files scanned : " . count($files) . PHP_EOL;
echo "  Files warmed      : $warmed" . PHP_EOL;
echo "  Skipped (already) : $skipped" . PHP_EOL;
echo "  Failed (syntax)   : $failed" . PHP_EOL;
if ($opcacheActive) {
    $status = opcache_get_status(false);
    if ($status) {
        echo PHP_EOL . "  OPcache cached scripts: " . ($status['opcache_statistics']['num_cached_scripts'] ?? 'n/a') . PHP_EOL;
        echo "  Memory used           : " . round(($status['memory_usage']['used_memory'] ?? 0) / 1024 / 1024, 2) . " MB" . PHP_EOL;
        echo "  Hit rate              : " . round(($status['opcache_statistics']['opcache_hit_rate'] ?? 0), 1) . "%" . PHP_EOL;
    }
} else {
    echo PHP_EOL . "  (OPcache not active in this CLI run — opcache.enable_cli is Off. Syntax of every file was still validated; the web server will compile them on first hit.)" . PHP_EOL;
    echo "  For a true warmup, run this script via the web server or set opcache.enable_cli=1." . PHP_EOL;
}
if (!empty($failures)) {
    echo PHP_EOL . "=== Syntax failures ===" . PHP_EOL;
    foreach ($failures as $f) {
        echo "  ✗ $f" . PHP_EOL;
    }
}
