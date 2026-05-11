<?php
require_once 'config/api_config.php';

if (trim(API_SPORTS_KEY) === '') {
    die('<p>API_SPORTS_KEY is not configured. Copy .env.example to .env and set your API-Sports key there.</p>');
}

echo "<h3>Debug Info:</h3>";
echo "<p><strong>API key:</strong> configured</p>";

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => "https://v3.football.api-sports.io/status",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        "x-apisports-key: " . API_SPORTS_KEY
    ]
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$data = json_decode($response, true);

echo "<p><strong>HTTP Status:</strong> " . $httpCode . "</p>";
echo "<p><strong>Full Response:</strong></p>";
echo "<pre>" . htmlspecialchars(print_r($data, true)) . "</pre>";
?>
