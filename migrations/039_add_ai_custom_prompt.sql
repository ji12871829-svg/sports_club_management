-- 039: Add ai_custom_prompt column to admins for custom AI strictness prompt editing
ALTER TABLE `admins`
    ADD COLUMN IF NOT EXISTS `ai_custom_prompt` TEXT DEFAULT NULL AFTER `ai_strictness`,
    ADD COLUMN IF NOT EXISTS `ai_custom_temperature` DECIMAL(3,2) DEFAULT 0.20 AFTER `ai_custom_prompt`;
