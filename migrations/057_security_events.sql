-- Migration 057: Security events table for alerting / probe detection
-- Date: 2026-08-04
--
-- Records security-relevant events (rate-limit hits, CSRF rejections,
-- webhook/callback failures, auth lockouts) so a daily digest cron can
-- surface probing and abuse. Insert-only; retention handled by the digest
-- cron and probabilistic housekeeping.

CREATE TABLE IF NOT EXISTS `security_events` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `event_type` VARCHAR(40) NOT NULL,
    `severity` ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `actor` VARCHAR(120) NULL DEFAULT NULL,
    `details` VARCHAR(500) NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_sec_events_type_time` (`event_type`, `created_at`),
    INDEX `idx_sec_events_ip` (`ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
