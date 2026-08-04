-- Migration 014: Player stats, seasons, refunds, activity feed, GDPR requests

-- ── Seasons table (for season archive) ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `seasons` (
    `season_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `name`         VARCHAR(100) NOT NULL UNIQUE,
    `start_date`   DATE NOT NULL,
    `end_date`     DATE NOT NULL,
    `is_current`   TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Link leagues to seasons
ALTER TABLE `leagues`
    ADD COLUMN IF NOT EXISTS `season_id` INT NULL AFTER `league_id`,
    ADD COLUMN IF NOT EXISTS `season_name` VARCHAR(100) NULL AFTER `season_id`;

-- ── Refunds table ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `refunds` (
    `refund_id`      INT AUTO_INCREMENT PRIMARY KEY,
    `payment_id`     INT NOT NULL,
    `member_id`      INT NOT NULL,
    `amount`         DECIMAL(10,2) NOT NULL,
    `refund_type`    ENUM('full','partial') NOT NULL DEFAULT 'full',
    `reason`         TEXT NULL,
    `status`         ENUM('Pending','Processed','Failed') NOT NULL DEFAULT 'Pending',
    `processed_by`   INT NULL COMMENT 'admin_id',
    `processed_at`   DATETIME NULL,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_refund_payment`  (`payment_id`),
    KEY `idx_refund_member`   (`member_id`),
    CONSTRAINT `fk_refund_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_refund_member`  FOREIGN KEY (`member_id`)  REFERENCES `members`  (`member_id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Activity feed table ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `activity_feed` (
    `feed_id`      INT AUTO_INCREMENT PRIMARY KEY,
    `event_type`   VARCHAR(60) NOT NULL COMMENT 'goal, result, new_member, booking, motm, card',
    `title`        VARCHAR(200) NOT NULL,
    `description`  TEXT NULL,
    `icon`         VARCHAR(10)  NULL,
    `color`        VARCHAR(20)  NULL,
    `member_id`    INT NULL,
    `fixture_id`   INT NULL,
    `link_url`     VARCHAR(255) NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_feed_created` (`created_at`),
    KEY `idx_feed_type`    (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── GDPR data export requests ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `data_export_requests` (
    `request_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`    INT NOT NULL,
    `status`       ENUM('Pending','Ready','Downloaded','Expired') NOT NULL DEFAULT 'Pending',
    `token`        VARCHAR(64) NOT NULL UNIQUE,
    `file_path`    VARCHAR(255) NULL,
    `expires_at`   DATETIME NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_export_member` (`member_id`),
    CONSTRAINT `fk_export_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Member of the month ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `member_of_month` (
    `motm_id`      INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`    INT NOT NULL,
    `month`        DATE NOT NULL COMMENT 'First day of the month',
    `reason`       TEXT NULL,
    `nominated_by` INT NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_motm_month` (`month`),
    CONSTRAINT `fk_motm_month_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
