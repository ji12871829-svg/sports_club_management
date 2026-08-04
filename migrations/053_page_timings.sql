-- 053: Page timing log for the AscProfiler (slow-page spotting)
-- Only slow pages (>100 ms by default) are recorded, so this stays small.
-- Retention: AscProfiler::maybeLog() deletes rows older than 30 days on a
-- ~1-in-200 slow-page insert, so the table never grows unbounded without
-- adding a delete on every request.
CREATE TABLE IF NOT EXISTS `page_timings` (
    `timing_id`   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `page`        VARCHAR(255) NOT NULL,
    `duration_ms` DECIMAL(8,1) NOT NULL DEFAULT 0,
    `query_count` INT NOT NULL DEFAULT 0,
    `memory_mb`   DECIMAL(6,1) NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_timings_created` (`created_at`),
    KEY `idx_timings_slow` (`duration_ms`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
