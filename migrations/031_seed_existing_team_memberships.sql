-- Migration 031: Seed memberships for the current teams and expose seeded roster members.

-- The members.sport_id / members.position / members.show_in_directory columns used
-- below came from the removed database.sql and were never created by a migration.
-- Add them here (guarded, so this is a no-op on databases that already have them)
-- so fresh installs built purely from migrations work.
ALTER TABLE `members`
    ADD COLUMN IF NOT EXISTS `sport_id` INT NULL DEFAULT NULL AFTER `address`,
    ADD COLUMN IF NOT EXISTS `position` VARCHAR(60) NULL DEFAULT NULL AFTER `sport_id`,
    ADD COLUMN IF NOT EXISTS `show_in_directory` TINYINT(1) NOT NULL DEFAULT 0 AFTER `position`;

UPDATE members
SET show_in_directory = 1
WHERE email LIKE '%@apexsportsclub.local';

INSERT INTO members (`first_name`, `last_name`, `email`, `password`, `phone_number`, `address`, `sport_id`, `show_in_directory`)
SELECT
    CONCAT(SUBSTRING_INDEX(t.name, ' ', 1), ' Player') AS first_name,
    CONCAT('of ', REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.name, ' ', '_'), '&', 'and'), '-', '_'), '.', ''), '''', '')) AS last_name,
    CONCAT(
        LOWER(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(t.name, ' ', '_'),
                            '&', 'and'
                        ),
                        '-', '_'
                    ),
                    '.', ''
                ),
                '''', ''
            )
        ),
        '_player@apexsportsclub.local'
    ) AS email,
    '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890' AS password,
    '000-000-0000' AS phone_number,
    CONCAT(t.name, ' Training Ground') AS address,
    CASE t.league_id
        WHEN 1 THEN 2
        WHEN 2 THEN 1
        WHEN 3 THEN 3
        WHEN 4 THEN 4
        WHEN 5 THEN 5
        WHEN 6 THEN 6
        WHEN 7 THEN 7
        ELSE NULL
    END AS sport_id,
    1 AS show_in_directory
FROM teams t
ON DUPLICATE KEY UPDATE email = email;

INSERT IGNORE INTO team_memberships (`league_id`, `team_id`, `member_id`, `role`, `status`)
SELECT
    t.league_id,
    t.team_id,
    m.member_id,
    'Player',
    'Active'
FROM teams t
JOIN members m ON m.email = CONCAT(
        LOWER(
            REPLACE(
                REPLACE(
                    REPLACE(
                        REPLACE(
                            REPLACE(t.name, ' ', '_'),
                            '&', 'and'
                        ),
                        '-', '_'
                    ),
                    '.', ''
                ),
                '''', ''
            )
        ),
        '_player@apexsportsclub.local'
    )
WHERE m.email IS NOT NULL;
