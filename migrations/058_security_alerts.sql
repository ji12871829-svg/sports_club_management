-- Migration 058: Real-time security alert throttle log
-- Date: 2026-08-04
--
-- One row per alert email actually sent, keyed by alert type. The
-- log_security_event() critical path checks this table (e.g. last 15 min)
-- before firing an immediate email, so an attack flood produces a single
-- alert per window instead of an email storm. Retention is handled
-- probabilistically by the daily digest cron.

CREATE TABLE IF NOT EXISTS `security_alert_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `alert_type` VARCHAR(60) NOT NULL,
    `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sec_alert_type_time` (`alert_type`, `sent_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
