<?php
require_once 'config/api_config.php';

if (trim(BREVO_API_KEY) === '') {
    die('<p>BREVO_API_KEY is not configured. Copy .env.example to .env and set your Brevo key there.</p>');
}

$payload = [
    "sender"      => ["name" => "Sports Club", "email" => CLUB_EMAIL_FROM],
    "to"          => [["email" => "yourname@gmail.com", "name" => "Test User"]], // ← your email here
    "subject"     => "Sports Club Test Email",
    "htmlContent" => "<h2>Test email from Sports Club!</h2>"
];

$curl = curl_init();
curl_setopt_array($curl, [
    CURLOPT_URL            => "https://api.brevo.com/v3/smtp/email",
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        "api-key: " . BREVO_API_KEY,
        "Content-Type: application/json",
        "Accept: application/json"
    ]
]);

$response = curl_exec($curl);
$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
$curlError = curl_error($curl);
curl_close($curl);

echo "<h3>Debug Results:</h3>";
echo "<p><strong>API Key:</strong> configured</p>";
echo "<p><strong>Sender Email:</strong> " . htmlspecialchars(CLUB_EMAIL_FROM) . "</p>";
echo "<p><strong>HTTP Status Code:</strong> " . $httpCode . "</p>";
echo "<p><strong>cURL Error:</strong> " . htmlspecialchars($curlError ?: 'None') . "</p>";
echo "<p><strong>Brevo Response:</strong> " . htmlspecialchars($response) . "</p>";
?>
