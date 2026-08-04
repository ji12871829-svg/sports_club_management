-- Migration 020: Notifications, announcements, polls, sponsors, revenue, whatsapp, match features

-- ── Admin notifications ───────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `admin_notifications` (
    `notification_id` INT AUTO_INCREMENT PRIMARY KEY,
    `type`            VARCHAR(60)  NOT NULL COMMENT 'damage_report, payment_failed, membership_expiry, etc',
    `title`           VARCHAR(200) NOT NULL,
    `message`         TEXT         NULL,
    `link_url`        VARCHAR(255) NULL,
    `is_read`         TINYINT(1)   NOT NULL DEFAULT 0,
    `admin_id`        INT          NULL COMMENT 'NULL means all admins',
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_notif_read`    (`is_read`),
    INDEX `idx_notif_admin`   (`admin_id`),
    INDEX `idx_notif_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Scheduled announcements ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `announcements` (
    `announcement_id` INT AUTO_INCREMENT PRIMARY KEY,
    `title`           VARCHAR(200) NOT NULL,
    `body`            TEXT         NOT NULL,
    `publish_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`      DATETIME     NULL,
    `is_pinned`       TINYINT(1)   NOT NULL DEFAULT 0,
    `created_by`      INT          NULL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_ann_publish` (`publish_at`),
    INDEX `idx_ann_pinned`  (`is_pinned`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Member polls ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `polls` (
    `poll_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `question`   VARCHAR(255) NOT NULL,
    `expires_at` DATETIME     NULL,
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `created_by` INT          NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `poll_options` (
    `option_id`  INT AUTO_INCREMENT PRIMARY KEY,
    `poll_id`    INT          NOT NULL,
    `option_text` VARCHAR(200) NOT NULL,
    `vote_count` INT          NOT NULL DEFAULT 0,
    INDEX `idx_poll_option_poll` (`poll_id`),
    CONSTRAINT `fk_poll_option` FOREIGN KEY (`poll_id`) REFERENCES `polls` (`poll_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `poll_votes` (
    `vote_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `poll_id`    INT NOT NULL,
    `option_id`  INT NOT NULL,
    `member_id`  INT NOT NULL,
    `voted_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_poll_member` (`poll_id`, `member_id`),
    CONSTRAINT `fk_poll_vote_poll`   FOREIGN KEY (`poll_id`)   REFERENCES `polls`        (`poll_id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_poll_vote_option` FOREIGN KEY (`option_id`) REFERENCES `poll_options` (`option_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_poll_vote_member` FOREIGN KEY (`member_id`) REFERENCES `members`      (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Sponsors ──────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `sponsors` (
    `sponsor_id`  INT AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(150) NOT NULL,
    `logo_url`    VARCHAR(255) NULL,
    `website_url` VARCHAR(255) NULL,
    `tier`        ENUM('Bronze','Silver','Gold','Platinum') NOT NULL DEFAULT 'Bronze',
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Match ratings ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `match_ratings` (
    `rating_id`  INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id` INT          NOT NULL,
    `member_id`  INT          NOT NULL,
    `rating`     TINYINT      NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
    `comment`    VARCHAR(255) NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_match_rating` (`fixture_id`, `member_id`),
    CONSTRAINT `fk_mr_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mr_member`  FOREIGN KEY (`member_id`)  REFERENCES `members`  (`member_id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Live commentary ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `match_commentary` (
    `commentary_id` INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`    INT          NOT NULL,
    `minute`        TINYINT      NULL,
    `text`          VARCHAR(255) NOT NULL,
    `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_commentary_fixture` (`fixture_id`),
    CONSTRAINT `fk_comm_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Pitch condition ───────────────────────────────────────────────────────────
ALTER TABLE `fixtures`
    ADD COLUMN IF NOT EXISTS `pitch_condition` ENUM('Good','Playable','Waterlogged','Postponed') NULL AFTER `venue`;

-- ── Match attendance ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `match_attendance` (
    `attendance_id` INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`    INT          NOT NULL,
    `member_id`     INT          NOT NULL,
    `status`        ENUM('Present','Absent','Excused') NOT NULL DEFAULT 'Present',
    `marked_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_match_att` (`fixture_id`, `member_id`),
    CONSTRAINT `fk_att_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_att_member`  FOREIGN KEY (`member_id`)  REFERENCES `members`  (`member_id`)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Payment receipt tokens ────────────────────────────────────────────────────
ALTER TABLE `payments`
    ADD COLUMN IF NOT EXISTS `receipt_token` VARCHAR(64) NULL UNIQUE AFTER `payment_status`;

-- ── Subscription pause ────────────────────────────────────────────────────────
ALTER TABLE `member_memberships`
    ADD COLUMN IF NOT EXISTS `paused_at`   DATETIME NULL AFTER `status`,
    ADD COLUMN IF NOT EXISTS `resume_at`   DATETIME NULL AFTER `paused_at`,
    ADD COLUMN IF NOT EXISTS `pause_days`  INT      NULL DEFAULT 0 AFTER `resume_at`;

-- ── Member referrals ──────────────────────────────────────────────────────────
ALTER TABLE `members`
    ADD COLUMN IF NOT EXISTS `referral_code`    VARCHAR(12) NULL UNIQUE AFTER `address`,
    ADD COLUMN IF NOT EXISTS `referred_by`      INT         NULL AFTER `referral_code`;

CREATE TABLE IF NOT EXISTS `referrals` (
    `referral_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `referrer_id`   INT NOT NULL COMMENT 'member who shared the code',
    `referred_id`   INT NOT NULL COMMENT 'new member who used the code',
    `reward_given`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`    TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_referral` (`referred_id`),
    CONSTRAINT `fk_ref_referrer` FOREIGN KEY (`referrer_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ref_referred` FOREIGN KEY (`referred_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
