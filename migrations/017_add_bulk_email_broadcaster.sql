-- Bulk email broadcaster and admin notification plumbing

CREATE TABLE IF NOT EXISTS `email_campaigns` (
    `campaign_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `created_by_admin_id` INT NOT NULL,
    `title`              VARCHAR(180) NOT NULL,
    `subject`            VARCHAR(180) NOT NULL,
    `message_html`       MEDIUMTEXT NOT NULL,
    `audience_type`      ENUM('all_members', 'league', 'team') NOT NULL,
    `league_id`          INT NULL,
    `team_id`            INT NULL,
    `status`             ENUM('Draft', 'Queued', 'Sending', 'Completed', 'Failed') NOT NULL DEFAULT 'Draft',
    `scheduled_at`       DATETIME NULL,
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `started_at`         DATETIME NULL,
    `completed_at`       DATETIME NULL,
    `total_recipients`   INT UNSIGNED NOT NULL DEFAULT 0,
    `sent_count`         INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_count`       INT UNSIGNED NOT NULL DEFAULT 0,
    KEY `idx_campaign_status_schedule` (`status`, `scheduled_at`),
    KEY `idx_campaign_created_by` (`created_by_admin_id`),
    CONSTRAINT `fk_campaign_admin`
        FOREIGN KEY (`created_by_admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_campaign_league`
        FOREIGN KEY (`league_id`) REFERENCES `leagues` (`league_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_campaign_team`
        FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `email_campaign_recipients` (
    `recipient_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `campaign_id`        INT NOT NULL,
    `member_id`          INT NOT NULL,
    `email`              VARCHAR(120) NOT NULL,
    `name`               VARCHAR(160) NOT NULL,
    `status`             ENUM('Queued', 'Sent', 'Failed') NOT NULL DEFAULT 'Queued',
    `error_message`      VARCHAR(255) NULL,
    `provider_message_id` VARCHAR(120) NULL,
    `sent_at`            DATETIME NULL,
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_campaign_member` (`campaign_id`, `member_id`),
    KEY `idx_campaign_recipient_status` (`campaign_id`, `status`),
    CONSTRAINT `fk_campaign_recipient_campaign`
        FOREIGN KEY (`campaign_id`) REFERENCES `email_campaigns` (`campaign_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_campaign_recipient_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `admin_notifications` (
    `notification_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id`           INT NULL,
    `event_key`          VARCHAR(80) NOT NULL,
    `title`              VARCHAR(180) NOT NULL,
    `message`            VARCHAR(255) NOT NULL,
    `payload_json`       TEXT NULL,
    `is_read`            TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_admin_notifications_admin` (`admin_id`, `is_read`, `created_at`),
    KEY `idx_admin_notifications_event` (`event_key`, `created_at`),
    CONSTRAINT `fk_admin_notification_admin`
        FOREIGN KEY (`admin_id`) REFERENCES `admins` (`admin_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
