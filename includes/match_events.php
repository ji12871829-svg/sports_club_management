<?php

require_once __DIR__ . '/feature_helpers.php';

function asc_match_events_ready(mysqli $conn): bool
{
    return db_table_exists($conn, 'match_events');
}

function asc_record_match_event(
    mysqli $conn,
    int $fixture_id,
    int $team_id,
    string $event_type,
    ?string $player_name = null,
    ?int $member_id = null,
    ?int $minute = null,
    string $recorded_by = 'admin',
    ?string $notes = null
): bool {
    if (!asc_match_events_ready($conn)) {
        return false;
    }

    $allowed = ['goal', 'own_goal', 'penalty', 'yellow_card', 'red_card'];
    if (!in_array($event_type, $allowed, true)) {
        return false;
    }

    $player_name = $player_name !== null ? trim($player_name) : null;
    if ($player_name === '') {
        $player_name = null;
    }

    $stmt = $conn->prepare(
        'INSERT INTO match_events (fixture_id, team_id, event_type, player_name, member_id, minute, notes, recorded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iissiiss', $fixture_id, $team_id, $event_type, $player_name, $member_id, $minute, $notes, $recorded_by);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

function asc_remove_last_match_event(mysqli $conn, int $fixture_id, int $team_id, array $event_types): bool
{
    if (!asc_match_events_ready($conn) || $event_types === []) {
        return false;
    }

    $placeholders = implode(',', array_fill(0, count($event_types), '?'));
    $sql = "SELECT event_id FROM match_events
            WHERE fixture_id = ? AND team_id = ? AND event_type IN ($placeholders)
            ORDER BY event_id DESC LIMIT 1";

    $stmt = $conn->prepare($sql);
    $bind_types = 'ii' . str_repeat('s', count($event_types));
    $bind_params = [$fixture_id, $team_id];
    foreach ($event_types as $t) {
        $bind_params[] = $t;
    }

    $refs = [$bind_types];
    foreach ($bind_params as $k => $v) {
        $refs[] = &$bind_params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $refs);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return false;
    }

    $event_id = (int) $row['event_id'];
    $del = $conn->prepare('DELETE FROM match_events WHERE event_id = ?');
    $del->bind_param('i', $event_id);
    $ok = $del->execute();
    $del->close();

    return $ok;
}

function asc_fixture_events(mysqli $conn, int $fixture_id): array
{
    if (!asc_match_events_ready($conn)) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT me.*, t.name AS team_name
         FROM match_events me
         JOIN teams t ON t.team_id = me.team_id
         WHERE me.fixture_id = ?
         ORDER BY COALESCE(me.minute, 999), me.event_id"
    );
    $stmt->bind_param('i', $fixture_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function asc_top_scorers(mysqli $conn, ?int $league_id = null, int $limit = 20): array
{
    if (!asc_match_events_ready($conn)) {
        return [];
    }

    $sql = "SELECT me.player_name, me.team_id, t.name AS team_name, l.league_id, l.name AS league_name,
                   SUM(CASE WHEN me.event_type IN ('goal','penalty') THEN 1 ELSE 0 END) AS goals,
                   SUM(CASE WHEN me.event_type = 'own_goal' THEN 1 ELSE 0 END) AS own_goals
            FROM match_events me
            JOIN fixtures f ON f.fixture_id = me.fixture_id
            JOIN teams t ON t.team_id = me.team_id
            JOIN leagues l ON l.league_id = f.league_id
            WHERE me.player_name IS NOT NULL AND me.player_name != ''
              AND me.event_type IN ('goal','penalty')";

    if ($league_id !== null && $league_id > 0) {
        $sql .= ' AND f.league_id = ' . (int) $league_id;
    }

    $sql .= ' GROUP BY me.player_name, me.team_id, t.name, l.league_id, l.name
              HAVING goals > 0
              ORDER BY goals DESC, player_name ASC
              LIMIT ' . (int) $limit;

    $result = $conn->query($sql);

    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function asc_card_summary_by_fixture(mysqli $conn, int $fixture_id): array
{
    $events = asc_fixture_events($conn, $fixture_id);
    $summary = ['yellow' => [], 'red' => []];

    foreach ($events as $e) {
        if ($e['event_type'] === 'yellow_card') {
            $summary['yellow'][] = $e;
        } elseif ($e['event_type'] === 'red_card') {
            $summary['red'][] = $e;
        }
    }

    return $summary;
}

function asc_sync_goal_from_referee(
    mysqli $conn,
    int $fixture_id,
    int $home_team_id,
    int $away_team_id,
    string $action,
    ?string $player_name,
    ?int $minute
): void {
    if (!asc_match_events_ready($conn)) {
        return;
    }

    $goal_types = ['goal', 'penalty'];

    switch ($action) {
        case 'goal_home':
            asc_record_match_event($conn, $fixture_id, $home_team_id, 'goal', $player_name, null, $minute, 'referee');
            break;
        case 'goal_away':
            asc_record_match_event($conn, $fixture_id, $away_team_id, 'goal', $player_name, null, $minute, 'referee');
            break;
        case 'undo_home':
            asc_remove_last_match_event($conn, $fixture_id, $home_team_id, $goal_types);
            break;
        case 'undo_away':
            asc_remove_last_match_event($conn, $fixture_id, $away_team_id, $goal_types);
            break;
    }
}
