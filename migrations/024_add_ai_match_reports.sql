-- Migration 024: AI-generated match reports

CREATE TABLE IF NOT EXISTS `match_reports` (
    `report_id`       INT AUTO_INCREMENT PRIMARY KEY,
    `fixture_id`      INT NOT NULL,
    `headline`        VARCHAR(180) NOT NULL,
    `body`            TEXT NOT NULL,
    `source`          ENUM('gemini','fallback','manual') NOT NULL DEFAULT 'gemini',
    `generation_note` VARCHAR(255) NULL,
    `is_published`    TINYINT(1) NOT NULL DEFAULT 0,
    `generated_by`    INT NULL,
    `published_by`    INT NULL,
    `published_at`    DATETIME NULL,
    `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_match_report_fixture` (`fixture_id`),
    KEY `idx_match_report_published` (`is_published`, `published_at`),
    CONSTRAINT `fk_match_report_fixture`
        FOREIGN KEY (`fixture_id`) REFERENCES `fixtures` (`fixture_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
