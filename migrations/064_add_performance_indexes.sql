-- Add missing performance indexes discovered during the production DB audit.
-- These columns are used in WHERE/JOIN clauses across the app but had no
-- index, forcing full-table scans on member directories, activity feeds,
-- AR match stats, equipment damage reports, and facility lookups.
--
-- Idempotency note: each statement is a plain ADD INDEX (MySQL does not
-- support ADD INDEX IF NOT EXISTS, MariaDB does). The migration runner
-- applies each numbered migration exactly once via checksum-tracked
-- schema_migrations, so re-runs are prevented by the runner, not the SQL.
-- The audit verified these indexes were absent before writing this file.

-- members.sport_id — member directory filtered by sport (36 files reference it)
ALTER TABLE `members`
    ADD INDEX `idx_members_sport` (`sport_id`);

-- activity_feed — per-member feed queries plus per-fixture aggregation
ALTER TABLE `activity_feed`
    ADD INDEX `idx_feed_member` (`member_id`),
    ADD INDEX `idx_feed_fixture` (`fixture_id`);

-- ar_match_events — AR match stats filtered by team (45 files) and player
ALTER TABLE `ar_match_events`
    ADD INDEX `idx_ar_events_team` (`team_id`),
    ADD INDEX `idx_ar_events_player` (`player_id`);

-- coach_session_notes.sport_id — session notes by sport (36 files)
ALTER TABLE `coach_session_notes`
    ADD INDEX `idx_csn_sport` (`sport_id`);

-- equipment_damage_reports.facility_id — damage reports by facility (17 files)
ALTER TABLE `equipment_damage_reports`
    ADD INDEX `idx_edr_facility` (`facility_id`);

-- facility_energy_management.booking_id — energy rows joined back to bookings
ALTER TABLE `facility_energy_management`
    ADD INDEX `idx_fem_booking` (`booking_id`);

-- smart_access_codes.facility_id — door codes by facility (17 files)
ALTER TABLE `smart_access_codes`
    ADD INDEX `idx_sac_facility` (`facility_id`);
