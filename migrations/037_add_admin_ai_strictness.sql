-- Per-admin AI strictness preference for booking reviews
-- Values: Conservative, Balanced, Liberal

ALTER TABLE `admins`
    ADD COLUMN `ai_strictness` VARCHAR(20) NOT NULL DEFAULT 'Balanced'
    COMMENT 'AI booking review strictness: Conservative, Balanced, or Liberal'
    AFTER `recovery_codes`;
