-- Migration 026: Sponsorship Tiers and Digital Features

-- Sponsors Table (Enhanced)
CREATE TABLE IF NOT EXISTS `sponsors_extended` (
    `sponsor_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `sponsor_name`      VARCHAR(150) NOT NULL,
    `contact_email`     VARCHAR(100) NOT NULL,
    `contact_phone`     VARCHAR(20) NULL,
    `website`           VARCHAR(255) NULL,
    `logo_url`          VARCHAR(255) NULL,
    `tier`              ENUM('bronze', 'silver', 'gold', 'platinum') DEFAULT 'bronze',
    `annual_fee`        DECIMAL(10,2) NOT NULL,
    `status`            ENUM('active', 'inactive', 'expired') DEFAULT 'active',
    `start_date`        DATE NOT NULL,
    `end_date`          DATE NOT NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_tier` (`tier`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sponsorship Campaigns Table
CREATE TABLE IF NOT EXISTS `sponsorship_campaigns` (
    `campaign_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `sponsor_id`        INT NOT NULL,
    `campaign_name`     VARCHAR(150) NOT NULL,
    `ad_image_url`      VARCHAR(255) NULL,
    `ad_text`           TEXT NULL,
    `placement`         ENUM('dashboard', 'match_report', 'email', 'website') DEFAULT 'dashboard',
    `start_date`        DATE NOT NULL,
    `end_date`          DATE NOT NULL,
    `impressions`       INT DEFAULT 0,
    `clicks`            INT DEFAULT 0,
    `status`            ENUM('active', 'paused', 'completed') DEFAULT 'active',
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_sponsor_id` (`sponsor_id`),
    KEY `idx_placement` (`placement`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_sponsorship_campaign_sponsor`
        FOREIGN KEY (`sponsor_id`) REFERENCES `sponsors_extended` (`sponsor_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Member Referrals Table
CREATE TABLE IF NOT EXISTS `member_referrals` (
    `referral_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `referrer_id`       INT NOT NULL,
    `referred_member_id` INT NULL,
    `referred_email`    VARCHAR(100) NOT NULL,
    `referred_name`     VARCHAR(100) NOT NULL,
    `status`            ENUM('pending', 'accepted', 'completed', 'expired') DEFAULT 'pending',
    `credits_awarded`   DECIMAL(10,2) DEFAULT 0,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at`      DATETIME NULL,
    `expires_at`        DATETIME NULL,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_referrer_id` (`referrer_id`),
    KEY `idx_referred_member_id` (`referred_member_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_referral_referrer`
        FOREIGN KEY (`referrer_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_referral_referred`
        FOREIGN KEY (`referred_member_id`) REFERENCES `members` (`member_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Digital Membership Cards Table
CREATE TABLE IF NOT EXISTS `digital_membership_cards` (
    `card_id`           INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`         INT NOT NULL,
    `card_number`       VARCHAR(50) UNIQUE NOT NULL,
    `qr_code`           TEXT NULL,
    `card_status`       ENUM('active', 'suspended', 'expired') DEFAULT 'active',
    `issued_date`       DATE NOT NULL,
    `expiry_date`       DATE NOT NULL,
    `last_scanned`      DATETIME NULL,
    `scan_count`        INT DEFAULT 0,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_member_card` (`member_id`),
    KEY `idx_card_status` (`card_status`),
    CONSTRAINT `fk_digital_card_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Member Loyalty Points Table
CREATE TABLE IF NOT EXISTS `member_loyalty_points` (
    `loyalty_id`        INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`         INT NOT NULL,
    `total_points`      DECIMAL(10,2) DEFAULT 0,
    `points_redeemed`   DECIMAL(10,2) DEFAULT 0,
    `current_balance`   DECIMAL(10,2) DEFAULT 0,
    `tier`              ENUM('bronze', 'silver', 'gold', 'platinum') DEFAULT 'bronze',
    `last_earned`       DATETIME NULL,
    `last_redeemed`     DATETIME NULL,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_member_loyalty` (`member_id`),
    CONSTRAINT `fk_loyalty_points_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Loyalty Points Transactions Table
CREATE TABLE IF NOT EXISTS `loyalty_transactions` (
    `transaction_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`         INT NOT NULL,
    `transaction_type`  ENUM('earned', 'redeemed', 'expired', 'adjusted') DEFAULT 'earned',
    `points`            DECIMAL(10,2) NOT NULL,
    `reason`            VARCHAR(200) NULL,
    `reference_id`      INT NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_member_id` (`member_id`),
    KEY `idx_transaction_type` (`transaction_type`),
    CONSTRAINT `fk_loyalty_transaction_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
