-- Cron AI review settings table
-- Allows admins to configure the automatic AI booking review cron schedule,
-- strictness level, and enable/disable it from a UI.

CREATE TABLE IF NOT EXISTS `cron_ai_settings` (
    `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(64) NOT NULL UNIQUE,
    `setting_value` VARCHAR(255) NOT NULL DEFAULT '',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default values
INSERT INTO `cron_ai_settings` (`setting_key`, `setting_value`) VALUES
    ('enabled', '1'),
    ('strictness', 'Balanced'),
    ('interval_hours', '6'),
    ('batch_limit', '50')
ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`);
