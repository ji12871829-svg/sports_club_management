-- Migration 042: Create coach_session_notes table

CREATE TABLE IF NOT EXISTS `coach_session_notes` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `coach_id` INT(11) NOT NULL,
    `session_date` DATE NOT NULL,
    `sport_id` INT(11) NULL DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `notes` TEXT NOT NULL,
    `ai_summary` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `coach_id_idx` (`coach_id`),
    INDEX `session_date_idx` (`session_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
