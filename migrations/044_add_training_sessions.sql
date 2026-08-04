CREATE TABLE IF NOT EXISTS `training_sessions` (
    `session_id`     INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`      INT NOT NULL,
    `coach_id`       INT NOT NULL,
    `session_date`   DATE NOT NULL,
    `session_time`   TIME NOT NULL,
    `duration_mins`  INT NOT NULL DEFAULT 60,
    `notes`          TEXT DEFAULT NULL,
    `status`         ENUM('Pending','Confirmed','Cancelled') NOT NULL DEFAULT 'Pending',
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_member` (`member_id`),
    KEY `idx_coach`  (`coach_id`),
    KEY `idx_date`   (`session_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
