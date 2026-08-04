-- Migration 041: Create admin_todos table for admin to-do list kanban board

CREATE TABLE IF NOT EXISTS `admin_todos` (
    `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL DEFAULT NULL,
    `priority` ENUM('low', 'medium', 'high', 'urgent') NOT NULL DEFAULT 'medium',
    `status` ENUM('open', 'in_progress', 'done') NOT NULL DEFAULT 'open',
    `due_date` DATE NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `status_idx` (`status`),
    INDEX `priority_idx` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
