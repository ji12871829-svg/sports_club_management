ALTER TABLE `fixtures`
    MODIFY `status` ENUM('Scheduled','Live','Completed','Postponed','Cancelled')
        NOT NULL DEFAULT 'Scheduled',
    ADD COLUMN `live_minute` TINYINT UNSIGNED NULL AFTER `away_score`,
    ADD COLUMN `live_status` VARCHAR(40) NULL AFTER `live_minute`,
    ADD COLUMN `live_updated_at` DATETIME NULL AFTER `live_status`,
    ADD COLUMN `score_source` VARCHAR(30) NOT NULL DEFAULT 'manual' AFTER `notes`,
    ADD COLUMN `external_provider` VARCHAR(60) NULL AFTER `score_source`,
    ADD COLUMN `external_fixture_id` VARCHAR(80) NULL AFTER `external_provider`;

CREATE INDEX `idx_fixtures_live_lookup`
    ON `fixtures` (`status`, `match_date`, `live_updated_at`);

CREATE INDEX `idx_fixtures_external_fixture`
    ON `fixtures` (`external_provider`, `external_fixture_id`);
