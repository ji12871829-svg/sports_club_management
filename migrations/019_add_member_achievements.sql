-- Member achievements and milestone badges

CREATE TABLE IF NOT EXISTS `achievements` (
    `achievement_id`   INT AUTO_INCREMENT PRIMARY KEY,
    `code`             VARCHAR(60) NOT NULL,
    `name`             VARCHAR(120) NOT NULL,
    `icon`             VARCHAR(16) NULL,
    `description`      VARCHAR(255) NULL,
    `created_at`       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_achievement_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `member_achievements` (
    `member_achievement_id` INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`             INT NOT NULL,
    `achievement_id`        INT NOT NULL,
    `awarded_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `context_json`          TEXT NULL,
    UNIQUE KEY `uq_member_achievement` (`member_id`, `achievement_id`),
    KEY `idx_member_achievement_member` (`member_id`, `awarded_at`),
    CONSTRAINT `fk_member_achievement_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_member_achievement_achievement`
        FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`achievement_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `achievements` (`code`, `name`, `icon`, `description`) VALUES
('first_goal', 'First Goal', '⚽', 'Awarded after scoring your first goal.'),
('ten_appearances', '10 Appearances', '💪', 'Awarded after ten completed match appearances.'),
('one_year_member', '1 Year Member', '🎉', 'Awarded after one year of membership.'),
('hat_trick', 'Hat-trick Hero', '🎯', 'Awarded after scoring three goals in one match.'),
('clean_sheet', 'Clean Sheet', '🧤', 'Awarded for contributing to a clean sheet.')
ON DUPLICATE KEY UPDATE
    `name` = VALUES(`name`),
    `icon` = VALUES(`icon`),
    `description` = VALUES(`description`);
