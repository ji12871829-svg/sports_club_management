-- Migration 032: Replace dummy team members with real player names for each existing team.

-- Remove previous dummy roster members and their memberships.
DELETE tm
FROM team_memberships tm
JOIN members m ON tm.member_id = m.member_id
WHERE m.email LIKE '%_player@apexsportsclub.local';

DELETE FROM members
WHERE email LIKE '%_player@apexsportsclub.local';

-- Seed real players for each current team.
INSERT INTO members (`first_name`, `last_name`, `email`, `password`, `phone_number`, `address`, `sport_id`, `show_in_directory`, `position`)
VALUES
('Bukayo', 'Saka', 'bukayo.saka@arsenal.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0001', 'Arsenal Training Ground', 2, 1, 'Winger'),
('Ollie', 'Watkins', 'ollie.watkins@aston_villa.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0002', 'Aston Villa Training Ground', 2, 1, 'Forward'),
('Dominic', 'Solanke', 'dominic.solanke@bournemouth.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0003', 'Bournemouth Training Ground', 2, 1, 'Forward'),
('Ivan', 'Toney', 'ivan.toney@brentford.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0004', 'Brentford Training Ground', 2, 1, 'Forward'),
('Moisés', 'Caicedo', 'moises.caicedo@brighton_and_hove_albion.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0005', 'Brighton Training Ground', 2, 1, 'Midfielder'),
('Cole', 'Palmer', 'cole.palmer@chelsea.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0006', 'Chelsea Training Ground', 2, 1, 'Attacking Midfielder'),
('Eberechi', 'Eze', 'eberechi.eze@crystal_palace.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0007', 'Crystal Palace Training Ground', 2, 1, 'Attacking Midfielder'),
('James', 'Tarkowski', 'james.tarkowski@everton.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0008', 'Everton Training Ground', 2, 1, 'Defender'),
('Aleksandar', 'Mitrovic', 'aleksandar.mitrovic@fulham.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0009', 'Fulham Training Ground', 2, 1, 'Striker'),
('Mohamed', 'Salah', 'mohamed.salah@liverpool.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0010', 'Liverpool Training Ground', 2, 1, 'Forward'),
('Kevin', 'De Bruyne', 'kevin.de_bruyne@manchester_city.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0011', 'Manchester City Training Ground', 2, 1, 'Midfielder'),
('Marcus', 'Rashford', 'marcus.rashford@manchester_united.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0012', 'Manchester United Training Ground', 2, 1, 'Forward'),
('Alexander', 'Isak', 'alexander.isak@newcastle_united.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0013', 'Newcastle United Training Ground', 2, 1, 'Striker'),
('Brennan', 'Johnson', 'brennan.johnson@nottingham_forest.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0014', 'Nottingham Forest Training Ground', 2, 1, 'Winger'),
('Harry', 'Kane', 'harry.kane@tottenham_hotspur.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0015', 'Tottenham Hotspur Training Ground', 2, 1, 'Striker'),
('Jarrod', 'Bowen', 'jarrod.bowen@west_ham_united.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0016', 'West Ham United Training Ground', 2, 1, 'Winger'),
('Matheus', 'Cunha', 'matheus.cunha@wolverhampton_wanderers.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0017', 'Wolverhampton Wanderers Training Ground', 2, 1, 'Forward'),
('Kelechi', 'Iheanacho', 'kelechi.iheanacho@leicester_city.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0018', 'Leicester City Training Ground', 2, 1, 'Forward'),
('James', 'Norwood', 'james.norwood@ipswich_town.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0019', 'Ipswich Town Training Ground', 2, 1, 'Forward'),
('Luke', 'Ayling', 'luke.ayling@leeds_united.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0020', 'Leeds United Training Ground', 2, 1, 'Defender'),
('Johnny', 'Sexton', 'johnny.sexton@leinster_rugby.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0021', 'Leinster Rugby Training Ground', 1, 1, 'Fly-half'),
('Maro', 'Itoje', 'maro.itoje@saracens.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0022', 'Saracens Training Ground', 1, 1, 'Lock'),
('Antoine', 'Dupont', 'antoine.dupont@toulouse.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0023', 'Toulouse Rugby Training Ground', 1, 1, 'Scrum-half'),
('Jacob', 'Stockdale', 'jacob.stockdale@ulster_rugby.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0024', 'Ulster Rugby Training Ground', 1, 1, 'Wing'),
('Peter', 'O\'Mahony', 'peter.omahony@munster_rugby.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0025', 'Munster Rugby Training Ground', 1, 1, 'Flanker'),
('Sam', 'Simmonds', 'sam.simmonds@exeter_chiefs.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0026', 'Exeter Chiefs Training Ground', 1, 1, 'Number 8'),
('Courtney', 'Lawes', 'courtney.lawes@northampton_saints.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0027', 'Northampton Saints Training Ground', 1, 1, 'Lock'),
('Finn', 'Russell', 'finn.russell@bath_rugby.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0028', 'Bath Rugby Training Ground', 1, 1, 'Fly-half'),
('Teddy', 'Thomas', 'teddy.thomas@racing_92.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0029', 'Racing 92 Training Ground', 1, 1, 'Wing'),
('Gregory', 'Alldritt', 'gregory.alldritt@la_rochelle.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0030', 'La Rochelle Training Ground', 1, 1, 'Number 8'),
('Marcus', 'Smith', 'marcus.smith@harlequins.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0031', 'Harlequins Training Ground', 1, 1, 'Fly-half'),
('Ali', 'Price', 'ali.price@glasgow_warriors.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0032', 'Glasgow Warriors Training Ground', 1, 1, 'Scrum-half'),
('Wesley', 'Fofana', 'wesley.fofana@clermont_auvergne.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0033', 'Clermont Auvergne Training Ground', 1, 1, 'Centre'),
('Stuart', 'Hogg', 'stuart.hogg@edinburgh_rugby.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0034', 'Edinburgh Rugby Training Ground', 1, 1, 'Fullback'),
('Tobias', 'Hauke', 'tobias.hauke@club_an_der_alster.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0035', 'Club an der Alster Field', 3, 1, 'Forward'),
('Jeroen', 'Hertzberger', 'jeroen.hertzberger@sv_kampong.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0036', 'SV Kampong Field', 3, 1, 'Forward'),
('Christopher', 'Rühr', 'christopher.ruhr@rot_weiss_koln.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0037', 'Rot-Weiss Köln Field', 3, 1, 'Forward'),
('Billy', 'Bakker', 'billy.bakker@hc_den_bosch.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0038', 'HC Den Bosch Field', 3, 1, 'Midfielder'),
('Santi', 'Freixa', 'santi.freixa@atletico_de_madrid_hc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0039', 'Atletico de Madrid HC Field', 3, 1, 'Forward'),
('Pau', 'Cunill', 'pau.cunill@rc_polo_barcelona.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0040', 'RC Polo Barcelona Field', 3, 1, 'Forward'),
('Tom', 'Boon', 'tom.boon@oranje_zwart.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0041', 'Oranje Zwart Field', 3, 1, 'Forward'),
('Sam', 'Ward', 'sam.ward@beeston_hc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0042', 'Beeston HC Field', 3, 1, 'Forward'),
('Earvin', 'N\'Gapeth', 'earvin.ngapeth@trentino_itas.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0043', 'Trentino Itas Court', 4, 1, 'Outside Hitter'),
('Maxim', 'Mikhaylov', 'maxim.mikhaylov@zenit_kazan.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0044', 'Zenit Kazan Court', 4, 1, 'Opposite'),
('Wilfredo', 'Leon', 'wilfredo.leon@zaksa_kedzierzyn.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0045', 'Zaksa Kedzierzyn Court', 4, 1, 'Outside Hitter'),
('Simone', 'Giannelli', 'simone.giannelli@sir_safety_perugia.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0046', 'Sir Safety Perugia Court', 4, 1, 'Setter'),
('Osmany', 'Juantorena', 'osmany.juantorena@lube_civitanova.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0047', 'Lube Civitanova Court', 4, 1, 'Outside Hitter'),
('Fabio', 'Balaso', 'fabio.balaso@modena_volley.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0048', 'Modena Volley Court', 4, 1, 'Setter'),
('Ivan', 'Zaytsev', 'ivan.zaytsev@halkbank_ankara.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0049', 'Halkbank Ankara Court', 4, 1, 'Opposite'),
('Evandro', 'Guerra', 'evandro.guerra@dinamo_moscow.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0050', 'Dinamo Moscow Court', 4, 1, 'Outside Hitter'),
('David', 'Navara', 'david.navara@ave_novy_bor.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0051', 'AVE Novy Bor Chess Hall', 5, 1, 'Grandmaster'),
('Fabiano', 'Caruana', 'fabiano.caruana@baden_baden_sc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0052', 'Baden-Baden Chess Hall', 5, 1, 'Grandmaster'),
('Maxime', 'Vachier-Lagrave', 'maxime.vachier_lagrave@cercle_echecs_paris.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0053', 'Cercle Echecs Paris Hall', 5, 1, 'Grandmaster'),
('Shakhriyar', 'Mamedyarov', 'shakhriyar.mamedyarov@socar_azerbaijan.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0054', 'SOCAR Azerbaijan Chess Hall', 5, 1, 'Grandmaster'),
('Anish', 'Giri', 'anish.giri@istanbul_bbsk.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0055', 'Istanbul BBSK Chess Hall', 5, 1, 'Grandmaster'),
('Alexei', 'Shirov', 'alexei.shirov@alkaloid_skopje.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0056', 'Alkaloid Skopje Chess Hall', 5, 1, 'Grandmaster'),
('Scott', 'Brash', 'scott.brash@tops_international_stables.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0057', 'Tops International Stables', 6, 1, 'Rider'),
('Kevin', 'Staut', 'kevin.staut@stephex_stables.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0058', 'Stephex Stables', 6, 1, 'Rider'),
('Ludger', 'Beerbaum', 'ludger.beerbaum@beerbaum_stables.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0059', 'Beerbaum Stables', 6, 1, 'Rider'),
('McLain', 'Ward', 'mclain.ward@mclain_ward_stable.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0060', 'McLain Ward Stable', 6, 1, 'Rider'),
('Eric', 'Lamaze', 'eric.lamaze@spruce_meadows_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0061', 'Spruce Meadows Team', 6, 1, 'Rider'),
('Ali', 'Al-Thani', 'ali.al_thani@abu_dhabi_equestrian.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0062', 'Abu Dhabi Equestrian Center', 6, 1, 'Rider'),
('Kento', 'Momota', 'kento.momota@li_ning_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0063', 'Li-Ning Badminton Club', 7, 1, 'Singles'),
('Viktor', 'Axelsen', 'viktor.axelsen@yonex_japan_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0064', 'Yonex Japan Club', 7, 1, 'Singles'),
('Lee', 'Zii Jia', 'lee.zii_jia@victor_korea_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0065', 'Victor Korea Club', 7, 1, 'Singles'),
('Anthony', 'Sinisuka Ginting', 'anthony.ginting@djarum_kudus.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0066', 'Djarum Kudus', 7, 1, 'Singles'),
('Jonatan', 'Christie', 'jonatan.christie@pb_djarum.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0067', 'PB Djarum', 7, 1, 'Singles'),
('Carolina', 'Marín', 'carolina.marin@performa_badminton.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0068', 'Performa Badminton', 7, 1, 'Singles'),
('Chen', 'Long', 'chen.long@feng_tian_badminton.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0069', 'Feng Tian Badminton', 7, 1, 'Singles'),
('Marcus', 'Ellis', 'marcus.ellis@badminton_england_elite.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0070', 'Badminton England Elite', 7, 1, 'Doubles');

-- Link seeded players to their existing teams.
INSERT IGNORE INTO team_memberships (`league_id`, `team_id`, `member_id`, `role`, `status`)
SELECT t.league_id, t.team_id, m.member_id, 'Player', 'Active'
FROM teams t
JOIN members m ON m.email LIKE CONCAT(
        '%@',
        LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.name, ' ', '_'), '&', 'and'), '-', '_'), '.', ''), '''', '')),
        '.apexsportsclub.local'
    );
