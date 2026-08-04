-- Migration 027: AI Insights, Smart Access, and Facility Management

-- AI Match Insights Table
CREATE TABLE IF NOT EXISTS `ai_match_insights` (
    `insight_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`        INT NOT NULL,
    `player_of_match`   INT NULL,
    `team_of_week`      JSON NULL,
    `key_moments`       JSON NULL,
    `performance_summary` TEXT NULL,
    `social_media_caption` TEXT NULL,
    `generated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_fixture_insight` (`fixture_id`),
    CONSTRAINT `fk_ai_insight_fixture`
        FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Social Media Posts Table
CREATE TABLE IF NOT EXISTS `social_media_posts` (
    `post_id`           INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`        INT NULL,
    `insight_id`        INT NULL,
    `platform`          ENUM('instagram', 'facebook', 'twitter', 'tiktok') DEFAULT 'instagram',
    `content`           TEXT NOT NULL,
    `image_url`         VARCHAR(255) NULL,
    `status`            ENUM('draft', 'scheduled', 'published', 'failed') DEFAULT 'draft',
    `scheduled_at`      DATETIME NULL,
    `published_at`      DATETIME NULL,
    `engagement_count`  INT DEFAULT 0,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_fixture_id` (`fixture_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_social_post_fixture`
        FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_social_post_insight`
        FOREIGN KEY (`insight_id`) REFERENCES `ai_match_insights` (`insight_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Smart Access Codes Table
CREATE TABLE IF NOT EXISTS `smart_access_codes` (
    `code_id`           INT AUTO_INCREMENT PRIMARY KEY,
    `booking_id`        INT NOT NULL,
    `member_id`         INT NOT NULL,
    `access_code`       VARCHAR(6) UNIQUE NOT NULL,
    `facility_id`       INT NULL,
    `code_status`       ENUM('active', 'used', 'expired', 'revoked') DEFAULT 'active',
    `generated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `first_used_at`     DATETIME NULL,
    `expires_at`        DATETIME NOT NULL,
    `access_attempts`   INT DEFAULT 0,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_booking_code` (`booking_id`),
    KEY `idx_member_id` (`member_id`),
    KEY `idx_code_status` (`code_status`),
    CONSTRAINT `fk_smart_access_booking`
        FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_smart_access_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Equipment Damage Reports Table
CREATE TABLE IF NOT EXISTS `equipment_damage_reports` (
    `report_id`         INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`         INT NOT NULL,
    `facility_id`       INT NULL,
    `equipment_name`    VARCHAR(150) NOT NULL,
    `damage_description` TEXT NOT NULL,
    `damage_photo_url`  VARCHAR(255) NULL,
    `ai_damage_class`   ENUM('minor', 'moderate', 'severe', 'critical') NULL,
    `ai_confidence`     DECIMAL(3,2) NULL,
    `assigned_to`       INT NULL,
    `status`            ENUM('reported', 'reviewed', 'in_repair', 'completed', 'rejected') DEFAULT 'reported',
    `repair_notes`      TEXT NULL,
    `reported_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at`      DATETIME NULL,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_member_id` (`member_id`),
    KEY `idx_status` (`status`),
    KEY `idx_ai_damage_class` (`ai_damage_class`),
    CONSTRAINT `fk_damage_report_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_damage_report_assigned`
        FOREIGN KEY (`assigned_to`) REFERENCES `members` (`member_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Facility Energy Management Table
CREATE TABLE IF NOT EXISTS `facility_energy_management` (
    `energy_id`         INT AUTO_INCREMENT PRIMARY KEY,
    `facility_id`       INT NOT NULL,
    `booking_id`        INT NULL,
    `device_type`       ENUM('floodlights', 'heating', 'cooling', 'water', 'other') DEFAULT 'floodlights',
    `device_name`       VARCHAR(100) NOT NULL,
    `scheduled_on_time` DATETIME NULL,
    `scheduled_off_time` DATETIME NULL,
    `actual_on_time`    DATETIME NULL,
    `actual_off_time`   DATETIME NULL,
    `status`            ENUM('scheduled', 'active', 'completed', 'cancelled') DEFAULT 'scheduled',
    `energy_consumed_kwh` DECIMAL(8,2) NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_facility_id` (`facility_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Fan Wall & Tipping Table
CREATE TABLE IF NOT EXISTS `fan_wall_shoutouts` (
    `shoutout_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`        INT NOT NULL,
    `member_id`         INT NOT NULL,
    `shoutout_text`     VARCHAR(280) NOT NULL,
    `amount_paid`       DECIMAL(6,2) DEFAULT 0,
    `display_order`     INT DEFAULT 0,
    `status`            ENUM('pending', 'approved', 'displayed', 'rejected') DEFAULT 'pending',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `displayed_at`      DATETIME NULL,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_fixture_id` (`fixture_id`),
    KEY `idx_member_id` (`member_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_shoutout_fixture`
        FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shoutout_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sponsorship Impression Tracking Table
CREATE TABLE IF NOT EXISTS `sponsorship_impressions` (
    `impression_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `campaign_id`       INT NOT NULL,
    `member_id`         INT NULL,
    `impression_type`   ENUM('email', 'dashboard', 'match_report', 'website') DEFAULT 'dashboard',
    `clicked`           BOOLEAN DEFAULT FALSE,
    `click_timestamp`   DATETIME NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_campaign_id` (`campaign_id`),
    KEY `idx_member_id` (`member_id`),
    CONSTRAINT `fk_impression_campaign`
        FOREIGN KEY (`campaign_id`) REFERENCES `sponsorship_campaigns` (`campaign_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
