-- Migration 022: Expenses, equipment loans, waiting list, injuries, coach ratings, volunteers, gallery, forum

CREATE TABLE IF NOT EXISTS `club_expenses` (
    `expense_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `category`     VARCHAR(80)  NOT NULL,
    `amount`       DECIMAL(10,2) NOT NULL,
    `description`  VARCHAR(255) NULL,
    `expense_date` DATE NOT NULL,
    `created_by`   INT NULL,
    `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_expense_date` (`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `equipment_loans` (
    `loan_id`      INT AUTO_INCREMENT PRIMARY KEY,
    `equipment_id` INT NOT NULL,
    `member_id`    INT NOT NULL,
    `qty`          INT NOT NULL DEFAULT 1,
    `borrowed_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `due_date`     DATE NOT NULL,
    `returned_at`  DATETIME NULL,
    `status`       ENUM('Active','Returned','Overdue') NOT NULL DEFAULT 'Active',
    `notes`        VARCHAR(255) NULL,
    INDEX `idx_loan_status` (`status`),
    INDEX `idx_loan_due` (`due_date`),
    CONSTRAINT `fk_loan_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_loan_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `waiting_list` (
    `wait_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`     INT NOT NULL,
    `resource_type` ENUM('membership_plan','training_session','facility_booking') NOT NULL,
    `resource_id`   INT NOT NULL COMMENT 'plan_id, session_id, or facility_id',
    `notes`         VARCHAR(255) NULL,
    `status`        ENUM('Waiting','Offered','Accepted','Cancelled') NOT NULL DEFAULT 'Waiting',
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_wait_member_resource` (`member_id`, `resource_type`, `resource_id`, `status`),
    INDEX `idx_wait_status` (`status`),
    CONSTRAINT `fk_wait_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `member_injuries` (
    `injury_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`        INT NOT NULL,
    `injury_date`      DATE NOT NULL,
    `body_area`        VARCHAR(80) NULL,
    `description`      TEXT NOT NULL,
    `recovery_status`  ENUM('Active','Recovering','Cleared') NOT NULL DEFAULT 'Active',
    `notes`            TEXT NULL,
    `recorded_by`      INT NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_injury_member` (`member_id`),
    CONSTRAINT `fk_injury_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `coach_ratings` (
    `rating_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`    INT NOT NULL,
    `member_id`   INT NOT NULL,
    `session_id`  INT NULL,
    `rating`      TINYINT NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
    `comment`     VARCHAR(500) NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_rating_coach` (`coach_id`),
    CONSTRAINT `fk_rating_coach` FOREIGN KEY (`coach_id`) REFERENCES `coaches` (`coach_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rating_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `volunteer_events` (
    `event_id`      INT AUTO_INCREMENT PRIMARY KEY,
    `title`         VARCHAR(200) NOT NULL,
    `description`   TEXT NULL,
    `event_date`    DATE NOT NULL,
    `event_time`    TIME NULL,
    `venue`         VARCHAR(150) NULL,
    `slots_needed`  INT NOT NULL DEFAULT 10,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `volunteer_signups` (
    `signup_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `event_id`    INT NOT NULL,
    `member_id`   INT NOT NULL,
    `role_note`   VARCHAR(120) NULL,
    `status`      ENUM('Registered','Confirmed','Cancelled') NOT NULL DEFAULT 'Registered',
    `signed_up_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_volunteer_event_member` (`event_id`, `member_id`),
    CONSTRAINT `fk_vol_event` FOREIGN KEY (`event_id`) REFERENCES `volunteer_events` (`event_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vol_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `gallery_items` (
    `item_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `title`       VARCHAR(200) NOT NULL,
    `caption`     TEXT NULL,
    `image_url`   VARCHAR(500) NOT NULL,
    `fixture_id`  INT NULL,
    `is_public`   TINYINT(1) NOT NULL DEFAULT 1,
    `uploaded_by` INT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_gallery_public` (`is_public`),
    CONSTRAINT `fk_gallery_fixture` FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `forum_posts` (
    `post_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`   INT NOT NULL,
    `title`       VARCHAR(200) NOT NULL,
    `body`        TEXT NOT NULL,
    `is_hidden`   TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_forum_created` (`created_at`),
    CONSTRAINT `fk_forum_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `forum_replies` (
    `reply_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `post_id`     INT NOT NULL,
    `member_id`   INT NOT NULL,
    `body`        TEXT NOT NULL,
    `is_hidden`   TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_reply_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`post_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_reply_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
