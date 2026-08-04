<?php
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/fixture_scheduler.php';

$league_id = (int) ($_GET['league_id'] ?? 0);
$league = null;

if ($league_id > 0) {
    $league = asc_fixture_fetch_league($conn, $league_id);
    if (!$league) {
        http_response_code(404);
        echo 'League not found.';
        $conn->close();
        exit;
    }
}

$filename_label = 'all_leagues';
if ($league) {
    $filename_label = $league['sport_name'] . '_' . $league['name'] . '_' . $league['season'];
}
$filename_label = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($filename_label));
$filename_label = trim($filename_label, '_') ?: 'fixtures';
$filename = 'fixtures_' . $filename_label . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, [
    'Sport',
    'League',
    'Season',
    'Matchday',
    'Date',
    'Time',
    'Home Team',
    'Away Team',
    'Venue',
    'Status',
    'Home Score',
    'Away Score',
]);

$sql = "SELECT s.name AS sport_name, l.name AS league_name, l.season,
               f.matchday, f.match_date, f.match_time, f.venue, f.status,
               f.home_score, f.away_score,
               h.name AS home_team, a.name AS away_team
        FROM fixtures f
        JOIN leagues l ON l.league_id = f.league_id
        JOIN sports s ON s.sport_id = l.sport_id
        JOIN teams h ON h.team_id = f.home_team_id
        JOIN teams a ON a.team_id = f.away_team_id";

if ($league_id > 0) {
    $sql .= " WHERE f.league_id = ?";
}

$sql .= " ORDER BY s.name, l.name, f.matchday, f.match_date, f.match_time, f.fixture_id";

if ($league_id > 0) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        fclose($out);
        $conn->close();
        exit;
    }
    $stmt->bind_param('i', $league_id);
    $stmt->execute();
    $result = $stmt->get_result();
} else {
    $result = $conn->query($sql);
}

if ($result) {
    while ($row = $result->fetch_assoc()) {
        fputcsv($out, [
            $row['sport_name'],
            $row['league_name'],
            $row['season'],
            $row['matchday'],
            $row['match_date'],
            substr((string) $row['match_time'], 0, 5),
            $row['home_team'],
            $row['away_team'],
            $row['venue'],
            $row['status'],
            $row['home_score'],
            $row['away_score'],
        ]);
    }
}

if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    $stmt->close();
}

fclose($out);
$conn->close();
exit;
