-- Migration 040: Add last_login column to members table
-- Tracks when members last logged in for more accurate churn prediction scoring

ALTER TABLE `members`
    ADD COLUMN `last_login` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last successful login timestamp' AFTER `email`;
