<?php
/**
 * public/api/standings.php
 * JSON standings for a league — polled by view_fixtures.php.
 * Now with short-TTL cache and ETag support.
 */
require_once '../../config/db_connect.php';
require_once __DIR__ . '/../../includes/cache.php';
require_once __DIR__ . '/../../includes/rate_limiter.php';

header('Content-Type: application/json');

// Public, unauthenticated endpoint — cap each source IP so it cannot be
// hammered.
if (!rate_limit_check(client_rate_key('api_standings'), 60, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests. Please slow down.']);
    exit;
}

$league_id = (int) ($_GET['league_id'] ?? 0);

if ($league_id <= 0) {
    header('Cache-Control: no-store');
    echo json_encode(['standings' => [], 'updated_at' => date('H:i:s'), 'league_id' => 0]);
    $conn->close();
    exit;
}

// 30-second cache — reduces DB hits from 15s polling
$cacheKey = "standings:{$league_id}";
$cached = cache_get($cacheKey);

if ($cached !== null) {
    // ETag support for conditional requests
    $etag = '"' . md5(json_encode($cached)) . '"';
    header("ETag: {$etag}");
    header('Cache-Control: public, max-age=30, must-revalidate');

    if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
        http_response_code(304);
        exit;
    }

    echo json_encode($cached);
    $conn->close();
    exit;
}

$stmt = $conn->prepare(
    'SELECT st.team_id, st.played, st.won, st.drawn, st.lost,
            st.goals_for, st.goals_against, st.goal_diff, st.points,
            t.name AS team_name, t.short_name
     FROM standings st
     JOIN teams t ON t.team_id = st.team_id
     WHERE st.league_id = ?
     ORDER BY st.points DESC, st.goal_diff DESC, st.goals_for DESC, t.name'
);
$stmt->bind_param('i', $league_id);
$stmt->execute();
$result = $stmt->get_result();

$standings = [];
while ($row = $result->fetch_assoc()) {
    $standings[] = $row;
}
$stmt->close();

$response = [
    'standings'  => $standings,
    'updated_at' => date('H:i:s'),
    'league_id'  => $league_id,
    'count'      => count($standings),
];

// Cache for 30 seconds
cache_set($cacheKey, $response, 30);

// ETag
$etag = '"' . md5(json_encode($response)) . '"';
header("ETag: {$etag}");
header('Cache-Control: public, max-age=30, must-revalidate');

echo json_encode($response);

$conn->close();
