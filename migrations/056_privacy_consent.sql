-- Migration 056: Add privacy consent tracking
-- Date: 2026-08-04

ALTER TABLE `members`
    ADD COLUMN IF NOT EXISTS `privacy_consent` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `address`;

ALTER TABLE `members`
    ADD COLUMN IF NOT EXISTS `consent_given_at` DATETIME NULL DEFAULT NULL
    AFTER `privacy_consent`;

ALTER TABLE `members`
    ADD COLUMN IF NOT EXISTS `data_deletion_requested_at` DATETIME NULL DEFAULT NULL
    AFTER `consent_given_at`;