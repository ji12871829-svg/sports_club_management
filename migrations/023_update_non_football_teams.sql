-- ============================================================
-- UPDATE ALL NON-FOOTBALL TEAMS TO REAL-WORLD EQUIVALENTS
-- This migration updates rugby, hockey, volleyball, chess, horse riding,
-- and badminton teams to real-world club names and venues.
-- ============================================================

-- ── RUGBY (14 teams) ──────────────────────────────────────────
UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Leinster Rugby',    t.`short_name` = 'LEI', t.`home_ground` = 'RDS Arena'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Nairobi RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Saracens',          t.`short_name` = 'SAR', t.`home_ground` = 'StoneX Stadium'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Mombasa RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Toulouse',          t.`short_name` = 'TOU', t.`home_ground` = 'Stade Ernest Wallon'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Kisumu RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Ulster Rugby',      t.`short_name` = 'ULS', t.`home_ground` = 'Kingspan Stadium'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Nakuru RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Munster Rugby',     t.`short_name` = 'MUN', t.`home_ground` = 'Thomond Park'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Eldoret RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Exeter Chiefs',     t.`short_name` = 'EXE', t.`home_ground` = 'Sandy Park'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Thika RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Northampton Saints',t.`short_name` = 'NOR', t.`home_ground` = 'cinch Stadium'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Machakos RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Bath Rugby',        t.`short_name` = 'BAT', t.`home_ground` = 'The Rec'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Meru RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Racing 92',         t.`short_name` = 'RAC', t.`home_ground` = 'Paris La Défense Arena'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Kitale RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'La Rochelle',       t.`short_name` = 'LAR', t.`home_ground` = 'Stade Marcel Deflandre'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Nyeri RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Harlequins',        t.`short_name` = 'HAR', t.`home_ground` = 'Twickenham Stoop'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Kericho RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Glasgow Warriors',  t.`short_name` = 'GLA', t.`home_ground` = 'Scotstoun Stadium'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Naivasha RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Clermont Auvergne', t.`short_name` = 'ASM', t.`home_ground` = 'Stade Marcel Michelin'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Kakamega RFC';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Edinburgh Rugby',   t.`short_name` = 'EDI', t.`home_ground` = 'Murrayfield Stadium'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026' AND t.`name` = 'Malindi RFC';

-- ── HOCKEY (8 teams) ────────────────────────────────────────
UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Club an der Alster',    t.`short_name` = 'ALS', t.`home_ground` = 'An der Alster Hamburg'
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026' AND t.`name` = 'Nairobi Hockey Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'SV Kampong',            t.`short_name` = 'KAM', t.`home_ground` = 'Kampong Sportpark'
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026' AND t.`name` = 'Mombasa Hockey Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Rot-Weiss Köln',        t.`short_name` = 'RWK', t.`home_ground` = 'Sportpark Lentpark'
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026' AND t.`name` = 'Kisumu Hockey Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'HC Den Bosch',          t.`short_name` = 'DBO', t.`home_ground` = 'Kapittelhof'
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026' AND t.`name` = 'Nakuru Hockey Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Atlético de Madrid HC', t.`short_name` = 'ATL', t.`home_ground` = 'Estadio Atlético'
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026' AND t.`name` = 'Eldoret Hockey Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'RC Polo Barcelona',     t.`short_name` = 'POL', t.`home_ground` = 'Polo Club Barcelona'
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026' AND t.`name` = 'Thika Hockey Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Oranje Zwart',          t.`short_name` = 'OZW', t.`home_ground` = 'OZ Sportpark Eindhoven'
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026' AND t.`name` = 'Machakos Hockey Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Beeston HC',            t.`short_name` = 'BEE', t.`home_ground` = 'Woodlands Ground Leeds'
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026' AND t.`name` = 'Meru Hockey Club';

-- ── VOLLEYBALL (8 teams) ───────────────────────────────────
UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Trentino Itas',      t.`short_name` = 'TRE', t.`home_ground` = 'BLM Group Arena'
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026' AND t.`name` = 'Nairobi Volleyball Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Zenit Kazan',        t.`short_name` = 'ZEN', t.`home_ground` = 'Basket Hall Kazan'
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026' AND t.`name` = 'Mombasa Volleyball Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Zaksa Kedzierzyn',   t.`short_name` = 'ZAK', t.`home_ground` = 'Azoty Arena'
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026' AND t.`name` = 'Kisumu Volleyball Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Sir Safety Perugia', t.`short_name` = 'PER', t.`home_ground` = 'PalaBarton Perugia'
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026' AND t.`name` = 'Nakuru Volleyball Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Lube Civitanova',    t.`short_name` = 'LUB', t.`home_ground` = 'Eurosuole Forum'
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026' AND t.`name` = 'Eldoret Volleyball Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Modena Volley',      t.`short_name` = 'MOD', t.`home_ground` = 'PalaPanini Modena'
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026' AND t.`name` = 'Thika Volleyball Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Halkbank Ankara',    t.`short_name` = 'HAL', t.`home_ground` = 'Ankara Arena'
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026' AND t.`name` = 'Machakos Volleyball Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Dinamo Moscow',      t.`short_name` = 'DIN', t.`home_ground` = 'Luzhniki Moscow'
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026' AND t.`name` = 'Meru Volleyball Club';

-- ── CHESS (6 teams) ───────────────────────────────────────
UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'AVE Novy Bor',          t.`short_name` = 'NBO', t.`home_ground` = 'Novy Bor Club'
WHERE s.`name` = 'Chess' AND l.`name` = 'Chess League' AND l.`season` = '2026' AND t.`name` = 'Nairobi Chess Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Baden-Baden',           t.`short_name` = 'BAD', t.`home_ground` = 'Schachklub Baden-Baden'
WHERE s.`name` = 'Chess' AND l.`name` = 'Chess League' AND l.`season` = '2026' AND t.`name` = 'Mombasa Chess Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Cercle d''Echecs Paris', t.`short_name` = 'CEP', t.`home_ground` = 'Paris Chess Centre'
WHERE s.`name` = 'Chess' AND l.`name` = 'Chess League' AND l.`season` = '2026' AND t.`name` = 'Kisumu Chess Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'SOCAR Azerbaijan',      t.`short_name` = 'SOC', t.`home_ground` = 'Baku Chess House'
WHERE s.`name` = 'Chess' AND l.`name` = 'Chess League' AND l.`season` = '2026' AND t.`name` = 'Nakuru Chess Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Istanbul BBSK',         t.`short_name` = 'BBK', t.`home_ground` = 'Istanbul Chess Club'
WHERE s.`name` = 'Chess' AND l.`name` = 'Chess League' AND l.`season` = '2026' AND t.`name` = 'Eldoret Chess Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Alkaloid Skopje',       t.`short_name` = 'ALK', t.`home_ground` = 'Skopje Chess Centre'
WHERE s.`name` = 'Chess' AND l.`name` = 'Chess League' AND l.`season` = '2026' AND t.`name` = 'Thika Chess Club';

-- ── HORSE RIDING (6 teams) ─────────────────────────────────
UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Tops International Stables',  t.`short_name` = 'TOP', t.`home_ground` = 'Tops Arena Valkenswaard'
WHERE s.`name` = 'Horse Riding' AND l.`name` = 'Horse Riding League' AND l.`season` = '2026' AND t.`name` = 'Nairobi Riding Team';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Stephex Stables',             t.`short_name` = 'STE', t.`home_ground` = 'Stephex Stables Brussels'
WHERE s.`name` = 'Horse Riding' AND l.`name` = 'Horse Riding League' AND l.`season` = '2026' AND t.`name` = 'Mombasa Riding Team';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Ludger Beerbaum Stables',     t.`short_name` = 'LBS', t.`home_ground` = 'Beerbaum Stables Riesenbeck'
WHERE s.`name` = 'Horse Riding' AND l.`name` = 'Horse Riding League' AND l.`season` = '2026' AND t.`name` = 'Kisumu Riding Team';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'McLain Ward Stable',          t.`short_name` = 'MCW', t.`home_ground` = 'Clairon Farm New York'
WHERE s.`name` = 'Horse Riding' AND l.`name` = 'Horse Riding League' AND l.`season` = '2026' AND t.`name` = 'Nakuru Riding Team';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Spruce Meadows Team',         t.`short_name` = 'SPM', t.`home_ground` = 'Spruce Meadows Calgary'
WHERE s.`name` = 'Horse Riding' AND l.`name` = 'Horse Riding League' AND l.`season` = '2026' AND t.`name` = 'Eldoret Riding Team';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Abu Dhabi Equestrian Team',   t.`short_name` = 'ADE', t.`home_ground` = 'Al Forsan International'
WHERE s.`name` = 'Horse Riding' AND l.`name` = 'Horse Riding League' AND l.`season` = '2026' AND t.`name` = 'Thika Riding Team';

-- Note: The existing placeholder teams are not all one-to-one with the requested names,
-- so this migration updates six real-world horse riding teams while preserving IDs.

-- ── BADMINTON (8 teams) ───────────────────────────────────
UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Li-Ning Badminton Club',  t.`short_name` = 'LIN', t.`home_ground` = 'Li-Ning Arena Beijing'
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026' AND t.`name` = 'Nairobi Badminton Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Yonex Japan Club',        t.`short_name` = 'YON', t.`home_ground` = 'Yonex Arena Tokyo'
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026' AND t.`name` = 'Mombasa Badminton Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Victor Korea Club',       t.`short_name` = 'VIC', t.`home_ground` = 'KOVO Arena Seoul'
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026' AND t.`name` = 'Kisumu Badminton Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Djarum Kudus',            t.`short_name` = 'DJA', t.`home_ground` = 'Djarum Hall Kudus'
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026' AND t.`name` = 'Nakuru Badminton Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'PB Djarum',               t.`short_name` = 'PBD', t.`home_ground` = 'Djarum Badminton Hall'
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026' AND t.`name` = 'Eldoret Badminton Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Performa Badminton',      t.`short_name` = 'PBA', t.`home_ground` = 'Performa Centre Kuala Lumpur'
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026' AND t.`name` = 'Thika Badminton Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Feng Tian Badminton',     t.`short_name` = 'FTB', t.`home_ground` = 'Feng Tian Arena Guangzhou'
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026' AND t.`name` = 'Machakos Badminton Club';

UPDATE `teams` t
JOIN `leagues` l ON l.`league_id` = t.`league_id`
JOIN `sports` s ON s.`sport_id` = t.`sport_id`
SET t.`name` = 'Badminton England Elite', t.`short_name` = 'BEE', t.`home_ground` = 'National Badminton Centre'
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026' AND t.`name` = 'Meru Badminton Club';

-- ── UPDATE LEAGUE DESCRIPTIONS ──────────────────────────────
UPDATE `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
SET l.`name` = 'Rugby Champions Cup',
    l.`description` = 'Fourteen-club league featuring top European rugby union clubs.'
WHERE s.`name` = 'Rugby' AND l.`name` = 'Rugby Championship League' AND l.`season` = '2026';

UPDATE `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
SET l.`name` = 'EHL Hockey League',
    l.`description` = 'Eight top international field hockey clubs.'
WHERE s.`name` = 'Hockey' AND l.`name` = 'Hockey League' AND l.`season` = '2026';

UPDATE `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
SET l.`name` = 'CEV Champions League',
    l.`description` = 'Eight top international volleyball clubs.'
WHERE s.`name` = 'Volleyball' AND l.`name` = 'Volleyball League' AND l.`season` = '2026';

UPDATE `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
SET l.`name` = 'European Chess Cup',
    l.`description` = 'Six elite chess clubs from the European Chess Club Cup.'
WHERE s.`name` = 'Chess' AND l.`name` = 'Chess League' AND l.`season` = '2026';

UPDATE `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
SET l.`name` = 'Global Equestrian Tour',
    l.`description` = 'Six world-class show jumping stables.'
WHERE s.`name` = 'Horse Riding' AND l.`name` = 'Horse Riding League' AND l.`season` = '2026';

UPDATE `leagues` l
JOIN `sports` s ON s.`sport_id` = l.`sport_id`
SET l.`name` = 'BWF Club Championship',
    l.`description` = 'Eight top badminton clubs from the BWF circuit.'
WHERE s.`name` = 'Badminton' AND l.`name` = 'Badminton League' AND l.`season` = '2026';
