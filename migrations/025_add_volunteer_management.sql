-- Migration 025: Volunteer Management System

-- Volunteer Tasks Table
CREATE TABLE IF NOT EXISTS `volunteer_tasks` (
    `task_id`           INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`        INT NULL,
    `task_name`         VARCHAR(100) NOT NULL,
    `task_description`  TEXT NULL,
    `task_type`         ENUM('linesman', 'referee', 'refreshments', 'setup', 'cleanup', 'medical', 'other') NOT NULL,
    `required_count`    INT DEFAULT 1,
    `status`            ENUM('open', 'filled', 'completed', 'cancelled') DEFAULT 'open',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_fixture_id` (`fixture_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_volunteer_task_fixture`
        FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Volunteer Assignments Table
CREATE TABLE IF NOT EXISTS `volunteer_assignments` (
    `assignment_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `task_id`           INT NOT NULL,
    `member_id`         INT NOT NULL,
    `status`            ENUM('assigned', 'accepted', 'declined', 'completed', 'no_show') DEFAULT 'assigned',
    `hours_worked`      DECIMAL(5,2) NULL,
    `notes`             TEXT NULL,
    `assigned_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at`      DATETIME NULL,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_task_id` (`task_id`),
    KEY `idx_member_id` (`member_id`),
    KEY `idx_status` (`status`),
    UNIQUE KEY `uq_task_member` (`task_id`, `member_id`),
    CONSTRAINT `fk_volunteer_assignment_task`
        FOREIGN KEY (`task_id`) REFERENCES `volunteer_tasks` (`task_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_volunteer_assignment_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Volunteer Credits Table
CREATE TABLE IF NOT EXISTS `volunteer_credits` (
    `credit_id`         INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`         INT NOT NULL,
    `total_hours`       DECIMAL(10,2) DEFAULT 0,
    `total_credits`     DECIMAL(10,2) DEFAULT 0,
    `last_updated`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_member_credits` (`member_id`),
    CONSTRAINT `fk_volunteer_credits_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add volunteer_credits column to members table if not exists
ALTER TABLE `members` ADD COLUMN `volunteer_hours` DECIMAL(10,2) DEFAULT 0;
