USE `sports_club_db`;

-- -------------------------------------------------------
--  FIXTURES  (scheduled matches between two teams)
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fixtures` (
    `fixture_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `league_id`     INT NOT NULL,
    `home_team_id`  INT NOT NULL,
    `away_team_id`  INT NOT NULL,
    `match_date`    DATE NOT NULL,
    `match_time`    TIME NOT NULL DEFAULT '15:00:00',
    `venue`         VARCHAR(150),
    `matchday`      INT NOT NULL DEFAULT 1,          -- Matchday / round number
    `status`        ENUM('Scheduled','Completed','Postponed','Cancelled')
                        NOT NULL DEFAULT 'Scheduled',
    -- Result fields (filled once match is Completed)
    `home_score`    TINYINT UNSIGNED NULL DEFAULT NULL,
    `away_score`    TINYINT UNSIGNED NULL DEFAULT NULL,
    `notes`         TEXT,
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- No team can play itself
    CONSTRAINT `chk_different_teams` CHECK (`home_team_id` <> `away_team_id`),

    -- Foreign keys
    CONSTRAINT `fk_fixtures_league`
        FOREIGN KEY (`league_id`)    REFERENCES `leagues` (`league_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fixtures_home`
        FOREIGN KEY (`home_team_id`) REFERENCES `teams`   (`team_id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_fixtures_away`
        FOREIGN KEY (`away_team_id`) REFERENCES `teams`   (`team_id`)   ON DELETE CASCADE,

    KEY `idx_fixtures_league`    (`league_id`),
    KEY `idx_fixtures_match_date`(`match_date`),
    KEY `idx_fixtures_status`    (`status`)
);

-- -------------------------------------------------------
--  STANDINGS  (auto-recalculated league position table)
--  One row per team per league; updated whenever a result
--  is recorded in `fixtures`.
-- -------------------------------------------------------
CREATE TABLE IF NOT EXISTS `standings` (
    `standing_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `league_id`     INT NOT NULL,
    `team_id`       INT NOT NULL,
    `played`        INT UNSIGNED NOT NULL DEFAULT 0,
    `won`           INT UNSIGNED NOT NULL DEFAULT 0,
    `drawn`         INT UNSIGNED NOT NULL DEFAULT 0,
    `lost`          INT UNSIGNED NOT NULL DEFAULT 0,
    `goals_for`     INT UNSIGNED NOT NULL DEFAULT 0,
    `goals_against` INT UNSIGNED NOT NULL DEFAULT 0,
    `goal_diff`     INT          NOT NULL DEFAULT 0,  -- computed: goals_for - goals_against
    `points`        INT UNSIGNED NOT NULL DEFAULT 0,  -- W=3, D=1, L=0
    `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_standing_league_team` (`league_id`, `team_id`),
    KEY `idx_standings_league`  (`league_id`),

    CONSTRAINT `fk_standings_league`
        FOREIGN KEY (`league_id`) REFERENCES `leagues` (`league_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_standings_team`
        FOREIGN KEY (`team_id`)   REFERENCES `teams`   (`team_id`)   ON DELETE CASCADE
);

-- -------------------------------------------------------
--  Seed standings rows for every existing team
--  (only inserts rows that don't already exist)
-- -------------------------------------------------------
INSERT IGNORE INTO `standings` (`league_id`, `team_id`)
SELECT `league_id`, `team_id` FROM `teams`;
