-- Migration 035: Repair seeded team memberships for full roster members.

DELETE tm
FROM team_memberships tm
JOIN members m ON tm.member_id = m.member_id
WHERE m.email LIKE '%@%.apexsportsclub.local';

INSERT IGNORE INTO team_memberships (`league_id`, `team_id`, `member_id`, `role`, `status`)
SELECT t.league_id, t.team_id, m.member_id, 'Player', 'Active'
FROM teams t
JOIN members m ON m.email LIKE CONCAT('%@', LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(t.name, ' ', '_'), '&', 'and'), '-', '_'), '.', ''), '''', '')), '.apexsportsclub.local')
WHERE m.email LIKE '%@%.apexsportsclub.local';
