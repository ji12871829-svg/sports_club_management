<?php

function asc_head_to_head(mysqli $conn, int $team_a_id, int $team_b_id, ?int $league_id = null): array
{
    $sql = "SELECT f.fixture_id, f.match_date, f.status, f.home_score, f.away_score,
                   f.home_team_id, f.away_team_id,
                   h.name AS home_team, a.name AS away_team
            FROM fixtures f
            JOIN teams h ON h.team_id = f.home_team_id
            JOIN teams a ON a.team_id = f.away_team_id
            WHERE f.status = 'Completed'
              AND f.home_score IS NOT NULL AND f.away_score IS NOT NULL
              AND (
                    (f.home_team_id = ? AND f.away_team_id = ?)
                 OR (f.home_team_id = ? AND f.away_team_id = ?)
              )";

    if ($league_id !== null && $league_id > 0) {
        $sql .= ' AND f.league_id = ' . (int) $league_id;
    }

    $sql .= ' ORDER BY f.match_date DESC';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('iiii', $team_a_id, $team_b_id, $team_b_id, $team_a_id);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stats = [
        'played' => count($matches),
        'team_a_wins' => 0,
        'team_b_wins' => 0,
        'draws' => 0,
        'team_a_goals' => 0,
        'team_b_goals' => 0,
    ];

    foreach ($matches as $m) {
        $home_id = (int) $m['home_team_id'];
        $hs = (int) $m['home_score'];
        $as = (int) $m['away_score'];

        $a_is_home = $home_id === $team_a_id;
        $goals_a = $a_is_home ? $hs : $as;
        $goals_b = $a_is_home ? $as : $hs;

        $stats['team_a_goals'] += $goals_a;
        $stats['team_b_goals'] += $goals_b;

        if ($goals_a > $goals_b) {
            $stats['team_a_wins']++;
        } elseif ($goals_b > $goals_a) {
            $stats['team_b_wins']++;
        } else {
            $stats['draws']++;
        }
    }

    return ['matches' => $matches, 'stats' => $stats];
}

function asc_get_fixture_detail(mysqli $conn, int $fixture_id): ?array
{
    $stmt = $conn->prepare(
        "SELECT f.*,
                h.team_id AS home_team_id, h.name AS home_team,
                a.team_id AS away_team_id, a.name AS away_team,
                l.league_id, l.name AS league_name, l.season,
                s.name AS sport_name
         FROM fixtures f
         JOIN teams h ON h.team_id = f.home_team_id
         JOIN teams a ON a.team_id = f.away_team_id
         JOIN leagues l ON l.league_id = f.league_id
         JOIN sports s ON s.sport_id = l.sport_id
         WHERE f.fixture_id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $fixture_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}
