-- AI Review Log for tracking Gemini decisions on bookings

CREATE TABLE IF NOT EXISTS `ai_review_log` (
    `log_id` INT AUTO_INCREMENT PRIMARY KEY,
    `booking_id` INT NOT NULL,
    `admin_id` INT NULL DEFAULT NULL,
    `decision` VARCHAR(20) NOT NULL COMMENT 'APPROVE or REJECT',
    `reason` VARCHAR(500) DEFAULT NULL COMMENT 'AI reasoning',
    `applied` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Whether the decision was applied',
    `reviewed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_booking_id` (`booking_id`),
    INDEX `idx_reviewed_at` (`reviewed_at`),
    INDEX `idx_decision` (`decision`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
