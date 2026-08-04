-- Replace Football Premier League club names with English Premier League teams (in-place; keeps team_id / fixtures / standings).

UPDATE `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET l.`description` = 'Fifteen-club league featuring English Premier League teams.'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Arsenal', t.`short_name` = 'ARS', t.`home_ground` = 'Emirates Stadium'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Nairobi United FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Aston Villa', t.`short_name` = 'AVL', t.`home_ground` = 'Villa Park'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Mombasa City FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Bournemouth', t.`short_name` = 'BOU', t.`home_ground` = 'Vitality Stadium'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Kisumu Stars FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Brentford', t.`short_name` = 'BRE', t.`home_ground` = 'Gtech Community Stadium'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Nakuru Athletic FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Brighton & Hove Albion', t.`short_name` = 'BHA', t.`home_ground` = 'Amex Stadium'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Eldoret Rangers FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Chelsea', t.`short_name` = 'CHE', t.`home_ground` = 'Stamford Bridge'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Thika Rovers FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Crystal Palace', t.`short_name` = 'CRY', t.`home_ground` = 'Selhurst Park'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Machakos Royals FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Everton', t.`short_name` = 'EVE', t.`home_ground` = 'Goodison Park'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Meru County FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Fulham', t.`short_name` = 'FUL', t.`home_ground` = 'Craven Cottage'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Kitale Warriors FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Liverpool', t.`short_name` = 'LIV', t.`home_ground` = 'Anfield'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Nyeri Highlanders FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Manchester City', t.`short_name` = 'MCI', t.`home_ground` = 'Etihad Stadium'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Kericho Green FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Manchester United', t.`short_name` = 'MUN', t.`home_ground` = 'Old Trafford'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Naivasha United FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Newcastle United', t.`short_name` = 'NEW', t.`home_ground` = 'St James'' Park'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Kakamega Town FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Nottingham Forest', t.`short_name` = 'NFO', t.`home_ground` = 'City Ground'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Malindi Coast FC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = l.`sport_id` AND s.`name` = 'Football'
SET t.`name` = 'Tottenham Hotspur', t.`short_name` = 'TOT', t.`home_ground` = 'Tottenham Hotspur Stadium'
WHERE l.`name` = 'Football Premier League' AND l.`season` = '2026' AND t.`name` = 'Garissa Plains FC';
