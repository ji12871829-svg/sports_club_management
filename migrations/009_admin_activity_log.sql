CREATE TABLE IF NOT EXISTS `admin_activity_log` (
    `log_id`      INT AUTO_INCREMENT PRIMARY KEY,
    `admin_id`    INT          NULL,
    `admin_email` VARCHAR(100) NULL,
    `action`      VARCHAR(100) NOT NULL,
    `module`      VARCHAR(60)  NOT NULL,
    `description` TEXT         NULL,
    `record_id`   INT          NULL COMMENT 'ID of the affected record',
    `ip_address`  VARCHAR(45)  NULL,
    `user_agent`  VARCHAR(255) NULL,
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_log_admin`   (`admin_id`),
    INDEX `idx_log_module`  (`module`),
    INDEX `idx_log_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
