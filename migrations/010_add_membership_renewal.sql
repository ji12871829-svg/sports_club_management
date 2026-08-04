-- Automated Membership Renewal Support
-- Adds auto-renewal configuration and Paystack authorization token storage

-- Add auto_renew flag to member_memberships
ALTER TABLE `member_memberships`
    ADD COLUMN `auto_renew` BOOLEAN DEFAULT FALSE AFTER `status`,
    ADD COLUMN `renewal_reminder_sent` BOOLEAN DEFAULT FALSE AFTER `auto_renew`;

-- Create table to store Paystack authorization codes for recurring billing
CREATE TABLE IF NOT EXISTS `paystack_authorizations` (
    `authorization_id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `authorization_code` VARCHAR(255) NOT NULL UNIQUE,
    `customer_code` VARCHAR(255),
    `last_four_digits` VARCHAR(4),
    `card_brand` VARCHAR(50),
    `payment_id` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_used_at` TIMESTAMP NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
    KEY `idx_member_authorizations` (`member_id`, `status`),
    KEY `idx_authorization_code` (`authorization_code`),
    CONSTRAINT `fk_paystack_authorizations_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_paystack_authorizations_payment`
        FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE SET NULL
);

-- Create table to track renewal attempts
CREATE TABLE IF NOT EXISTS `membership_renewal_logs` (
    `renewal_log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `membership_id` INT NOT NULL,
    `plan_id` INT NOT NULL,
    `renewal_date` DATE NOT NULL,
    `amount` DECIMAL(10, 2) NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
    `paystack_reference` VARCHAR(120),
    `error_message` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    KEY `idx_member_renewal_logs` (`member_id`, `renewal_date`),
    KEY `idx_renewal_status` (`status`, `renewal_date`),
    CONSTRAINT `fk_renewal_logs_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_renewal_logs_membership`
        FOREIGN KEY (`membership_id`) REFERENCES `member_memberships` (`membership_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_renewal_logs_plan`
        FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`plan_id`) ON DELETE RESTRICT
);

-- Create table to store renewal configuration
CREATE TABLE IF NOT EXISTS `renewal_settings` (
    `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL UNIQUE,
    `auto_renew_enabled` BOOLEAN DEFAULT FALSE,
    `renewal_days_before` INT DEFAULT 7,
    `preferred_plan_id` INT,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_renewal_settings_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_renewal_settings_plan`
        FOREIGN KEY (`preferred_plan_id`) REFERENCES `membership_plans` (`plan_id`) ON DELETE SET NULL
);

-- Add index for efficient renewal queries
CREATE INDEX `idx_member_memberships_renewal`
    ON `member_memberships` (`member_id`, `status`, `end_date`, `auto_renew`);
