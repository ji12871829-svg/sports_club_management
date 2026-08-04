-- Fixture lineups (starting XI + bench)

CREATE TABLE IF NOT EXISTS `fixture_lineups` (
    `lineup_id`      INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`     INT NOT NULL,
    `team_id`        INT NOT NULL,
    `formation`      VARCHAR(30) NULL,
    `published_by`   INT NULL COMMENT 'admin_id',
    `is_published`   TINYINT(1) NOT NULL DEFAULT 1,
    `published_at`   DATETIME NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_fixture_team_lineup` (`fixture_id`, `team_id`),
    KEY `idx_lineups_fixture` (`fixture_id`),
    CONSTRAINT `fk_lineups_fixture`
        FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lineups_team`
        FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lineups_admin`
        FOREIGN KEY (`published_by`) REFERENCES `admins` (`admin_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `fixture_lineup_players` (
    `lineup_player_id` INT AUTO_INCREMENT PRIMARY KEY,
    `lineup_id`        INT NOT NULL,
    `member_id`        INT NOT NULL,
    `slot_type`        ENUM('starter', 'substitute') NOT NULL DEFAULT 'starter',
    `jersey_number`    TINYINT UNSIGNED NULL,
    `position_label`   VARCHAR(40) NULL,
    `sort_order`       TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_lineup_member` (`lineup_id`, `member_id`),
    KEY `idx_lineup_slot` (`lineup_id`, `slot_type`, `sort_order`),
    CONSTRAINT `fk_lineup_players_lineup`
        FOREIGN KEY (`lineup_id`) REFERENCES `fixture_lineups` (`lineup_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lineup_players_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
