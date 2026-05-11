<?php
// ============================================================
//  public/get_sport_data.php
//  Called by view_sports.php via JavaScript fetch()
//  Keeps your API key hidden on the server side
// ============================================================
require_once '../config/api_config.php';

header('Content-Type: application/json');

$sport = $_GET['sport'] ?? '';
$type  = $_GET['type']  ?? 'fixtures'; // fixtures, standings, live

// Map sport names to their API-Sports endpoints
$endpoints = [
    'Football' => [
        'live'      => 'https://v3.football.api-sports.io/fixtures?live=all',
        'fixtures'  => 'https://v3.football.api-sports.io/fixtures?league=264&season=2024', // Kenya Premier League
        'standings' => 'https://v3.football.api-sports.io/standings?league=264&season=2024',
    ],
    'Rugby' => [
        'fixtures'  => 'https://v1.rugby.api-sports.io/games?season=2024',
        'live'      => 'https://v1.rugby.api-sports.io/games?live=all',
    ],
    'Volleyball' => [
        'fixtures'  => 'https://v1.volleyball.api-sports.io/games?season=2024',
        'live'      => 'https://v1.volleyball.api-sports.io/games?live=all',
    ],
    'Hockey' => [
        'fixtures'  => 'https://v1.hockey.api-sports.io/games?season=2024',
        'live'      => 'https://v1.hockey.api-sports.io/games?live=all',
    ],
];

// Map sport to correct API host
$hosts = [
    'Football'   => 'v3.football.api-sports.io',
    'Rugby'      => 'v1.rugby.api-sports.io',
    'Volleyball' => 'v1.volleyball.api-sports.io',
    'Hockey'     => 'v1.hockey.api-sports.io',
];

if (!isset($endpoints[$sport])) {
    echo json_encode(['error' => 'Sport not supported yet', 'sport' => $sport]);
    exit;
}

$url  = $endpoints[$sport][$type] ?? $endpoints[$sport]['fixtures'];
$host = $hosts[$sport];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "x-apisports-key: " . API_SPORTS_KEY,
        "x-rapidapi-host: " . $host
    ]
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($httpCode !== 200) {
    echo json_encode(['error' => 'API request failed', 'code' => $httpCode]);
    exit;
}

echo $response;
?>