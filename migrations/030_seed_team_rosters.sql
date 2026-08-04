-- Migration 030: Seed team rosters with real player names for each team.

INSERT INTO `members` (`first_name`, `last_name`, `email`, `password`, `phone_number`, `address`)
VALUES
('Brian', 'Otieno', 'brian.otieno@nairobi_united_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-000-0001', 'Nairobi Sports Complex'),
('Amina', 'Wanjiru', 'amina.wanjiru@nairobi_united_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-000-0002', 'Nairobi Sports Complex'),
('Yusuf', 'Ali', 'yusuf.ali@mombasa_city_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-000-0001', 'Mombasa City Stadium'),
('Mary', 'Njeri', 'mary.njeri@mombasa_city_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-000-0002', 'Mombasa City Stadium'),
('Peter', 'Ouma', 'peter.ouma@kisumu_stars_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-000-0001', 'Kisumu Sports Ground'),
('Faith', 'Achieng', 'faith.achieng@kisumu_stars_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-000-0002', 'Kisumu Sports Ground'),
('David', 'Kiptoo', 'david.kiptoo@nakuru_athletic_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-000-0001', 'Nakuru Athletic Park'),
('Grace', 'Chebet', 'grace.chebet@nakuru_athletic_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-000-0002', 'Nakuru Athletic Park'),
('Paul', 'Kamau', 'paul.kamau@eldoret_rangers_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-000-0001', 'Eldoret Rangers Stadium'),
('Esther', 'Njeri', 'esther.njeri@eldoret_rangers_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-000-0002', 'Eldoret Rangers Stadium'),
('Michael', 'Mwangi', 'michael.mwangi@thika_rovers_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-000-0001', 'Thika Sports Ground'),
('Carol', 'Wanjiru', 'carol.wanjiru@thika_rovers_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-000-0002', 'Thika Sports Ground'),
('Kevin', 'Mutua', 'kevin.mutua@machakos_royals_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-000-0001', 'Machakos Royal Field'),
('Ruth', 'Nyambura', 'ruth.nyambura@machakos_royals_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-000-0002', 'Machakos Royal Field'),
('Dennis', 'Kirui', 'dennis.kirui@meru_county_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-000-0001', 'Meru County Grounds'),
('Lydia', 'Chebet', 'lydia.chebet@meru_county_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-000-0002', 'Meru County Grounds'),
('Eric', 'Kipkoech', 'eric.kipkoech@kitale_warriors_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-000-0001', 'Kitale Warriors Stadium'),
('Susan', 'Achieng', 'susan.achieng@kitale_warriors_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-000-0002', 'Kitale Warriors Stadium'),
('Daniel', 'Njoroge', 'daniel.njoroge@nyeri_highlanders_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-100-0001', 'Nyeri Highlanders Field'),
('Patricia', 'Wambui', 'patricia.wambui@nyeri_highlanders_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-100-0002', 'Nyeri Highlanders Field'),
('Simon', 'Bett', 'simon.bett@kericho_green_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-100-0003', 'Kericho Green Stadium'),
('Nancy', 'Chebet', 'nancy.chebet@kericho_green_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-100-0004', 'Kericho Green Stadium'),
('Patrick', 'Karanja', 'patrick.karanja@naivasha_united_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0001', 'Naivasha United Park'),
('Alice', 'Wanjiru', 'alice.wanjiru@naivasha_united_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0002', 'Naivasha United Park'),
('Charles', 'Odhiambo', 'charles.odhiambo@kakamega_town_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0003', 'Kakamega Town Grounds'),
('Jane', 'Auma', 'jane.auma@kakamega_town_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-100-0004', 'Kakamega Town Grounds'),
('Hassan', 'Juma', 'hassan.juma@malindi_coast_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-100-0001', 'Malindi Coastal Stadium'),
('Asha', 'Mwende', 'asha.mwende@malindi_coast_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-100-0002', 'Malindi Coastal Stadium'),
('Jamal', 'Yusuf', 'jamal.yusuf@garissa_plains_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-100-0001', 'Garissa Plains Field'),
('Hawa', 'Aden', 'hawa.aden@garissa_plains_fc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-100-0002', 'Garissa Plains Field'),
('Evan', 'Kipchumba', 'evan.kipchumba@nairobi_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-100-0001', 'Nairobi Rugby Grounds'),
('Lilian', 'Kinyua', 'lilian.kinyua@nairobi_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-100-0002', 'Nairobi Rugby Grounds'),
('Joseph', 'Mwangi', 'joseph.mwangi@mombasa_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-100-0001', 'Mombasa Rugby Ground'),
('Aisha', 'Abdalla', 'aisha.abdalla@mombasa_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-100-0002', 'Mombasa Rugby Ground'),
('Omondi', 'Awuor', 'omondi.awuor@kisumu_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-100-0001', 'Kisumu Rugby Field'),
('Jane', 'Khamala', 'jane.khamala@kisumu_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-100-0002', 'Kisumu Rugby Field'),
('Fredrick', 'Kiprono', 'fredrick.kiprono@nakuru_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-100-0001', 'Nakuru Rugby Park'),
('Sarah', 'Chebet', 'sarah.chebet@nakuru_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-100-0002', 'Nakuru Rugby Park'),
('Martin', 'Cheruiyot', 'martin.cheruiyot@eldoret_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-100-0001', 'Eldoret Rugby Park'),
('Carol', 'Jepkosgei', 'carol.jepkosgei@eldoret_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-100-0002', 'Eldoret Rugby Park'),
('Samson', 'Musyoka', 'samson.musyoka@thika_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-100-0001', 'Thika Rugby Ground'),
('Esther', 'Kimani', 'esther.kimani@thika_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-100-0002', 'Thika Rugby Ground'),
('Victor', 'Mutua', 'victor.mutua@machakos_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-200-0001', 'Machakos Rugby Field'),
('Mary', 'Wambui', 'mary.wambui@machakos_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-200-0002', 'Machakos Rugby Field'),
('Nelson', 'Langat', 'nelson.langat@meru_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-200-0001', 'Meru Rugby Ground'),
('Nancy', 'Kigen', 'nancy.kigen@meru_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-200-0002', 'Meru Rugby Ground'),
('Silas', 'Kiptoo', 'silas.kiptoo@kitale_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-200-0001', 'Kitale Rugby Field'),
('Beatrice', 'Chebet', 'beatrice.chebet@kitale_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-200-0002', 'Kitale Rugby Field'),
('Christopher', 'Bett', 'christopher.bett@nyeri_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-200-0001', 'Nyeri Rugby Park'),
('Judith', 'Korir', 'judith.korir@nyeri_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-200-0002', 'Nyeri Rugby Park'),
('Felix', 'Njenga', 'felix.njenga@naivasha_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-200-0001', 'Naivasha Rugby Field'),
('Rose', 'Wanjiru', 'rose.wanjiru@naivasha_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-200-0002', 'Naivasha Rugby Field'),
('Isaac', 'Odhiambo', 'isaac.odhiambo@kakamega_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-200-0001', 'Kakamega Rugby Park'),
('Prisca', 'Achieng', 'prisca.achieng@kakamega_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-200-0002', 'Kakamega Rugby Park'),
('Hussein', 'Baraza', 'hussein.baraza@malindi_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-200-0001', 'Malindi Rugby Field'),
('Halima', 'Juma', 'halima.juma@malindi_rfc.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-200-0002', 'Malindi Rugby Field'),
('Julia', 'Mwende', 'julia.mwende@nairobi_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-200-0001', 'Nairobi Hockey Field A'),
('Andrew', 'Karanja', 'andrew.karanja@nairobi_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-200-0002', 'Nairobi Hockey Field A'),
('Ali', 'Hassan', 'ali.hassan@mombasa_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-200-0001', 'Mombasa Hockey Field'),
('Mercy', 'Achieng', 'mercy.achieng@mombasa_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-200-0002', 'Mombasa Hockey Field'),
('George', 'Ouma', 'george.ouma@kisumu_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-200-0001', 'Kisumu Hockey Field'),
('Sarah', 'Anyango', 'sarah.anyango@kisumu_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-200-0002', 'Kisumu Hockey Field'),
('Brian', 'Kirui', 'brian.kirui@nakuru_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-300-0001', 'Nakuru Hockey Field'),
('Janet', 'Chebet', 'janet.chebet@nakuru_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-300-0002', 'Nakuru Hockey Field'),
('Peter', 'Kipngetich', 'peter.kipngetich@eldoret_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-300-0001', 'Eldoret Hockey Field'),
('Diane', 'Kiptoo', 'diane.kiptoo@eldoret_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-300-0002', 'Eldoret Hockey Field'),
('Samuel', 'Njoroge', 'samuel.njoroge@thika_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-300-0001', 'Thika Hockey Field'),
('Caroline', 'Wanjiru', 'caroline.wanjiru@thika_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-300-0002', 'Thika Hockey Field'),
('Daniel', 'Muthoni', 'daniel.muthoni@machakos_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-300-0001', 'Machakos Hockey Field'),
('Grace', 'Nderitu', 'grace.nderitu@machakos_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-300-0002', 'Machakos Hockey Field'),
('Michael', 'Langat', 'michael.langat@meru_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-300-0001', 'Meru Hockey Field'),
('Leah', 'Chebet', 'leah.chebet@meru_hockey_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-300-0002', 'Meru Hockey Field'),
('Beatrice', 'Wanjiru', 'beatrice.wanjiru@nairobi_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-300-0001', 'Nairobi Indoor Hall A'),
('Kelvin', 'Mburu', 'kelvin.mburu@nairobi_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-300-0002', 'Nairobi Indoor Hall A'),
('Aisha', 'Hassan', 'aisha.hassan@mombasa_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-300-0001', 'Mombasa Indoor Hall'),
('David', 'Njuguna', 'david.njuguna@mombasa_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-300-0002', 'Mombasa Indoor Hall'),
('Joyce', 'Achieng', 'joyce.achieng@kisumu_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-300-0001', 'Kisumu Indoor Hall'),
('Peter', 'Onchari', 'peter.onchari@kisumu_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-300-0002', 'Kisumu Indoor Hall'),
('Ruth', 'Kiptanui', 'ruth.kiptanui@nakuru_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-300-0001', 'Nakuru Indoor Hall'),
('Brian', 'Kiprono', 'brian.kiprono@nakuru_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-300-0002', 'Nakuru Indoor Hall'),
('Moses', 'Kiprotich', 'moses.kiprotich@eldoret_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-300-0001', 'Eldoret Indoor Hall'),
('Patricia', 'Kiptum', 'patricia.kiptum@eldoret_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-300-0002', 'Eldoret Indoor Hall'),
('Esther', 'Nyambura', 'esther.nyambura@thika_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-400-0001', 'Thika Indoor Hall'),
('Kevin', 'Ngugi', 'kevin.ngugi@thika_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-400-0002', 'Thika Indoor Hall'),
('Lillian', 'Mutua', 'lillian.mutua@machakos_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-400-0001', 'Machakos Indoor Hall'),
('Paul', 'Mwangi', 'paul.mwangi@machakos_volleyball_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-400-0002', 'Machakos Indoor Hall'),
('Kevin', 'Otieno', 'kevin.otieno@nairobi_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-400-0001', 'Chess Room 105'),
('Susan', 'Anyango', 'susan.anyango@nairobi_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-400-0002', 'Chess Room 105'),
('Zainab', 'Yusuf', 'zainab.yusuf@mombasa_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-400-0001', 'Coast Chess Room'),
('Fredrick', 'Okumu', 'fredrick.okumu@mombasa_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-400-0002', 'Coast Chess Room'),
('Charles', 'Ouma', 'charles.ouma@kisumu_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-400-0001', 'Lakeside Chess Room'),
('Jane', 'Adhiambo', 'jane.adhiambo@kisumu_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-400-0002', 'Lakeside Chess Room'),
('Peter', 'Kinyua', 'peter.kinyua@nakuru_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-400-0001', 'Valley Chess Room'),
('Alice', 'Chebet', 'alice.chebet@nakuru_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-400-0002', 'Valley Chess Room'),
('Henry', 'Kipchumba', 'henry.kipchumba@eldoret_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-400-0001', 'Highlands Chess Room'),
('Emily', 'Chebet', 'emily.chebet@eldoret_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-400-0002', 'Highlands Chess Room'),
('Brian', 'Kamau', 'brian.kamau@thika_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-400-0001', 'Thika Chess Room'),
('Mercy', 'Wanjiru', 'mercy.wanjiru@thika_chess_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-400-0002', 'Thika Chess Room'),
('James', 'Kiprono', 'james.kiprono@nairobi_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-400-0001', 'Nairobi Equestrian Center'),
('Faith', 'Wambui', 'faith.wambui@nairobi_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-400-0002', 'Nairobi Equestrian Center'),
('Ahmed', 'Omar', 'ahmed.omar@mombasa_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-400-0001', 'Mombasa Equestrian Arena'),
('Mary', 'Abdalla', 'mary.abdalla@mombasa_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-400-0002', 'Mombasa Equestrian Arena'),
('Paul', 'Odhiambo', 'paul.odhiambo@kisumu_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-500-0001', 'Kisumu Riding Arena'),
('Grace', 'Anyango', 'grace.anyango@kisumu_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-500-0002', 'Kisumu Riding Arena'),
('Peter', 'Kirui', 'peter.kirui@nakuru_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-500-0001', 'Nakuru Riding Arena'),
('Joanne', 'Chebet', 'joanne.chebet@nakuru_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-500-0002', 'Nakuru Riding Arena'),
('Samuel', 'Kiplagat', 'samuel.kiplagat@eldoret_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-500-0001', 'Eldoret Riding Arena'),
('Lucy', 'Jepkemei', 'lucy.jepkemei@eldoret_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '072-500-0002', 'Eldoret Riding Arena'),
('David', 'Musyoka', 'david.musyoka@thika_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-500-0001', 'Thika Riding Arena'),
('Esther', 'Njeri', 'esther.njeri@thika_riding_team.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '073-500-0002', 'Thika Riding Arena'),
('Thomas', 'Otieno', 'thomas.otieno@nairobi_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-500-0001', 'Nairobi Badminton Hall B'),
('Alice', 'Wanjiru', 'alice.wanjiru@nairobi_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '074-500-0002', 'Nairobi Badminton Hall B'),
('Hassan', 'Ali', 'hassan.ali@mombasa_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-500-0001', 'Mombasa Badminton Hall'),
('Grace', 'Hassan', 'grace.hassan@mombasa_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '075-500-0002', 'Mombasa Badminton Hall'),
('Simon', 'Ouma', 'simon.ouma@kisumu_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-500-0001', 'Kisumu Badminton Hall'),
('Jane', 'Achieng', 'jane.achieng@kisumu_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '076-500-0002', 'Kisumu Badminton Hall'),
('Daniel', 'Kipkoech', 'daniel.kipkoech@nakuru_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-500-0001', 'Nakuru Badminton Hall'),
('Faith', 'Chebet', 'faith.chebet@nakuru_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '077-500-0002', 'Nakuru Badminton Hall'),
('Michael', 'Langat', 'michael.langat@eldoret_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-500-0001', 'Eldoret Badminton Hall'),
('Mercy', 'Korir', 'mercy.korir@eldoret_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '078-500-0002', 'Eldoret Badminton Hall'),
('Patrick', 'Kimani', 'patrick.kimani@thika_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-500-0001', 'Thika Badminton Hall'),
('Ruth', 'Kiptum', 'ruth.kiptum@thika_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '079-500-0002', 'Thika Badminton Hall'),
('Kevin', 'Mutua', 'kevin.mutua@machakos_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-600-0001', 'Machakos Badminton Hall'),
('Joyce', 'Wanjiru', 'joyce.wanjiru@machakos_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '070-600-0002', 'Machakos Badminton Hall'),
('Joseph', 'Kiplagat', 'joseph.kiplagat@meru_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-600-0001', 'Meru Badminton Hall'),
('Sarah', 'Korir', 'sarah.korir@meru_badminton_club.apexsportsclub.local', '$2y$10$abcdefghijklmnopqrstuvwxyza1234567890123456789012345678901234567890', '071-600-0002', 'Meru Badminton Hall')
ON DUPLICATE KEY UPDATE `email` = `email`;

INSERT IGNORE INTO `team_memberships` (`league_id`, `team_id`, `member_id`, `role`, `status`)
SELECT t.`league_id`, t.`team_id`, m.`member_id`, 'Player', 'Active'
FROM `teams` t
JOIN (
    SELECT 'Nairobi United FC' AS team_name, 'brian.otieno@nairobi_united_fc.apexsportsclub.local' AS email UNION ALL
    SELECT 'Nairobi United FC', 'amina.wanjiru@nairobi_united_fc.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa City FC', 'yusuf.ali@mombasa_city_fc.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa City FC', 'mary.njeri@mombasa_city_fc.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Stars FC', 'peter.ouma@kisumu_stars_fc.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Stars FC', 'faith.achieng@kisumu_stars_fc.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Athletic FC', 'david.kiptoo@nakuru_athletic_fc.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Athletic FC', 'grace.chebet@nakuru_athletic_fc.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Rangers FC', 'paul.kamau@eldoret_rangers_fc.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Rangers FC', 'esther.njeri@eldoret_rangers_fc.apexsportsclub.local' UNION ALL
    SELECT 'Thika Rovers FC', 'michael.mwangi@thika_rovers_fc.apexsportsclub.local' UNION ALL
    SELECT 'Thika Rovers FC', 'carol.wanjiru@thika_rovers_fc.apexsportsclub.local' UNION ALL
    SELECT 'Machakos Royals FC', 'kevin.mutua@machakos_royals_fc.apexsportsclub.local' UNION ALL
    SELECT 'Machakos Royals FC', 'ruth.nyambura@machakos_royals_fc.apexsportsclub.local' UNION ALL
    SELECT 'Meru County FC', 'dennis.kirui@meru_county_fc.apexsportsclub.local' UNION ALL
    SELECT 'Meru County FC', 'lydia.chebet@meru_county_fc.apexsportsclub.local' UNION ALL
    SELECT 'Kitale Warriors FC', 'eric.kipkoech@kitale_warriors_fc.apexsportsclub.local' UNION ALL
    SELECT 'Kitale Warriors FC', 'susan.achieng@kitale_warriors_fc.apexsportsclub.local' UNION ALL
    SELECT 'Nyeri Highlanders FC', 'daniel.njoroge@nyeri_highlanders_fc.apexsportsclub.local' UNION ALL
    SELECT 'Nyeri Highlanders FC', 'patricia.wambui@nyeri_highlanders_fc.apexsportsclub.local' UNION ALL
    SELECT 'Kericho Green FC', 'simon.bett@kericho_green_fc.apexsportsclub.local' UNION ALL
    SELECT 'Kericho Green FC', 'nancy.chebet@kericho_green_fc.apexsportsclub.local' UNION ALL
    SELECT 'Naivasha United FC', 'patrick.karanja@naivasha_united_fc.apexsportsclub.local' UNION ALL
    SELECT 'Naivasha United FC', 'alice.wanjiru@naivasha_united_fc.apexsportsclub.local' UNION ALL
    SELECT 'Kakamega Town FC', 'charles.odhiambo@kakamega_town_fc.apexsportsclub.local' UNION ALL
    SELECT 'Kakamega Town FC', 'jane.auma@kakamega_town_fc.apexsportsclub.local' UNION ALL
    SELECT 'Malindi Coast FC', 'hassan.juma@malindi_coast_fc.apexsportsclub.local' UNION ALL
    SELECT 'Malindi Coast FC', 'asha.mwende@malindi_coast_fc.apexsportsclub.local' UNION ALL
    SELECT 'Garissa Plains FC', 'jamal.yusuf@garissa_plains_fc.apexsportsclub.local' UNION ALL
    SELECT 'Garissa Plains FC', 'hawa.aden@garissa_plains_fc.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi RFC', 'evan.kipchumba@nairobi_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi RFC', 'lilian.kinyua@nairobi_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa RFC', 'joseph.mwangi@mombasa_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa RFC', 'aisha.abdalla@mombasa_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu RFC', 'omondi.awuor@kisumu_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu RFC', 'jane.khamala@kisumu_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru RFC', 'fredrick.kiprono@nakuru_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru RFC', 'sarah.chebet@nakuru_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret RFC', 'martin.cheruiyot@eldoret_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret RFC', 'carol.jepkosgei@eldoret_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Thika RFC', 'samson.musyoka@thika_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Thika RFC', 'esther.kimani@thika_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Machakos RFC', 'victor.mutua@machakos_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Machakos RFC', 'mary.wambui@machakos_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Meru RFC', 'nelson.langat@meru_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Meru RFC', 'nancy.kigen@meru_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Kitale RFC', 'silas.kiptoo@kitale_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Kitale RFC', 'beatrice.chebet@kitale_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Nyeri RFC', 'christopher.bett@nyeri_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Nyeri RFC', 'judith.korir@nyeri_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Naivasha RFC', 'felix.njenga@naivasha_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Naivasha RFC', 'rose.wanjiru@naivasha_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Kakamega RFC', 'isaac.odhiambo@kakamega_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Kakamega RFC', 'prisca.achieng@kakamega_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Malindi RFC', 'hussein.baraza@malindi_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Malindi RFC', 'halima.juma@malindi_rfc.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Hockey Club', 'julia.mwende@nairobi_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Hockey Club', 'andrew.karanja@nairobi_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Hockey Club', 'ali.hassan@mombasa_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Hockey Club', 'mercy.achieng@mombasa_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Hockey Club', 'george.ouma@kisumu_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Hockey Club', 'sarah.anyango@kisumu_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Hockey Club', 'brian.kirui@nakuru_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Hockey Club', 'janet.chebet@nakuru_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Hockey Club', 'peter.kipngetich@eldoret_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Hockey Club', 'diane.kiptoo@eldoret_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Thika Hockey Club', 'samuel.njoroge@thika_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Thika Hockey Club', 'caroline.wanjiru@thika_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Machakos Hockey Club', 'daniel.muthoni@machakos_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Machakos Hockey Club', 'grace.nderitu@machakos_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Meru Hockey Club', 'michael.langat@meru_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Meru Hockey Club', 'leah.chebet@meru_hockey_club.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Volleyball Club', 'beatrice.wanjiru@nairobi_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Volleyball Club', 'kelvin.mburu@nairobi_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Volleyball Club', 'aisha.hassan@mombasa_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Volleyball Club', 'david.njuguna@mombasa_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Volleyball Club', 'joyce.achieng@kisumu_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Volleyball Club', 'peter.onchari@kisumu_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Volleyball Club', 'ruth.kiptanui@nakuru_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Volleyball Club', 'brian.kiprono@nakuru_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Volleyball Club', 'moses.kiprotich@eldoret_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Volleyball Club', 'patricia.kiptum@eldoret_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Thika Volleyball Club', 'esther.nyambura@thika_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Thika Volleyball Club', 'kevin.ngugi@thika_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Machakos Volleyball Club', 'lillian.mutua@machakos_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Machakos Volleyball Club', 'paul.mwangi@machakos_volleyball_club.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Chess Club', 'kevin.otieno@nairobi_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Chess Club', 'susan.anyango@nairobi_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Chess Club', 'zainab.yusuf@mombasa_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Chess Club', 'fredrick.okumu@mombasa_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Chess Club', 'charles.ouma@kisumu_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Chess Club', 'jane.adhiambo@kisumu_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Chess Club', 'peter.kinyua@nakuru_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Chess Club', 'alice.chebet@nakuru_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Chess Club', 'henry.kipchumba@eldoret_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Chess Club', 'emily.chebet@eldoret_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Thika Chess Club', 'brian.kamau@thika_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Thika Chess Club', 'mercy.wanjiru@thika_chess_club.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Riding Team', 'james.kiprono@nairobi_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Riding Team', 'faith.wambui@nairobi_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Riding Team', 'ahmed.omar@mombasa_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Riding Team', 'mary.abdalla@mombasa_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Riding Team', 'paul.odhiambo@kisumu_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Riding Team', 'grace.anyango@kisumu_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Riding Team', 'peter.kirui@nakuru_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Riding Team', 'joanne.chebet@nakuru_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Riding Team', 'samuel.kiplagat@eldoret_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Riding Team', 'lucy.jepkemei@eldoret_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Thika Riding Team', 'david.musyoka@thika_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Thika Riding Team', 'esther.njeri@thika_riding_team.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Badminton Club', 'thomas.otieno@nairobi_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Nairobi Badminton Club', 'alice.wanjiru@nairobi_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Badminton Club', 'hassan.ali@mombasa_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Mombasa Badminton Club', 'grace.hassan@mombasa_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Badminton Club', 'simon.ouma@kisumu_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Kisumu Badminton Club', 'jane.achieng@kisumu_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Badminton Club', 'daniel.kipkoech@nakuru_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Nakuru Badminton Club', 'faith.chebet@nakuru_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Badminton Club', 'michael.langat@eldoret_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Eldoret Badminton Club', 'mercy.korir@eldoret_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Thika Badminton Club', 'patrick.kimani@thika_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Thika Badminton Club', 'ruth.kiptum@thika_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Machakos Badminton Club', 'kevin.mutua@machakos_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Machakos Badminton Club', 'joyce.wanjiru@machakos_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Meru Badminton Club', 'joseph.kiplagat@meru_badminton_club.apexsportsclub.local' UNION ALL
    SELECT 'Meru Badminton Club', 'sarah.korir@meru_badminton_club.apexsportsclub.local'
) roster ON roster.team_name = t.`name`
JOIN `members` m ON m.`email` = roster.email;
