CREATE TABLE IF NOT EXISTS `event_checklists` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`  INT UNSIGNED NOT NULL,
    `item`        VARCHAR(255) NOT NULL,
    `responsible` VARCHAR(120) DEFAULT NULL,
    `sort_order`  INT UNSIGNED NOT NULL DEFAULT 0,
    `is_done`     TINYINT(1) NOT NULL DEFAULT 0,
    `done_at`     DATETIME DEFAULT NULL,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_fixture` (`fixture_id`),
    KEY `idx_done` (`is_done`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
