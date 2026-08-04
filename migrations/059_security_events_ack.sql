-- Migration 059: Acknowledge / notes on security events
-- Date: 2026-08-04
--
-- Lets admins mark a security event as reviewed/acknowledged (with an
-- optional note) from admin/security_events.php. Acknowledged events are
-- excluded from the daily digest email so it only surfaces what still
-- needs attention.

ALTER TABLE `security_events`
    ADD COLUMN `acknowledged` TINYINT(1) NOT NULL DEFAULT 0 AFTER `details`,
    ADD COLUMN `acknowledged_by` VARCHAR(120) NULL DEFAULT NULL AFTER `acknowledged`,
    ADD COLUMN `acknowledged_at` TIMESTAMP NULL DEFAULT NULL AFTER `acknowledged_by`,
    ADD COLUMN `notes` VARCHAR(500) NULL DEFAULT NULL AFTER `acknowledged_at`,
    ADD INDEX `idx_sec_events_ack` (`acknowledged`);
