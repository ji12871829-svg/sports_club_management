-- Migration 028: Gear Swap Marketplace, Parent Portal, and Compliance

-- Gear Swap Marketplace Listings Table
CREATE TABLE IF NOT EXISTS `gear_marketplace_listings` (
    `listing_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `seller_id`         INT NOT NULL,
    `item_name`         VARCHAR(150) NOT NULL,
    `category`          ENUM('boots', 'rackets', 'kits', 'protective', 'accessories', 'other') DEFAULT 'other',
    `description`       TEXT NOT NULL,
    `condition`         ENUM('like_new', 'excellent', 'good', 'fair', 'poor') DEFAULT 'good',
    `price`             DECIMAL(8,2) NULL,
    `listing_type`      ENUM('sell', 'donate', 'trade') DEFAULT 'sell',
    `item_image_url`    VARCHAR(255) NULL,
    `status`            ENUM('active', 'pending_approval', 'sold', 'withdrawn', 'expired') DEFAULT 'pending_approval',
    `views`             INT DEFAULT 0,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at`        DATETIME NULL,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_seller_id` (`seller_id`),
    KEY `idx_category` (`category`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_listing_seller`
        FOREIGN KEY (`seller_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Gear Marketplace Transactions Table
CREATE TABLE IF NOT EXISTS `gear_marketplace_transactions` (
    `transaction_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `listing_id`        INT NOT NULL,
    `buyer_id`          INT NOT NULL,
    `seller_id`         INT NOT NULL,
    `transaction_type`  ENUM('purchase', 'trade', 'donation') DEFAULT 'purchase',
    `amount_paid`       DECIMAL(8,2) NULL,
    `platform_fee`      DECIMAL(8,2) DEFAULT 0,
    `status`            ENUM('pending', 'completed', 'cancelled', 'disputed') DEFAULT 'pending',
    `buyer_rating`      INT NULL,
    `seller_rating`     INT NULL,
    `buyer_feedback`    TEXT NULL,
    `seller_feedback`   TEXT NULL,
    `completed_at`      DATETIME NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_listing_id` (`listing_id`),
    KEY `idx_buyer_id` (`buyer_id`),
    KEY `idx_seller_id` (`seller_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_transaction_listing`
        FOREIGN KEY (`listing_id`) REFERENCES `gear_marketplace_listings` (`listing_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_transaction_buyer`
        FOREIGN KEY (`buyer_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_transaction_seller`
        FOREIGN KEY (`seller_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parent Portal - Parent Accounts Table
CREATE TABLE IF NOT EXISTS `parent_accounts` (
    `parent_id`         INT AUTO_INCREMENT PRIMARY KEY,
    `email`             VARCHAR(100) UNIQUE NOT NULL,
    `password_hash`     VARCHAR(255) NOT NULL,
    `first_name`        VARCHAR(100) NOT NULL,
    `last_name`         VARCHAR(100) NOT NULL,
    `phone_number`      VARCHAR(20) NULL,
    `status`            ENUM('active', 'inactive', 'suspended') DEFAULT 'active',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parent-Child Relationships Table
CREATE TABLE IF NOT EXISTS `parent_child_relationships` (
    `relationship_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `parent_id`         INT NOT NULL,
    `child_member_id`   INT NOT NULL,
    `relationship_type` ENUM('parent', 'guardian', 'coach') DEFAULT 'parent',
    `verified`          BOOLEAN DEFAULT FALSE,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_parent_child` (`parent_id`, `child_member_id`),
    CONSTRAINT `fk_relationship_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `parent_accounts` (`parent_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_relationship_child`
        FOREIGN KEY (`child_member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Medical Waivers Table
CREATE TABLE IF NOT EXISTS `medical_waivers` (
    `waiver_id`         INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`         INT NOT NULL,
    `parent_id`         INT NULL,
    `waiver_type`       ENUM('general', 'high_risk_activity', 'contact_sport') DEFAULT 'general',
    `waiver_document_url` VARCHAR(255) NULL,
    `signed_date`       DATE NOT NULL,
    `expiry_date`       DATE NULL,
    `status`            ENUM('active', 'expired', 'revoked') DEFAULT 'active',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_member_id` (`member_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_waiver_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Authorized Pickup List Table
CREATE TABLE IF NOT EXISTS `authorized_pickups` (
    `pickup_id`         INT AUTO_INCREMENT PRIMARY KEY,
    `child_member_id`   INT NOT NULL,
    `authorized_person_name` VARCHAR(100) NOT NULL,
    `authorized_person_phone` VARCHAR(20) NOT NULL,
    `relationship`      VARCHAR(50) NOT NULL,
    `id_document_type`  VARCHAR(50) NULL,
    `status`            ENUM('active', 'inactive') DEFAULT 'active',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_child_member_id` (`child_member_id`),
    CONSTRAINT `fk_pickup_child`
        FOREIGN KEY (`child_member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Coach Certifications & DBS Checks Table
CREATE TABLE IF NOT EXISTS `coach_certifications` (
    `certification_id`  INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`          INT NOT NULL,
    `certification_type` ENUM('level_1', 'level_2', 'level_3', 'dbs_check', 'first_aid', 'safeguarding') DEFAULT 'level_1',
    `certificate_url`   VARCHAR(255) NULL,
    `issued_date`       DATE NOT NULL,
    `expiry_date`       DATE NOT NULL,
    `status`            ENUM('valid', 'expired', 'pending_renewal', 'revoked') DEFAULT 'valid',
    `issuing_body`      VARCHAR(100) NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_coach_id` (`coach_id`),
    KEY `idx_status` (`status`),
    KEY `idx_expiry_date` (`expiry_date`),
    CONSTRAINT `fk_certification_coach`
        FOREIGN KEY (`coach_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Compliance Audit Log Table
CREATE TABLE IF NOT EXISTS `compliance_audit_log` (
    `audit_id`          INT AUTO_INCREMENT PRIMARY KEY,
    `coach_id`          INT NOT NULL,
    `audit_type`        ENUM('certification_check', 'dbs_check', 'medical_waiver', 'safeguarding_training') DEFAULT 'certification_check',
    `status`            ENUM('compliant', 'non_compliant', 'pending') DEFAULT 'pending',
    `details`           TEXT NULL,
    `action_taken`      TEXT NULL,
    `audited_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_coach_id` (`coach_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_audit_coach`
        FOREIGN KEY (`coach_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Member Churn Risk Analysis Table
CREATE TABLE IF NOT EXISTS `member_churn_risk` (
    `risk_id`           INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`         INT NOT NULL,
    `risk_score`        DECIMAL(5,2) DEFAULT 0,
    `risk_level`        ENUM('low', 'medium', 'high', 'critical') DEFAULT 'low',
    `last_login_days_ago` INT NULL,
    `last_booking_days_ago` INT NULL,
    `booking_frequency_trend` VARCHAR(50) NULL,
    `engagement_score`  DECIMAL(5,2) DEFAULT 0,
    `retention_actions_taken` TEXT NULL,
    `analyzed_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_member_risk` (`member_id`),
    KEY `idx_risk_level` (`risk_level`),
    CONSTRAINT `fk_churn_risk_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Member Wellness & Activity Tracking Table
CREATE TABLE IF NOT EXISTS `member_wellness_tracking` (
    `wellness_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`         INT NOT NULL,
    `tracking_date`     DATE NOT NULL,
    `off_pitch_minutes`  INT DEFAULT 0,
    `on_pitch_minutes`   INT DEFAULT 0,
    `injury_status`     ENUM('healthy', 'minor_injury', 'major_injury', 'recovery') DEFAULT 'healthy',
    `injury_notes`      TEXT NULL,
    `recommended_rest_until` DATE NULL,
    `wellness_score`    DECIMAL(5,2) DEFAULT 0,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_member_date_wellness` (`member_id`, `tracking_date`),
    KEY `idx_injury_status` (`injury_status`),
    CONSTRAINT `fk_wellness_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
