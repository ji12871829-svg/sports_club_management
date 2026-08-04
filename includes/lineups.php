<?php

declare(strict_types=1);

function asc_fetch_recent_fixtures_for_lineups(mysqli $conn, int $limit = 80): array
{
    $stmt = $conn->prepare(
        "SELECT f.fixture_id, f.match_date, f.match_time, f.status,
                h.team_id AS home_team_id, h.name AS home_team,
                a.team_id AS away_team_id, a.name AS away_team,
                l.name AS league_name
         FROM fixtures f
         JOIN teams h ON h.team_id = f.home_team_id
         JOIN teams a ON a.team_id = f.away_team_id
         JOIN leagues l ON l.league_id = f.league_id
         ORDER BY f.match_date DESC, f.fixture_id DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function asc_fetch_team_members_for_lineup(mysqli $conn, int $teamId): array
{
    $stmt = $conn->prepare(
        "SELECT m.member_id, m.first_name, m.last_name, tm.role
         FROM team_memberships tm
         JOIN members m ON m.member_id = tm.member_id
         WHERE tm.team_id = ? AND tm.status = 'Active'
         ORDER BY tm.role DESC, m.first_name, m.last_name"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $teamId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * @return array{success: bool, message: string}
 */
function asc_save_fixture_lineup(
    mysqli $conn,
    int $fixtureId,
    int $teamId,
    string $formation,
    array $starterMemberIds,
    array $substituteMemberIds,
    int $adminId
): array {
    $starterMemberIds = array_values(array_unique(array_filter(array_map('intval', $starterMemberIds))));
    $substituteMemberIds = array_values(array_unique(array_filter(array_map('intval', $substituteMemberIds))));

    if (count($starterMemberIds) === 0) {
        return ['success' => false, 'message' => 'Select at least one starting player.'];
    }
    if (count($starterMemberIds) > 11) {
        return ['success' => false, 'message' => 'Starting XI cannot exceed 11 players.'];
    }
    if (count($substituteMemberIds) > 12) {
        return ['success' => false, 'message' => 'Substitutes cannot exceed 12 players.'];
    }
    if (count(array_intersect($starterMemberIds, $substituteMemberIds)) > 0) {
        return ['success' => false, 'message' => 'A player cannot be both starter and substitute.'];
    }

    $allowed = [];
    foreach (asc_fetch_team_members_for_lineup($conn, $teamId) as $member) {
        $allowed[(int) $member['member_id']] = true;
    }
    foreach (array_merge($starterMemberIds, $substituteMemberIds) as $memberId) {
        if (!isset($allowed[$memberId])) {
            return ['success' => false, 'message' => 'One or more selected players are not active on this team.'];
        }
    }

    $conn->begin_transaction();
    try {
        $lineupId = null;
        $stmt = $conn->prepare("SELECT lineup_id FROM fixture_lineups WHERE fixture_id = ? AND team_id = ? LIMIT 1");
        $stmt->bind_param('ii', $fixtureId, $teamId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $lineupId = (int) $row['lineup_id'];
            $stmt = $conn->prepare(
                "UPDATE fixture_lineups
                 SET formation = ?, published_by = ?, is_published = 1, published_at = NOW()
                 WHERE lineup_id = ?"
            );
            $stmt->bind_param('sii', $formation, $adminId, $lineupId);
            $stmt->execute();
            $stmt->close();
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO fixture_lineups (fixture_id, team_id, formation, published_by, is_published, published_at)
                 VALUES (?, ?, ?, ?, 1, NOW())"
            );
            $stmt->bind_param('iisi', $fixtureId, $teamId, $formation, $adminId);
            $stmt->execute();
            $lineupId = (int) $stmt->insert_id;
            $stmt->close();
        }

        $stmt = $conn->prepare("DELETE FROM fixture_lineup_players WHERE lineup_id = ?");
        $stmt->bind_param('i', $lineupId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO fixture_lineup_players (lineup_id, member_id, slot_type, sort_order)
             VALUES (?, ?, ?, ?)"
        );
        $sort = 1;
        foreach ($starterMemberIds as $memberId) {
            $slot = 'starter';
            $stmt->bind_param('iisi', $lineupId, $memberId, $slot, $sort);
            $stmt->execute();
            $sort++;
        }
        $sort = 1;
        foreach ($substituteMemberIds as $memberId) {
            $slot = 'substitute';
            $stmt->bind_param('iisi', $lineupId, $memberId, $slot, $sort);
            $stmt->execute();
            $sort++;
        }
        $stmt->close();

        $conn->commit();
        return ['success' => true, 'message' => 'Lineup saved and published.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function asc_fetch_fixture_lineups(mysqli $conn, int $fixtureId): array
{
    $stmt = $conn->prepare(
        "SELECT fl.lineup_id, fl.fixture_id, fl.team_id, fl.formation, fl.is_published,
                t.name AS team_name
         FROM fixture_lineups fl
         JOIN teams t ON t.team_id = fl.team_id
         WHERE fl.fixture_id = ?
         ORDER BY fl.lineup_id ASC"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $fixtureId);
    $stmt->execute();
    $lineups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    if (!$lineups) {
        return [];
    }

    $stmtPlayers = $conn->prepare(
        "SELECT lp.lineup_id, lp.member_id, lp.slot_type, lp.sort_order,
                m.first_name, m.last_name
         FROM fixture_lineup_players lp
         JOIN members m ON m.member_id = lp.member_id
         WHERE lp.lineup_id = ?
         ORDER BY lp.slot_type ASC, lp.sort_order ASC, lp.lineup_player_id ASC"
    );
    if (!$stmtPlayers) {
        return $lineups;
    }

    foreach ($lineups as &$lineup) {
        $lineup['starters'] = [];
        $lineup['substitutes'] = [];
        $lineupId = (int) $lineup['lineup_id'];
        $stmtPlayers->bind_param('i', $lineupId);
        $stmtPlayers->execute();
        $players = $stmtPlayers->get_result()->fetch_all(MYSQLI_ASSOC);
        foreach ($players as $player) {
            $name = trim(((string) $player['first_name']) . ' ' . ((string) $player['last_name']));
            if ($player['slot_type'] === 'starter') {
                $lineup['starters'][] = $name;
            } else {
                $lineup['substitutes'][] = $name;
            }
        }
    }
    unset($lineup);
    $stmtPlayers->close();

    return $lineups;
}
