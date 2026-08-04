<?php

function asc_fixture_fetch_league(mysqli $conn, int $league_id): ?array
{
    $sql = "SELECT l.league_id, l.name, l.season, s.name AS sport_name
            FROM leagues l
            JOIN sports s ON s.sport_id = l.sport_id
            WHERE l.league_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare league lookup.');
    }

    $stmt->bind_param('i', $league_id);
    $stmt->execute();
    $league = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $league ?: null;
}

function asc_fixture_fetch_active_teams(mysqli $conn, int $league_id): array
{
    $sql = "SELECT team_id, name, home_ground
            FROM teams
            WHERE league_id = ? AND status = 'Active'
            ORDER BY name";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare team lookup.');
    }

    $stmt->bind_param('i', $league_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $teams = [];
    while ($row = $result->fetch_assoc()) {
        $teams[] = $row;
    }
    $stmt->close();

    return $teams;
}

function asc_fixture_existing_keys(mysqli $conn, int $league_id): array
{
    $sql = "SELECT home_team_id, away_team_id
            FROM fixtures
            WHERE league_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare fixture lookup.');
    }

    $stmt->bind_param('i', $league_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $keys = [
        'legs' => [],
        'pairs' => [],
    ];

    while ($row = $result->fetch_assoc()) {
        $home_id = (int) $row['home_team_id'];
        $away_id = (int) $row['away_team_id'];
        $keys['legs'][$home_id . '-' . $away_id] = true;
        $keys['pairs'][min($home_id, $away_id) . '-' . max($home_id, $away_id)] = true;
    }
    $stmt->close();

    return $keys;
}

function asc_fixture_valid_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed instanceof DateTime && $parsed->format('Y-m-d') === $date;
}

function asc_fixture_normalize_time(string $time): string
{
    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time . ':00';
    }

    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
        return $time;
    }

    return '15:00:00';
}

function asc_fixture_round_robin(array $teams, bool $double_round): array
{
    $participants = array_values($teams);
    if (count($participants) % 2 === 1) {
        $participants[] = null;
    }

    $team_count = count($participants);
    $round_count = $team_count - 1;
    $half = (int) ($team_count / 2);
    $rounds = [];

    for ($round = 0; $round < $round_count; $round++) {
        $matches = [];

        for ($i = 0; $i < $half; $i++) {
            $team_a = $participants[$i];
            $team_b = $participants[$team_count - 1 - $i];

            if ($team_a === null || $team_b === null) {
                continue;
            }

            $home_first = (($round + $i) % 2 === 0);
            if ($i === 0 && $round % 2 === 1) {
                $home_first = !$home_first;
            }

            $matches[] = [
                'home' => $home_first ? $team_a : $team_b,
                'away' => $home_first ? $team_b : $team_a,
            ];
        }

        if (!empty($matches)) {
            $rounds[] = $matches;
        }

        $rotating = array_splice($participants, 1);
        array_unshift($rotating, array_pop($rotating));
        $participants = array_merge([$participants[0]], $rotating);
    }

    if (!$double_round) {
        return $rounds;
    }

    $second_leg = [];
    foreach ($rounds as $round_matches) {
        $reverse_round = [];
        foreach ($round_matches as $match) {
            $reverse_round[] = [
                'home' => $match['away'],
                'away' => $match['home'],
            ];
        }
        $second_leg[] = $reverse_round;
    }

    return array_merge($rounds, $second_leg);
}

function asc_fixture_generate_round_robin(
    mysqli $conn,
    int $league_id,
    string $start_date,
    string $match_time,
    int $days_between_rounds,
    bool $double_round,
    bool $replace_scheduled,
    string $default_venue
): array {
    if ($league_id <= 0) {
        throw new InvalidArgumentException('Please select a league.');
    }

    if (!asc_fixture_valid_date($start_date)) {
        throw new InvalidArgumentException('Please choose a valid start date.');
    }

    $league = asc_fixture_fetch_league($conn, $league_id);
    if (!$league) {
        throw new InvalidArgumentException('Selected league was not found.');
    }

    $teams = asc_fixture_fetch_active_teams($conn, $league_id);
    if (count($teams) < 2) {
        throw new InvalidArgumentException('At least two active teams are required.');
    }

    $days_between_rounds = max(1, min(30, $days_between_rounds));
    $match_time = asc_fixture_normalize_time($match_time);
    $default_venue = trim($default_venue);
    $rounds = asc_fixture_round_robin($teams, $double_round);
    $start = new DateTime($start_date);

    $inserted = 0;
    $skipped = 0;
    $total = 0;

    $conn->begin_transaction();

    try {
        if ($replace_scheduled) {
            $stmt = $conn->prepare("DELETE FROM fixtures WHERE league_id = ? AND status = 'Scheduled'");
            if (!$stmt) {
                throw new RuntimeException('Could not prepare scheduled fixture cleanup.');
            }
            $stmt->bind_param('i', $league_id);
            $stmt->execute();
            $stmt->close();
        }

        $existing = asc_fixture_existing_keys($conn, $league_id);
        $sql = "INSERT INTO fixtures (league_id, home_team_id, away_team_id, match_date, match_time, venue, matchday)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Could not prepare fixture insert.');
        }

        foreach ($rounds as $round_index => $matches) {
            $matchday = $round_index + 1;
            $round_date = clone $start;
            if ($round_index > 0) {
                $round_date->modify('+' . ($round_index * $days_between_rounds) . ' days');
            }
            $match_date = $round_date->format('Y-m-d');

            foreach ($matches as $match) {
                $total++;
                $home_id = (int) $match['home']['team_id'];
                $away_id = (int) $match['away']['team_id'];
                $leg_key = $home_id . '-' . $away_id;
                $pair_key = min($home_id, $away_id) . '-' . max($home_id, $away_id);

                if (($double_round && isset($existing['legs'][$leg_key])) || (!$double_round && isset($existing['pairs'][$pair_key]))) {
                    $skipped++;
                    continue;
                }

                $venue = trim((string) ($match['home']['home_ground'] ?? ''));
                if ($venue === '') {
                    $venue = $default_venue;
                }

                $stmt->bind_param('iiisssi', $league_id, $home_id, $away_id, $match_date, $match_time, $venue, $matchday);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not save fixture: ' . $stmt->error);
                }

                $existing['legs'][$leg_key] = true;
                $existing['pairs'][$pair_key] = true;
                $inserted++;
            }
        }

        $stmt->close();

        $stmt = $conn->prepare("INSERT IGNORE INTO standings (league_id, team_id)
                                SELECT league_id, team_id FROM teams WHERE league_id = ?");
        if (!$stmt) {
            throw new RuntimeException('Could not prepare standings seed.');
        }
        $stmt->bind_param('i', $league_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'league' => $league,
        'teams' => count($teams),
        'total' => $total,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'rounds' => count($rounds),
        'double_round' => $double_round,
    ];
}
