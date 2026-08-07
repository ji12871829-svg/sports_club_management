-- Active admin sessions registry for the profile page.
-- Enables the "Active Sessions" panel (IP, user agent, last activity, age)
-- and per-session revocation without nuking every other session (that is
-- what auth_epoch on `admins` is for).

CREATE TABLE IF NOT EXISTS `admin_sessions` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `admin_id`      INT NOT NULL,
    `session_token` CHAR(64) NOT NULL COMMENT 'sha256(session_id()) — never store the raw session id',
    `ip_address`    VARCHAR(45) NULL DEFAULT NULL,
    `user_agent`    VARCHAR(255) NULL DEFAULT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `revoked_at`    TIMESTAMP NULL DEFAULT NULL,
    UNIQUE KEY `uq_admin_sessions_token` (`session_token`),
    KEY `idx_admin_sessions_admin` (`admin_id`, `last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
