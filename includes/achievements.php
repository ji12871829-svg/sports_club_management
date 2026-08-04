<?php

declare(strict_types=1);

function asc_award_achievement(mysqli $conn, int $memberId, string $code, ?array $context = null): bool
{
    $stmt = $conn->prepare("SELECT achievement_id FROM achievements WHERE code = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return false;
    }

    $achievementId = (int) $row['achievement_id'];
    $contextJson = $context ? json_encode($context) : null;
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO member_achievements (member_id, achievement_id, context_json)
         VALUES (?, ?, ?)"
    );
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('iis', $memberId, $achievementId, $contextJson);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();
    return $inserted;
}

function asc_member_achievements(mysqli $conn, int $memberId): array
{
    $stmt = $conn->prepare(
        "SELECT a.code, a.name, a.icon, a.description, ma.awarded_at
         FROM member_achievements ma
         JOIN achievements a ON a.achievement_id = ma.achievement_id
         WHERE ma.member_id = ?
         ORDER BY ma.awarded_at DESC"
    );
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $memberId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * @return array{awarded:int}
 */
function asc_recompute_achievements(mysqli $conn): array
{
    $awarded = 0;

    // First Goal + Hat-trick from match events.
    $res = $conn->query(
        "SELECT member_id, fixture_id,
                SUM(CASE WHEN event_type IN ('goal','penalty') THEN 1 ELSE 0 END) AS goals
         FROM match_events
         WHERE member_id IS NOT NULL
         GROUP BY member_id, fixture_id"
    );
    $firstGoalMembers = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $memberId = (int) $row['member_id'];
            $goals = (int) $row['goals'];
            if ($goals > 0) {
                $firstGoalMembers[$memberId] = true;
            }
            if ($goals >= 3) {
                if (asc_award_achievement($conn, $memberId, 'hat_trick', ['fixture_id' => (int) $row['fixture_id']])) {
                    $awarded++;
                }
            }
        }
        $res->free();
    }
    foreach (array_keys($firstGoalMembers) as $memberId) {
        if (asc_award_achievement($conn, (int) $memberId, 'first_goal')) {
            $awarded++;
        }
    }

    // One year member.
    $res = $conn->query("SELECT member_id FROM members WHERE DATE(date_joined) <= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (asc_award_achievement($conn, (int) $row['member_id'], 'one_year_member')) {
                $awarded++;
            }
        }
        $res->free();
    }

    // 10 appearances from published lineups on completed fixtures.
    $res = $conn->query(
        "SELECT lp.member_id, COUNT(DISTINCT fl.fixture_id) AS apps
         FROM fixture_lineup_players lp
         JOIN fixture_lineups fl ON fl.lineup_id = lp.lineup_id
         JOIN fixtures f ON f.fixture_id = fl.fixture_id
         WHERE f.status = 'Completed'
         GROUP BY lp.member_id
         HAVING apps >= 10"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (asc_award_achievement($conn, (int) $row['member_id'], 'ten_appearances')) {
                $awarded++;
            }
        }
        $res->free();
    }

    // Clean sheet for starters in completed fixtures where opponent scored zero.
    $res = $conn->query(
        "SELECT DISTINCT lp.member_id, f.fixture_id
         FROM fixture_lineup_players lp
         JOIN fixture_lineups fl ON fl.lineup_id = lp.lineup_id
         JOIN fixtures f ON f.fixture_id = fl.fixture_id
         WHERE lp.slot_type = 'starter'
           AND f.status = 'Completed'
           AND (
                (fl.team_id = f.home_team_id AND COALESCE(f.away_score, 0) = 0) OR
                (fl.team_id = f.away_team_id AND COALESCE(f.home_score, 0) = 0)
           )"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if (asc_award_achievement($conn, (int) $row['member_id'], 'clean_sheet', ['fixture_id' => (int) $row['fixture_id']])) {
                $awarded++;
            }
        }
        $res->free();
    }

    return ['awarded' => $awarded];
}
