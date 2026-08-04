-- Member password reset tokens (email link flow)

CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `token_id`    INT AUTO_INCREMENT PRIMARY KEY,
    `member_id`   INT NOT NULL,
    `token_hash`  CHAR(64) NOT NULL,
    `expires_at`  DATETIME NOT NULL,
    `used_at`     DATETIME NULL DEFAULT NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_reset_token_hash` (`token_hash`),
    KEY `idx_reset_member` (`member_id`),
    KEY `idx_reset_expires` (`expires_at`),
    CONSTRAINT `fk_reset_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
