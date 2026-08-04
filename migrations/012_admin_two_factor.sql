-- Admin two-factor authentication (TOTP)

ALTER TABLE `admins`
    ADD COLUMN `totp_secret` VARCHAR(64) NULL DEFAULT NULL AFTER `password`,
    ADD COLUMN `totp_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `totp_secret`,
    ADD COLUMN `totp_confirmed_at` TIMESTAMP NULL DEFAULT NULL AFTER `totp_enabled`,
    ADD COLUMN `recovery_codes` TEXT NULL DEFAULT NULL COMMENT 'JSON array of password_hash strings' AFTER `totp_confirmed_at`;
