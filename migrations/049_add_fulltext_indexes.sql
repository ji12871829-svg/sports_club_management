-- ============================================================
--  049_add_fulltext_indexes.sql
--  FULLTEXT Indexes for Search Performance
--
--  NOTE: Rewritten to match the column names actually created by
--  migrations 001-048. The original version referenced columns from
--  the removed database.sql (coaches.specialty, sports.sport_name,
--  payments.reference_number, a nonexistent venues table, and
--  forum_posts.content). All indexes use IF NOT EXISTS so re-runs
--  on existing databases are no-ops.
-- ============================================================

-- Members search (name + email)
ALTER TABLE members ADD FULLTEXT INDEX IF NOT EXISTS ft_members_search (first_name, last_name, email);

-- Coaches search (name + specialization)
ALTER TABLE coaches ADD FULLTEXT INDEX IF NOT EXISTS ft_coaches_search (first_name, last_name, specialization);

-- Fixtures search (venue only; teams are stored as FK ids, not names)
ALTER TABLE fixtures ADD FULLTEXT INDEX IF NOT EXISTS ft_fixtures_search (venue);

-- Sports search (name + description)
ALTER TABLE sports ADD FULLTEXT INDEX IF NOT EXISTS ft_sports_search (name, description);

-- Payments search (description; provider_reference is added by migration 054)
ALTER TABLE payments ADD FULLTEXT INDEX IF NOT EXISTS ft_payments_search (description);

-- Forum posts search (title + body)
ALTER TABLE forum_posts ADD FULLTEXT INDEX IF NOT EXISTS ft_forum_search (title, body);
