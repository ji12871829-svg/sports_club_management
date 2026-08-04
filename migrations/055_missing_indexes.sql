-- ============================================================
-- Migration 055: Missing Performance Indexes
-- ============================================================

-- Members: email is already UNIQUE (indexed). Add phone lookup index.
ALTER TABLE members
    ADD INDEX IF NOT EXISTS `idx_members_phone` (`phone_number`(15));

-- Bookings: additional indexes
ALTER TABLE bookings
    ADD INDEX IF NOT EXISTS `idx_bookings_member_date` (`member_id`, `booking_date`),
    ADD INDEX IF NOT EXISTS `idx_bookings_status` (`status`);

-- Payments: member lookup
ALTER TABLE payments
    ADD INDEX IF NOT EXISTS `idx_payments_member_id` (`member_id`);

-- Login attempts: clean up old indexes are already there (idx_email_time, idx_ip_time)
-- Fixtures: league + date for schedule queries
ALTER TABLE fixtures
    ADD INDEX IF NOT EXISTS `idx_fixtures_league_date` (`league_id`, `match_date`),
    ADD INDEX IF NOT EXISTS `idx_fixtures_status` (`status`);