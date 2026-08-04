-- Match events (goals, cards), MOTM, coach calendar, maintenance, promo codes, equipment alerts

CREATE TABLE IF NOT EXISTS `match_events` (
    `event_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`   INT NOT NULL,
    `team_id`      INT NOT NULL,
    `event_type`   ENUM('goal','own_goal','penalty','yellow_card','red_card') NOT NULL,
    `player_name`  VARCHAR(120) NULL,
    `member_id`    INT NULL,
    `minute`       INT UNSIGNED NULL,
    `notes`        VARCHAR(255) NULL,
    `recorded_by`  ENUM('admin','referee','system') NOT NULL DEFAULT 'admin',
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_match_events_fixture` (`fixture_id`),
    KEY `idx_match_events_team` (`team_id`),
    KEY `idx_match_events_type` (`event_type`),
    CONSTRAINT `fk_match_events_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_match_events_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_match_events_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `motm_votes` (
    `vote_id`           INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`        INT NOT NULL,
    `voter_member_id`   INT NOT NULL,
    `team_id`           INT NOT NULL,
    `player_name`       VARCHAR(120) NOT NULL,
    `member_id`         INT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_motm_fixture_voter` (`fixture_id`, `voter_member_id`),
    KEY `idx_motm_fixture` (`fixture_id`),
    CONSTRAINT `fk_motm_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_motm_voter` FOREIGN KEY (`voter_member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_motm_team` FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_motm_player_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coach_availability` (
    `availability_id` INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`        INT NOT NULL,
    `day_of_week`     TINYINT UNSIGNED NOT NULL COMMENT '0=Sunday .. 6=Saturday',
    `start_time`      TIME NOT NULL,
    `end_time`        TIME NOT NULL,
    `is_available`    TINYINT(1) NOT NULL DEFAULT 1,
    `notes`           VARCHAR(255) NULL,
    UNIQUE KEY `uq_coach_day_slot` (`coach_id`, `day_of_week`, `start_time`),
    CONSTRAINT `fk_coach_avail_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches` (`coach_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coach_availability_exceptions` (
    `exception_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`        INT NOT NULL,
    `exception_date`  DATE NOT NULL,
    `is_available`    TINYINT(1) NOT NULL DEFAULT 0,
    `start_time`      TIME NULL,
    `end_time`        TIME NULL,
    `reason`          VARCHAR(255) NULL,
    KEY `idx_coach_exc_date` (`coach_id`, `exception_date`),
    CONSTRAINT `fk_coach_exc_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches` (`coach_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `facility_maintenance` (
    `maintenance_id`  INT AUTO_INCREMENT PRIMARY KEY,
    `facility_id`     INT NOT NULL,
    `start_date`      DATE NOT NULL,
    `end_date`        DATE NOT NULL,
    `start_time`      TIME NULL,
    `end_time`        TIME NULL,
    `reason`          VARCHAR(255) NOT NULL,
    `status`          ENUM('Scheduled','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
    `blocks_bookings` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_maint_facility_dates` (`facility_id`, `start_date`, `end_date`),
    CONSTRAINT `fk_maint_facility` FOREIGN KEY (`facility_id`) REFERENCES `facilities` (`facility_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `promo_codes` (
    `promo_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `code`            VARCHAR(40) NOT NULL,
    `description`     VARCHAR(255) NULL,
    `discount_type`   ENUM('percent','fixed') NOT NULL,
    `discount_value`  DECIMAL(10,2) NOT NULL,
    `min_amount`      DECIMAL(10,2) NOT NULL DEFAULT 0,
    `valid_from`      DATE NULL,
    `valid_until`     DATE NULL,
    `max_uses`        INT UNSIGNED NULL,
    `uses_count`      INT UNSIGNED NOT NULL DEFAULT 0,
    `status`          ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_promo_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `equipment`
    ADD COLUMN `reorder_level` INT UNSIGNED NOT NULL DEFAULT 5 AFTER `quantity`;
