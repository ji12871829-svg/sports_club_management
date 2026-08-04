-- 047_add_emergency_columns_to_members.sql
-- Adds bio and emergency contact columns expected by edit_profile.php
-- and other profile-related pages.

ALTER TABLE `members`
    ADD COLUMN `bio` TEXT DEFAULT NULL AFTER `address`,
    ADD COLUMN `emergency_name` VARCHAR(120) DEFAULT NULL AFTER `bio`,
    ADD COLUMN `emergency_phone` VARCHAR(30) DEFAULT NULL AFTER `emergency_name`,
    ADD COLUMN `emergency_relation` VARCHAR(60) DEFAULT NULL AFTER `emergency_phone`;
