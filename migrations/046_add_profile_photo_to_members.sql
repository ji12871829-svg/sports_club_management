-- 046_add_profile_photo_to_members.sql
-- Adds profile_photo and date_of_birth columns expected by
-- membership_card.php, edit_profile.php, and verify_membership.php.

ALTER TABLE `members`
    ADD COLUMN `profile_photo` VARCHAR(255) DEFAULT NULL AFTER `position`,
    ADD COLUMN `date_of_birth` DATE DEFAULT NULL AFTER `profile_photo`;
