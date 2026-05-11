USE `sports_club_db`;

-- Ensure the core sports exist before creating league structures.
INSERT INTO `sports` (`name`, `description`) VALUES
('Rugby', 'A contact team sport played with an oval ball.'),
('Football', 'An 11-a-side field sport played with a round ball.'),
('Hockey', 'An 11-a-side field sport played with sticks and a ball.'),
('Volleyball', 'A 6-a-side court sport played over a net.'),
('Chess', 'A strategic board sport played individually and in teams.'),
('Horse Riding', 'An equestrian sport for training, jumping, and riding events.'),
('Badminton', 'A racquet sport played as singles, doubles, and team events.')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

CREATE TABLE IF NOT EXISTS `leagues` (
    `league_id` INT AUTO_INCREMENT PRIMARY KEY,
    `sport_id` INT NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `season` VARCHAR(20) NOT NULL DEFAULT '2026',
    `team_limit` INT NOT NULL,
    `team_format` VARCHAR(80) NOT NULL,
    `max_players_per_team` INT NOT NULL DEFAULT 25,
    `description` TEXT,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_league_sport_name_season` (`sport_id`, `name`, `season`),
    KEY `idx_leagues_sport` (`sport_id`),
    CONSTRAINT `fk_leagues_sport`
        FOREIGN KEY (`sport_id`) REFERENCES `sports` (`sport_id`)
        ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS `teams` (
    `team_id` INT AUTO_INCREMENT PRIMARY KEY,
    `league_id` INT NOT NULL,
    `sport_id` INT NOT NULL,
    `name` VARCHAR(120) NOT NULL,
    `short_name` VARCHAR(30),
    `home_ground` VARCHAR(120),
    `coach_id` INT NULL,
    `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_team_league_name` (`league_id`, `name`),
    KEY `idx_teams_league` (`league_id`),
    KEY `idx_teams_sport` (`sport_id`),
    KEY `idx_teams_coach` (`coach_id`),
    CONSTRAINT `fk_teams_league`
        FOREIGN KEY (`league_id`) REFERENCES `leagues` (`league_id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_teams_sport`
        FOREIGN KEY (`sport_id`) REFERENCES `sports` (`sport_id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_teams_coach`
        FOREIGN KEY (`coach_id`) REFERENCES `coaches` (`coach_id`)
        ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS `team_memberships` (
    `membership_id` INT AUTO_INCREMENT PRIMARY KEY,
    `league_id` INT NOT NULL,
    `team_id` INT NOT NULL,
    `member_id` INT NOT NULL,
    `role` VARCHAR(30) NOT NULL DEFAULT 'Player',
    `status` VARCHAR(30) NOT NULL DEFAULT 'Active',
    `registered_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_member_one_team_per_league` (`league_id`, `member_id`),
    UNIQUE KEY `uq_member_team` (`team_id`, `member_id`),
    KEY `idx_memberships_team` (`team_id`),
    KEY `idx_memberships_member` (`member_id`),
    CONSTRAINT `fk_memberships_league`
        FOREIGN KEY (`league_id`) REFERENCES `leagues` (`league_id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_memberships_team`
        FOREIGN KEY (`team_id`) REFERENCES `teams` (`team_id`)
        ON DELETE CASCADE,
    CONSTRAINT `fk_memberships_member`
        FOREIGN KEY (`member_id`) REFERENCES `members` (`member_id`)
        ON DELETE CASCADE
);

INSERT INTO `leagues` (`sport_id`, `name`, `season`, `team_limit`, `team_format`, `max_players_per_team`, `description`)
SELECT `sport_id`, 'Football Premier League', '2026', 15, '11-a-side', 25, 'Fifteen-team football league for club competition.' FROM `sports` WHERE `name` = 'Football'
ON DUPLICATE KEY UPDATE `team_limit` = VALUES(`team_limit`), `team_format` = VALUES(`team_format`), `max_players_per_team` = VALUES(`max_players_per_team`);

INSERT INTO `leagues` (`sport_id`, `name`, `season`, `team_limit`, `team_format`, `max_players_per_team`, `description`)
SELECT `sport_id`, 'Rugby Championship League', '2026', 14, '15-a-side', 30, 'Fourteen-team rugby league for club competition.' FROM `sports` WHERE `name` = 'Rugby'
ON DUPLICATE KEY UPDATE `team_limit` = VALUES(`team_limit`), `team_format` = VALUES(`team_format`), `max_players_per_team` = VALUES(`max_players_per_team`);

INSERT INTO `leagues` (`sport_id`, `name`, `season`, `team_limit`, `team_format`, `max_players_per_team`, `description`)
SELECT `sport_id`, 'Hockey League', '2026', 8, '11-a-side', 20, 'Eight-team hockey league.' FROM `sports` WHERE `name` = 'Hockey'
ON DUPLICATE KEY UPDATE `team_limit` = VALUES(`team_limit`), `team_format` = VALUES(`team_format`), `max_players_per_team` = VALUES(`max_players_per_team`);

INSERT INTO `leagues` (`sport_id`, `name`, `season`, `team_limit`, `team_format`, `max_players_per_team`, `description`)
SELECT `sport_id`, 'Volleyball League', '2026', 8, '6-a-side', 14, 'Eight-team volleyball league.' FROM `sports` WHERE `name` = 'Volleyball'
ON DUPLICATE KEY UPDATE `team_limit` = VALUES(`team_limit`), `team_format` = VALUES(`team_format`), `max_players_per_team` = VALUES(`max_players_per_team`);

INSERT INTO `leagues` (`sport_id`, `name`, `season`, `team_limit`, `team_format`, `max_players_per_team`, `description`)
SELECT `sport_id`, 'Chess League', '2026', 6, 'team boards', 8, 'Six-team chess league.' FROM `sports` WHERE `name` = 'Chess'
ON DUPLICATE KEY UPDATE `team_limit` = VALUES(`team_limit`), `team_format` = VALUES(`team_format`), `max_players_per_team` = VALUES(`max_players_per_team`);

INSERT INTO `leagues` (`sport_id`, `name`, `season`, `team_limit`, `team_format`, `max_players_per_team`, `description`)
SELECT `sport_id`, 'Horse Riding League', '2026', 6, 'team events', 8, 'Six-team equestrian league.' FROM `sports` WHERE `name` = 'Horse Riding'
ON DUPLICATE KEY UPDATE `team_limit` = VALUES(`team_limit`), `team_format` = VALUES(`team_format`), `max_players_per_team` = VALUES(`max_players_per_team`);

INSERT INTO `leagues` (`sport_id`, `name`, `season`, `team_limit`, `team_format`, `max_players_per_team`, `description`)
SELECT `sport_id`, 'Badminton League', '2026', 8, 'singles and doubles', 10, 'Eight-team badminton league.' FROM `sports` WHERE `name` = 'Badminton'
ON DUPLICATE KEY UPDATE `team_limit` = VALUES(`team_limit`), `team_format` = VALUES(`team_format`), `max_players_per_team` = VALUES(`max_players_per_team`);

INSERT INTO `teams` (`league_id`, `sport_id`, `name`, `short_name`, `home_ground`)
SELECT l.`league_id`, s.`sport_id`, x.`team_name`, x.`short_name`, x.`home_ground`
FROM `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
JOIN (
    SELECT 'Nairobi United FC' AS team_name, 'NUFC' AS short_name, 'Main Stadium' AS home_ground UNION ALL
    SELECT 'Mombasa City FC', 'MCFC', 'Coast Arena' UNION ALL
    SELECT 'Kisumu Stars FC', 'KSFC', 'Lakeside Grounds' UNION ALL
    SELECT 'Nakuru Athletic FC', 'NAFC', 'Valley Stadium' UNION ALL
    SELECT 'Eldoret Rangers FC', 'ERFC', 'Highlands Park' UNION ALL
    SELECT 'Thika Rovers FC', 'TRFC', 'Thika Sports Ground' UNION ALL
    SELECT 'Machakos Royals FC', 'MRFC', 'County Stadium' UNION ALL
    SELECT 'Meru County FC', 'MCY', 'Meru Grounds' UNION ALL
    SELECT 'Kitale Warriors FC', 'KWFC', 'Kitale Stadium' UNION ALL
    SELECT 'Nyeri Highlanders FC', 'NHFC', 'Nyeri Park' UNION ALL
    SELECT 'Kericho Green FC', 'KGFC', 'Green Grounds' UNION ALL
    SELECT 'Naivasha United FC', 'NVU', 'Lake View Stadium' UNION ALL
    SELECT 'Kakamega Town FC', 'KTFC', 'Town Grounds' UNION ALL
    SELECT 'Malindi Coast FC', 'MLD', 'Coast Sports Field' UNION ALL
    SELECT 'Garissa Plains FC', 'GPFC', 'Garissa Stadium'
) x
WHERE s.`name` = 'Football' AND l.`name` = 'Football Premier League' AND l.`season` = '2026'
ON DUPLICATE KEY UPDATE `short_name` = VALUES(`short_name`), `home_ground` = VALUES(`home_ground`);

INSERT INTO `teams` (`league_id`, `sport_id`, `name`, `short_name`, `home_ground`)
SELECT l.`league_id`, s.`sport_id`, x.`team_name`, x.`short_name`, x.`home_ground`
FROM `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
JOIN (
    SELECT 'Nairobi RFC' AS team_name, 'NRFC' AS short_name, 'Main Rugby Field' AS home_ground UNION ALL
    SELECT 'Mombasa RFC', 'MRFC', 'Coast Rugby Ground' UNION ALL
    SELECT 'Kisumu RFC', 'KRFC', 'Lakeside Rugby Ground' UNION ALL
    SELECT 'Nakuru RFC', 'NKR', 'Valley Rugby Park' UNION ALL
    SELECT 'Eldoret RFC', 'ERFC', 'Highlands Rugby Ground' UNION ALL
    SELECT 'Thika RFC', 'TRFC', 'Thika Rugby Ground' UNION ALL
    SELECT 'Machakos RFC', 'MCR', 'County Rugby Field' UNION ALL
    SELECT 'Meru RFC', 'MER', 'Meru Rugby Ground' UNION ALL
    SELECT 'Kitale RFC', 'KIT', 'Kitale Rugby Field' UNION ALL
    SELECT 'Nyeri RFC', 'NYR', 'Nyeri Rugby Park' UNION ALL
    SELECT 'Kericho RFC', 'KER', 'Kericho Rugby Field' UNION ALL
    SELECT 'Naivasha RFC', 'NAI', 'Naivasha Rugby Ground' UNION ALL
    SELECT 'Kakamega RFC', 'KAK', 'Kakamega Rugby Park' UNION ALL
    SELECT 'Malindi RFC', 'MAL', 'Malindi Rugby Field'
) x
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026'
ON DUPLICATE KEY UPDATE `short_name` = VALUES(`short_name`), `home_ground` = VALUES(`home_ground`);

INSERT INTO `teams` (`league_id`, `sport_id`, `name`, `short_name`, `home_ground`)
SELECT l.`league_id`, s.`sport_id`, x.`team_name`, x.`short_name`, x.`home_ground`
FROM `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
JOIN (
    SELECT 'Nairobi Hockey Club' AS team_name, 'NHC' AS short_name, 'Hockey Field A' AS home_ground UNION ALL
    SELECT 'Mombasa Hockey Club', 'MHC', 'Coast Hockey Field' UNION ALL
    SELECT 'Kisumu Hockey Club', 'KHC', 'Lakeside Hockey Field' UNION ALL
    SELECT 'Nakuru Hockey Club', 'NKH', 'Valley Hockey Field' UNION ALL
    SELECT 'Eldoret Hockey Club', 'EHC', 'Highlands Hockey Field' UNION ALL
    SELECT 'Thika Hockey Club', 'THC', 'Thika Hockey Field' UNION ALL
    SELECT 'Machakos Hockey Club', 'MCH', 'County Hockey Field' UNION ALL
    SELECT 'Meru Hockey Club', 'MEH', 'Meru Hockey Field'
) x
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026'
ON DUPLICATE KEY UPDATE `short_name` = VALUES(`short_name`), `home_ground` = VALUES(`home_ground`);

INSERT INTO `teams` (`league_id`, `sport_id`, `name`, `short_name`, `home_ground`)
SELECT l.`league_id`, s.`sport_id`, x.`team_name`, x.`short_name`, x.`home_ground`
FROM `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
JOIN (
    SELECT 'Nairobi Volleyball Club' AS team_name, 'NVC' AS short_name, 'Indoor Hall A' AS home_ground UNION ALL
    SELECT 'Mombasa Volleyball Club', 'MVC', 'Coast Indoor Hall' UNION ALL
    SELECT 'Kisumu Volleyball Club', 'KVC', 'Lakeside Indoor Hall' UNION ALL
    SELECT 'Nakuru Volleyball Club', 'NKV', 'Valley Indoor Hall' UNION ALL
    SELECT 'Eldoret Volleyball Club', 'EVC', 'Highlands Indoor Hall' UNION ALL
    SELECT 'Thika Volleyball Club', 'TVC', 'Thika Indoor Hall' UNION ALL
    SELECT 'Machakos Volleyball Club', 'MCV', 'County Indoor Hall' UNION ALL
    SELECT 'Meru Volleyball Club', 'MEV', 'Meru Indoor Hall'
) x
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026'
ON DUPLICATE KEY UPDATE `short_name` = VALUES(`short_name`), `home_ground` = VALUES(`home_ground`);

INSERT INTO `teams` (`league_id`, `sport_id`, `name`, `short_name`, `home_ground`)
SELECT l.`league_id`, s.`sport_id`, x.`team_name`, x.`short_name`, x.`home_ground`
FROM `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
JOIN (
    SELECT 'Nairobi Chess Club' AS team_name, 'NCC' AS short_name, 'Club House Room 105' AS home_ground UNION ALL
    SELECT 'Mombasa Chess Club', 'MCC', 'Coast Chess Room' UNION ALL
    SELECT 'Kisumu Chess Club', 'KCC', 'Lakeside Chess Room' UNION ALL
    SELECT 'Nakuru Chess Club', 'NKC', 'Valley Chess Room' UNION ALL
    SELECT 'Eldoret Chess Club', 'ECC', 'Highlands Chess Room' UNION ALL
    SELECT 'Thika Chess Club', 'TCC', 'Thika Chess Room'
) x
WHERE s.`name` = 'Chess' AND l.`name` = 'Chess League' AND l.`season` = '2026'
ON DUPLICATE KEY UPDATE `short_name` = VALUES(`short_name`), `home_ground` = VALUES(`home_ground`);

INSERT INTO `teams` (`league_id`, `sport_id`, `name`, `short_name`, `home_ground`)
SELECT l.`league_id`, s.`sport_id`, x.`team_name`, x.`short_name`, x.`home_ground`
FROM `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
JOIN (
    SELECT 'Nairobi Riding Team' AS team_name, 'NRT' AS short_name, 'Equestrian Center A' AS home_ground UNION ALL
    SELECT 'Mombasa Riding Team', 'MRT', 'Coast Riding Arena' UNION ALL
    SELECT 'Kisumu Riding Team', 'KRT', 'Lakeside Riding Arena' UNION ALL
    SELECT 'Nakuru Riding Team', 'NKR', 'Valley Riding Arena' UNION ALL
    SELECT 'Eldoret Riding Team', 'ERT', 'Highlands Riding Arena' UNION ALL
    SELECT 'Thika Riding Team', 'TRT', 'Thika Riding Arena'
) x
WHERE s.`name` = 'Horse Riding' AND l.`name` = 'Horse Riding League' AND l.`season` = '2026'
ON DUPLICATE KEY UPDATE `short_name` = VALUES(`short_name`), `home_ground` = VALUES(`home_ground`);

INSERT INTO `teams` (`league_id`, `sport_id`, `name`, `short_name`, `home_ground`)
SELECT l.`league_id`, s.`sport_id`, x.`team_name`, x.`short_name`, x.`home_ground`
FROM `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
JOIN (
    SELECT 'Nairobi Badminton Club' AS team_name, 'NBC' AS short_name, 'Indoor Hall B' AS home_ground UNION ALL
    SELECT 'Mombasa Badminton Club', 'MBC', 'Coast Badminton Hall' UNION ALL
    SELECT 'Kisumu Badminton Club', 'KBC', 'Lakeside Badminton Hall' UNION ALL
    SELECT 'Nakuru Badminton Club', 'NKB', 'Valley Badminton Hall' UNION ALL
    SELECT 'Eldoret Badminton Club', 'EBC', 'Highlands Badminton Hall' UNION ALL
    SELECT 'Thika Badminton Club', 'TBC', 'Thika Badminton Hall' UNION ALL
    SELECT 'Machakos Badminton Club', 'MCB', 'County Badminton Hall' UNION ALL
    SELECT 'Meru Badminton Club', 'MEB', 'Meru Badminton Hall'
) x
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026'
ON DUPLICATE KEY UPDATE `short_name` = VALUES(`short_name`), `home_ground` = VALUES(`home_ground`);
