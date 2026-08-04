<?php
/**
 * League standings recalculation (from completed fixtures).
 */

function recalculate_standings(mysqli $conn, int $league_id): void
{
    $stmt_reset = $conn->prepare(
        "UPDATE standings SET played=0,won=0,drawn=0,lost=0,goals_for=0,goals_against=0,goal_diff=0,points=0 WHERE league_id=?"
    );
    $stmt_reset->bind_param('i', $league_id);
    $stmt_reset->execute();
    $stmt_reset->close();

    $stmt_ensure = $conn->prepare(
        'INSERT IGNORE INTO standings (league_id, team_id) SELECT league_id, team_id FROM teams WHERE league_id=?'
    );
    $stmt_ensure->bind_param('i', $league_id);
    $stmt_ensure->execute();
    $stmt_ensure->close();

    $stmt_fetch = $conn->prepare(
        "SELECT home_team_id, away_team_id, home_score, away_score
         FROM fixtures
         WHERE league_id=? AND status='Completed' AND home_score IS NOT NULL AND away_score IS NOT NULL"
    );
    $stmt_fetch->bind_param('i', $league_id);
    $stmt_fetch->execute();
    $result = $stmt_fetch->get_result();

    while ($f = $result->fetch_assoc()) {
        $h  = (int) $f['home_team_id'];
        $a  = (int) $f['away_team_id'];
        $hs = (int) $f['home_score'];
        $as = (int) $f['away_score'];

        if ($hs > $as) {
            update_standing($conn, $league_id, $h, $hs, $as, 'W');
            update_standing($conn, $league_id, $a, $as, $hs, 'L');
        } elseif ($as > $hs) {
            update_standing($conn, $league_id, $h, $hs, $as, 'L');
            update_standing($conn, $league_id, $a, $as, $hs, 'W');
        } else {
            update_standing($conn, $league_id, $h, $hs, $as, 'D');
            update_standing($conn, $league_id, $a, $as, $hs, 'D');
        }
    }
    $stmt_fetch->close();
}

function update_standing(mysqli $conn, int $lid, int $tid, int $gf, int $ga, string $result): void
{
    $pts = ($result === 'W') ? 3 : (($result === 'D') ? 1 : 0);
    $w   = ($result === 'W') ? 1 : 0;
    $d   = ($result === 'D') ? 1 : 0;
    $l   = ($result === 'L') ? 1 : 0;
    $gd  = $gf - $ga;

    $stmt = $conn->prepare(
        'UPDATE standings SET
            played        = played + 1,
            won           = won + ?,
            drawn         = drawn + ?,
            lost          = lost + ?,
            goals_for     = goals_for + ?,
            goals_against = goals_against + ?,
            goal_diff     = goal_diff + ?,
            points        = points + ?
         WHERE league_id=? AND team_id=?'
    );
    $stmt->bind_param('iiiiiiiii', $w, $d, $l, $gf, $ga, $gd, $pts, $lid, $tid);
    $stmt->execute();
    $stmt->close();
}

/** Recalculate standings for the league that owns the given fixture. */
function asc_recalculate_standings(mysqli $conn, int $fixture_id): void
{
    if ($fixture_id <= 0) {
        return;
    }

    $stmt = $conn->prepare('SELECT league_id FROM fixtures WHERE fixture_id = ? LIMIT 1');
    $stmt->bind_param('i', $fixture_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        recalculate_standings($conn, (int) $row['league_id']);
    }
}
