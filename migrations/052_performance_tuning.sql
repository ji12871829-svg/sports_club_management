-- 052_performance_tuning.sql
-- Add missing performance indexes and optimize query patterns.
--
-- NOTE: Two indexes from the original file were removed because they
-- referenced columns that do not exist in the migration-built schema:
--   - login_attempts.action_type  (login_attempts only has email/ip_address/attempted_at;
--     migration 001 already indexes both (email, attempted_at) and (ip_address, attempted_at))
--   - payments.payment_status     (created later by migration 054 — the index now
--     lives at the end of 054 where the column exists)

-- Speed up churn prediction queries (the most expensive page)
ALTER TABLE member_churn_risk ADD INDEX IF NOT EXISTS idx_mcr_member_id (member_id);
ALTER TABLE member_churn_risk ADD INDEX IF NOT EXISTS idx_mcr_risk_level_score (risk_level, risk_score);

-- Speed up admin dashboard COUNT queries
ALTER TABLE bookings ADD INDEX IF NOT EXISTS idx_bookings_status_date (status, booking_date);

-- Speed up system_health queries
ALTER TABLE damage_reports ADD INDEX IF NOT EXISTS idx_damage_reports_resolved (resolved_at);

-- Speed up member lookups
ALTER TABLE members ADD INDEX IF NOT EXISTS idx_members_email (email);

-- Speed up churn wellness analytics lookups
ALTER TABLE member_wellness_tracking ADD INDEX IF NOT EXISTS idx_mwt_member_date (member_id, tracking_date);

-- Speed up AI review log queries
ALTER TABLE ai_review_log ADD INDEX IF NOT EXISTS idx_airl_admin_time (admin_id, reviewed_at);
