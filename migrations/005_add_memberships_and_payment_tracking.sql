-- Membership plans and member subscriptions.
CREATE TABLE IF NOT EXISTS `membership_plans` (
    `plan_id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `price` DECIMAL(10, 2) NOT NULL,
    `duration_days` INT NOT NULL,
    `description` TEXT,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `member_memberships` (
    `membership_id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NOT NULL,
    `plan_id` INT NOT NULL,
    `payment_id` INT NULL,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_member_memberships_member_status` (`member_id`, `status`, `end_date`),
    KEY `idx_member_memberships_plan` (`plan_id`),
    KEY `idx_member_memberships_payment` (`payment_id`),
    CONSTRAINT `fk_member_memberships_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_member_memberships_plan`
        FOREIGN KEY (`plan_id`) REFERENCES `membership_plans` (`plan_id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_member_memberships_payment`
        FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE SET NULL
);

INSERT INTO `membership_plans` (`name`, `price`, `duration_days`, `description`) VALUES
('Monthly', 2500.00, 30, 'Standard 30-day club membership.'),
('Quarterly', 7000.00, 90, 'Three months of club access at a discounted rate.'),
('Annual', 26000.00, 365, 'Full-year membership for regular members.'),
('Student', 1500.00, 30, 'Discounted monthly membership for students.')
ON DUPLICATE KEY UPDATE
    `price` = VALUES(`price`),
    `duration_days` = VALUES(`duration_days`),
    `description` = VALUES(`description`),
    `status` = 'Active';

-- Payment tracking upgrades used by callbacks and the member payment portal.
ALTER TABLE `payments`
    ADD COLUMN `provider_reference` VARCHAR(120) NULL AFTER `description`,
    ADD COLUMN `payment_status` VARCHAR(30) NOT NULL DEFAULT 'Paid' AFTER `provider_reference`,
    ADD COLUMN `source` VARCHAR(30) NOT NULL DEFAULT 'admin' AFTER `payment_status`;

CREATE UNIQUE INDEX `uq_payments_provider_reference`
    ON `payments` (`provider_reference`);

CREATE INDEX `idx_payments_member_date`
    ON `payments` (`member_id`, `payment_date`);

CREATE INDEX `idx_bookings_member_date`
    ON `bookings` (`member_id`, `booking_date`);

CREATE INDEX `idx_bookings_facility_slot`
    ON `bookings` (`facility_id`, `booking_date`, `start_time`, `end_time`);

CREATE INDEX `idx_bookings_coach_slot`
    ON `bookings` (`coach_id`, `booking_date`, `start_time`, `end_time`);
