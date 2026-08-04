ALTER TABLE `fixtures`
    ADD COLUMN `referee_pin` CHAR(4) NULL AFTER `live_updated_at`;

CREATE INDEX `idx_fixtures_referee_pin` ON `fixtures` (`referee_pin`);
