CREATE TABLE IF NOT EXISTS `fixture_ticket_settings` (
    `fixture_id` INT PRIMARY KEY,
    `ticket_price` DECIMAL(10, 2) NOT NULL DEFAULT 500.00,
    `ticket_capacity` INT NULL,
    `sales_status` VARCHAR(30) NOT NULL DEFAULT 'Open',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ticket_settings_fixture`
        FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `ticket_orders` (
    `order_id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id` INT NULL,
    `buyer_type` VARCHAR(20) NOT NULL DEFAULT 'Member',
    `buyer_name` VARCHAR(160) NULL,
    `buyer_email` VARCHAR(160) NULL,
    `buyer_phone` VARCHAR(40) NULL,
    `fixture_id` INT NOT NULL,
    `supported_team_id` INT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_price` DECIMAL(10, 2) NOT NULL,
    `total_amount` DECIMAL(10, 2) NOT NULL,
    `payment_method` VARCHAR(50) NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Pending',
    `provider_reference` VARCHAR(120) NULL,
    `provider_checkout_id` VARCHAR(120) NULL,
    `payment_id` INT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `paid_at` TIMESTAMP NULL DEFAULT NULL,
    KEY `idx_ticket_orders_member` (`member_id`, `created_at`),
    KEY `idx_ticket_orders_fixture` (`fixture_id`),
    KEY `idx_ticket_orders_supported_team` (`supported_team_id`),
    KEY `idx_ticket_orders_payment` (`payment_id`),
    UNIQUE KEY `uq_ticket_orders_reference` (`provider_reference`),
    UNIQUE KEY `uq_ticket_orders_checkout` (`provider_checkout_id`),
    CONSTRAINT `fk_ticket_orders_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_orders_fixture`
        FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ticket_orders_supported_team`
        FOREIGN KEY (`supported_team_id`) REFERENCES `teams` (`team_id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ticket_orders_payment`
        FOREIGN KEY (`payment_id`) REFERENCES `payments` (`payment_id`) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS `tickets` (
    `ticket_id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_id` INT NOT NULL,
    `fixture_id` INT NOT NULL,
    `member_id` INT NULL,
    `supported_team_id` INT NULL,
    `ticket_code` VARCHAR(80) NOT NULL UNIQUE,
    `ticket_price` DECIMAL(10, 2) NOT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Valid',
    `issued_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `used_at` TIMESTAMP NULL DEFAULT NULL,
    KEY `idx_tickets_order` (`order_id`),
    KEY `idx_tickets_fixture_status` (`fixture_id`, `status`),
    KEY `idx_tickets_member` (`member_id`, `issued_at`),
    KEY `idx_tickets_supported_team` (`supported_team_id`),
    CONSTRAINT `fk_tickets_order`
        FOREIGN KEY (`order_id`) REFERENCES `ticket_orders` (`order_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tickets_fixture`
        FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tickets_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tickets_supported_team`
        FOREIGN KEY (`supported_team_id`) REFERENCES `teams` (`team_id`) ON DELETE SET NULL
);
