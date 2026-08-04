<?php
/**
 * includes/profiler.php
 * Lightweight request profiler.
 *
 * - AscProfiler::start()     — begin timing (called from config/db_connect.php).
 * - AscProfiler::hit()       — increments the query counter (from AscMysqli).
 * - AscProfiler::badge()     — returns the small "Page rendered in Xms" badge
 *                              rendered by includes/footer.php.
 * - AscProfiler::maybeLog()  — appends a row to the page_timings table for slow
 *                              pages (threshold: 100 ms), so slow pages are easy
 *                              to spot later without writing on every request.
 *
 * AscMysqli is a drop-in mysqli subclass that counts query()/prepare() calls.
 * It behaves exactly like mysqli; persistent ("p:") connections work the same.
 */

if (!class_exists('AscProfiler', false)) {
    class AscProfiler
    {
        /** @var float|null Start time (microtime). */
        private static $start = null;

        /** @var int Query counter. */
        private static $queryCount = 0;

        /** @var bool Whether the page_timings table exists (checked once). */
        private static $tableChecked = false;

        /** @var bool Cached result of the table existence check. */
        private static $tableExists = false;

        /** Slow-page threshold in milliseconds (configurable via ASC_PROFILER_SLOW_MS). */
        public static function slowMs(): int
        {
            $env = getenv('ASC_PROFILER_SLOW_MS');
            return ($env !== false && is_numeric($env)) ? (int) $env : 100;
        }

        public static function start(): void
        {
            if (self::$start === null) {
                self::$start = microtime(true);
            }
        }

        public static function hit(): void
        {
            self::$queryCount++;
        }

        public static function queryCount(): int
        {
            return self::$queryCount;
        }

        public static function elapsedMs(): float
        {
            return self::$start === null ? 0.0 : (microtime(true) - self::$start) * 1000;
        }

        public static function isActive(): bool
        {
            return self::$start !== null;
        }

        /**
         * Return the badge HTML. Empty string when the profiler was never started.
         */
        public static function badge(): string
        {
            if (self::$start === null) {
                return '';
            }
            $ms  = round(self::elapsedMs(), 1);
            $mem = round(memory_get_peak_usage(true) / 1048576, 1);
            $q   = self::$queryCount;
            return '<div class="asc-profiler-badge" title="Render time · DB queries · peak memory">'
                . '<i class="fas fa-bolt" style="font-size:0.6rem;margin-right:4px;"></i>'
                . $ms . ' ms &middot; ' . $q . ' queries &middot; ' . $mem . ' MB</div>';
        }

        /**
         * Record slow pages into the page_timings table. Writes only when the
         * page took longer than the threshold, so fast requests cost nothing.
         *
         * Housekeeping (30-day retention) runs probabilistically on ANY call,
         * not just slow pages, so quiet sites still prune the table.
         *
         * @param mysqli|null $conn Optional connection (falls back to global $conn).
         */
        public static function maybeLog($conn = null): void
        {
            if (self::$start === null) {
                return;
            }
            if ($conn === null || !($conn instanceof mysqli)) {
                global $conn;
            }
            if (!isset($conn) || !($conn instanceof mysqli)) {
                return;
            }

            // Retention housekeeping — ~1 in 300 calls, regardless of speed,
            // so the table can't grow unbounded on sites with few slow pages.
            if (mt_rand(1, 300) === 1) {
                try {
                    self::ensureTableExists($conn);
                    if (self::$tableExists) {
                        $conn->query('DELETE FROM page_timings WHERE created_at < NOW() - INTERVAL 30 DAY');
                    }
                } catch (\Throwable $e) {
                    // never let profiling break the page
                }
            }

            $ms = self::elapsedMs();
            if ($ms < self::slowMs()) {
                return; // fast page — nothing to record
            }
            // Capture stats BEFORE any profiler query so the logged values
            // match what the user saw in the badge (the SHOW TABLES / INSERT
            // below would otherwise inflate the count by 1-2).
            $q     = self::$queryCount;
            $mem   = round(memory_get_peak_usage(true) / 1048576, 1);
            $msInt = (int) round($ms);

            try {
                if (!self::ensureTableExists($conn) || !self::$tableExists) {
                    return;
                }

                $page = basename($_SERVER['SCRIPT_NAME'] ?? 'unknown');
                $stmt = $conn->prepare(
                    'INSERT INTO page_timings (page, duration_ms, query_count, memory_mb, created_at) '
                    . 'VALUES (?, ?, ?, ?, NOW())'
                );
                if ($stmt) {
                    $ok = $stmt->bind_param('siid', $page, $msInt, $q, $mem);
                    if ($ok) {
                        $stmt->execute();
                    }
                    $stmt->close();
                }
            } catch (\Throwable $e) {
                // Never let profiling break the page.
            }
        }

        /**
         * Check (once per process) whether the page_timings table exists.
         *
         * @return bool True when the check succeeded (result is in self::$tableExists).
         */
        private static function ensureTableExists($conn): bool
        {
            if (self::$tableChecked) {
                return true;
            }
            self::$tableChecked = true;
            $res = $conn->query("SHOW TABLES LIKE 'page_timings'");
            self::$tableExists = ($res && $res->num_rows > 0);
            if ($res) {
                $res->free();
            }
            return true;
        }
    }
}

/**
 * mysqli wrapper that counts every query()/prepare() against the counter.
 */
if (!class_exists('AscMysqli', false) && class_exists('mysqli')) {
    class AscMysqli extends mysqli
    {
        #[\ReturnTypeWillChange]
        public function query($query, $result_mode = MYSQLI_STORE_RESULT)
        {
            AscProfiler::hit();
            return parent::query($query, $result_mode);
        }

        #[\ReturnTypeWillChange]
        public function prepare($query)
        {
            AscProfiler::hit();
            return parent::prepare($query);
        }

        #[\ReturnTypeWillChange]
        public function real_query($query)
        {
            AscProfiler::hit();
            return parent::real_query($query);
        }

        #[\ReturnTypeWillChange]
        public function multi_query($query)
        {
            AscProfiler::hit();
            return parent::multi_query($query);
        }
    }
}
