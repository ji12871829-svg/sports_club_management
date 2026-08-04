<?php
/**
 * public/api/live_scores.php
 * Lightweight JSON endpoint polled by view_fixtures.php every 15 seconds.
 * Returns all fixtures that are currently Live OR scheduled for today.
 * Now with 15-second cache and ETag support.
 */
require_once '../../config/db_connect.php';
require_once __DIR__ . '/../../includes/cache.php';

header('Content-Type: application/json');

// 15-second cache to reduce DB hits from polling
$cacheKey = 'live_scores:today';
$cached = cache_get($cacheKey);

if ($cached !== null) {
    $etag = '"' . md5(json_encode($cached)) . '"';
    header("ETag: {$etag}");
    header('Cache-Control: public, max-age=15, must-revalidate');

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    echo json_encode($cached);
    $conn->close();
    exit;
}

$sql = "
    SELECT
        f.fixture_id,
        f.match_date,
        f.match_time,
        f.venue,
        f.matchday,
        f.status,
        f.home_score,
        f.away_score,
        f.live_minute,
        f.live_status,
        f.live_updated_at,
        h.name AS home_team,
        a.name AS away_team,
        l.name AS league_name,
        l.league_id
    FROM fixtures f
    JOIN teams h ON h.team_id = f.home_team_id
    JOIN teams a ON a.team_id = f.away_team_id
    JOIN leagues l ON l.league_id = f.league_id
    WHERE f.status = 'Live'
       OR DATE(f.match_date) = CURDATE()
    ORDER BY
        (f.status = 'Live') DESC,
        f.match_date ASC,
        f.match_time ASC
";

$result  = $conn->query($sql);
$fixtures = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $fixtures[] = $row;
    }
}

$response = [
    'fixtures'   => $fixtures,
    'updated_at' => date('H:i:s'),
    'count'      => count($fixtures),
];

cache_set($cacheKey, $response, 15);

$etag = '"' . md5(json_encode($response)) . '"';
header("ETag: {$etag}");
header('Cache-Control: public, max-age=15, must-revalidate');

echo json_encode($response);

$conn->close();
