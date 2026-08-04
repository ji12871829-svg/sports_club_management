-- ============================================================
-- Migration 054: Payment Idempotency & Integrity
-- Adds columns needed for webhook idempotency and status tracking.
-- ============================================================

-- Add provider_reference (unique) for deduplication
ALTER TABLE payments
    ADD COLUMN IF NOT EXISTS `provider_reference` VARCHAR(120) NULL AFTER `payment_method`,
    ADD COLUMN IF NOT EXISTS `payment_status` ENUM('Pending','Completed','Failed','Refunded') NOT NULL DEFAULT 'Completed' AFTER `provider_reference`;

-- Unique index so INSERT ... ON DUPLICATE KEY UPDATE works
-- Drop first to avoid duplicates if run twice
ALTER TABLE payments
    DROP INDEX IF EXISTS `uq_payments_provider_reference`;
ALTER TABLE payments
    ADD UNIQUE INDEX `uq_payments_provider_reference` (`provider_reference`);

-- Performance indexes (for dashboard queries)
ALTER TABLE payments
    ADD INDEX IF NOT EXISTS `idx_payments_status_date` (`payment_status`, `payment_date`),
    ADD INDEX IF NOT EXISTS `idx_payments_date` (`payment_date`);

-- ── Booking concurrency protection ──────────────────────────
-- Add indexes on booking queries used by the dashboard and conflict detection
ALTER TABLE bookings
    ADD INDEX IF NOT EXISTS `idx_bookings_date_status` (`booking_date`, `status`),
    ADD INDEX IF NOT EXISTS `idx_bookings_facility_date` (`facility_id`, `booking_date`, `start_time`, `end_time`);