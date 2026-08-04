-- Add five teams to the Football Premier League (15 → 20 clubs) and seed standings rows.

UPDATE `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET l.`team_limit` = 20,
    l.`description` = 'Twenty-club league featuring English Premier League teams.'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026';

INSERT INTO `teams` (`league_id`, `sport_id`, `name`, `short_name`, `home_ground`)
SELECT l.`league_id`, s.`sport_id`, x.`team_name`, x.`short_name`, x.`home_ground`
FROM `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
JOIN (
    SELECT 'West Ham United' AS team_name, 'WHU' AS short_name, 'London Stadium' AS home_ground UNION ALL
    SELECT 'Wolverhampton Wanderers', 'WOL', 'Molineux Stadium' UNION ALL
    SELECT 'Leicester City', 'LEI', 'King Power Stadium' UNION ALL
    SELECT 'Ipswich Town', 'IPS', 'Portman Road' UNION ALL
    SELECT 'Leeds United', 'LEE', 'Elland Road'
) x
WHERE s.`name` = 'Football' AND l.`name` = 'Football Premier League' AND l.`season` = '2026'
ON DUPLICATE KEY UPDATE `short_name` = VALUES(`short_name`), `home_ground` = VALUES(`home_ground`);

INSERT IGNORE INTO `standings` (`league_id`, `team_id`)
SELECT t.`league_id`, t.`team_id`
FROM `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
WHERE s.`name` = 'Football'
  AND l.`name` = 'Football Premier League'
  AND l.`season` = '2026'
  AND t.`name` IN (
      'West Ham United',
      'Wolverhampton Wanderers',
      'Leicester City',
      'Ipswich Town',
      'Leeds United'
  );
