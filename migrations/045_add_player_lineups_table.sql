-- 045_add_player_lineups_table.sql
-- Creates the lineups table used by ai_player_summary.php to count
-- how many matches each player has appeared in.
--
-- This is a simple match-appearance tracker separate from fixture_lineups
-- (which stores team formations and starting XIs).

CREATE TABLE IF NOT EXISTS `lineups` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fixture_id` INT UNSIGNED NOT NULL,
    `member_id`  INT UNSIGNED NOT NULL,
    `played`     TINYINT(1)   NOT NULL DEFAULT 1,
    UNIQUE KEY `uq_fixture_member` (`fixture_id`, `member_id`),
    KEY `idx_member` (`member_id`),
    KEY `idx_fixture` (`fixture_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
