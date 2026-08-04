<?php
/**
 * performance_tuning.php
 * 
 * Applies MySQL runtime performance optimizations.
 * Run once: php performance_tuning.php
 * 
 * Changes are session-level (lost on MySQL restart).
 * For permanent changes, edit my.ini — see instructions at bottom.
 */

require_once 'config/db_connect.php';

echo "=== Apex Sports Club — Performance Tuning ===" . PHP_EOL . PHP_EOL;

// ── 1. MySQL Runtime Tuning ──────────────────────────────────────────
$tuning = [
    // InnoDB buffer pool — cache data/indexes in RAM
    'innodb_buffer_pool_size' => '134217728',  // 128MB (XAMPP default is 16MB!)
    
    // InnoDB log buffer — reduce disk I/O for writes
    'innodb_log_buffer_size' => '8388608',     // 8MB
    
    // InnoDB flush method — O_DIRECT avoids double-buffering on Windows
    'innodb_flush_method' => 'normal',         // Windows-safe (not O_DIRECT)
    
    // Query cache — cache SELECT results (small dataset, high benefit)
    'query_cache_type' => '1',                 // ON
    'query_cache_size' => '16777216',          // 16MB
    'query_cache_limit' => '2097152',          // 2MB per query
    
    // Table cache — avoid reopening table handles
    'table_open_cache' => '400',
    
    // Thread cache — reuse connections
    'thread_cache_size' => '8',
    
    // Sort buffer — speed up ORDER BY (used by churn prediction)
    'sort_buffer_size' => '2097152',           // 2MB
    
    // Join buffer — speed up JOINs without indexes
    'join_buffer_size' => '2097152',           // 2MB
    
    // Temp table — avoid disk-based temp tables
    'tmp_table_size' => '67108864',            // 64MB
    'max_heap_table_size' => '67108864',       // 64MB
    
    // Slow query log — enable for future diagnostics
    'slow_query_log' => '1',
    'long_query_time' => '2',                  // Log queries > 2 seconds
];

$applied = 0;
$failed = 0;

foreach ($tuning as $var => $value) {
    $check = $conn->query("SHOW VARIABLES LIKE '$var'");
    $old = $check ? $check->fetch_assoc()['Value'] : '?';
    
    try {
        $result = $conn->query("SET GLOBAL $var = $value");
        if ($result) {
            echo "  ✓ $var: $old → $value" . PHP_EOL;
            $applied++;
        } else {
            echo "  ✗ $var: failed — " . $conn->error . PHP_EOL;
            $failed++;
        }
    } catch (\Throwable $e) {
        echo "  ≈ $var: read-only (needs my.ini) — current: $old" . PHP_EOL;
        $failed++;
    }
}

echo PHP_EOL . "Applied: $applied | Failed: $failed" . PHP_EOL;

// ── 2. Verify query cache is working ─────────────────────────────────
echo PHP_EOL . "=== Query Cache Status ===" . PHP_EOL;
$qc = $conn->query("SHOW STATUS LIKE 'Qcache%'");
if ($qc) {
    while ($row = $qc->fetch_assoc()) {
        echo "  {$row['Variable_name']}: {$row['Value']}" . PHP_EOL;
    }
}

// ── 3. Verify indexes exist ──────────────────────────────────────────
echo PHP_EOL . "=== Key Indexes ===" . PHP_EOL;
$indexes = $conn->query("
    SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = 'sports_club_db'
    AND INDEX_NAME != 'PRIMARY'
    AND TABLE_NAME IN ('bookings','payments','members','member_churn_risk','login_attempts')
    GROUP BY TABLE_NAME, INDEX_NAME
    ORDER BY TABLE_NAME, INDEX_NAME
");
if ($indexes) {
    while ($row = $indexes->fetch_assoc()) {
        echo "  {$row['TABLE_NAME']}.{$row['INDEX_NAME']}({$row['columns']})" . PHP_EOL;
    }
}

echo PHP_EOL . "=== Done ===" . PHP_EOL;
echo "Note: These settings are SESSION-LEVEL and reset on MySQL restart." . PHP_EOL;
echo "For permanent tuning, edit C:\\xampp\\mysql\\bin\\my.ini:" . PHP_EOL;
echo "  Add under [mysqld] section:" . PHP_EOL;
echo "  innodb_buffer_pool_size=128M" . PHP_EOL;
echo "  query_cache_type=1" . PHP_EOL;
echo "  query_cache_size=16M" . PHP_EOL;
echo "  slow_query_log=1" . PHP_EOL;
echo "  long_query_time=2" . PHP_EOL;
