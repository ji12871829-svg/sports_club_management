<?php

require_once __DIR__ . '/feature_helpers.php';

function asc_motm_ready(mysqli $conn): bool
{
    return db_table_exists($conn, 'motm_votes');
}

function asc_cast_motm_vote(
    mysqli $conn,
    int $fixture_id,
    int $voter_member_id,
    int $team_id,
    string $player_name,
    ?int $member_id = null
): array {
    if (!asc_motm_ready($conn)) {
        return ['ok' => false, 'error' => 'Voting is not available yet.'];
    }

    $player_name = trim($player_name);
    if ($player_name === '') {
        return ['ok' => false, 'error' => 'Please enter a player name.'];
    }

    $stmt = $conn->prepare(
        'INSERT INTO motm_votes (fixture_id, voter_member_id, team_id, player_name, member_id)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), player_name = VALUES(player_name), member_id = VALUES(member_id)'
    );
    $stmt->bind_param('iiisi', $fixture_id, $voter_member_id, $team_id, $player_name, $member_id);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok
        ? ['ok' => true, 'error' => '']
        : ['ok' => false, 'error' => 'Could not save your vote.'];
}

function asc_motm_results(mysqli $conn, int $fixture_id): array
{
    if (!asc_motm_ready($conn)) {
        return [];
    }

    $stmt = $conn->prepare(
        "SELECT mv.team_id, t.name AS team_name, mv.player_name,
                COUNT(*) AS votes
         FROM motm_votes mv
         JOIN teams t ON t.team_id = mv.team_id
         WHERE mv.fixture_id = ?
         GROUP BY mv.team_id, t.name, mv.player_name
         ORDER BY votes DESC, mv.player_name ASC"
    );
    $stmt->bind_param('i', $fixture_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function asc_member_motm_vote(mysqli $conn, int $fixture_id, int $member_id): ?array
{
    if (!asc_motm_ready($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT team_id, player_name, member_id FROM motm_votes WHERE fixture_id = ? AND voter_member_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $fixture_id, $member_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}
