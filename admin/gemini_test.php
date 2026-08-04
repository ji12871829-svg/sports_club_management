<?php
require_once __DIR__ . "/../includes/gemini_client.php";

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$key = defined('GEMINI_API_KEY') ? trim((string) GEMINI_API_KEY) : '';
$keyStatus = asc_gemini_api_key_status();

echo "<h2>Gemini Connection Diagnostics</h2><pre>";

echo "1. cURL installed:       " . (function_exists('curl_init') ? "[OK] YES" : "[FAIL] NO - enable extension=curl in php.ini") . "\n";

if ($key === '') {
    echo "2. GEMINI_API_KEY set:   [FAIL] NO - add GEMINI_API_KEY to your .env file\n";
} else {
    $prefix = substr($key, 0, 4);
    echo "2. GEMINI_API_KEY set:   [OK] YES (length: " . strlen($key) . ", prefix: " . e($prefix) . "...)\n";
}

echo "3. API key format:       " . (!empty($keyStatus['valid_format']) ? "[OK] Google API key format" : "[FAIL] " . e($keyStatus['message'] ?? 'Invalid API key format')) . "\n";

$certPaths = [
    'C:/xampp/php/extras/ssl/cacert.pem',
    'C:/xampp/apache/bin/curl-ca-bundle.crt',
    'C:/Windows/System32/curl-ca-bundle.crt',
];
$certFound = '';
foreach ($certPaths as $path) {
    if (file_exists($path)) {
        $certFound = $path;
        break;
    }
}
echo "4. SSL cert file:        " . ($certFound ? "[OK] Found at $certFound" : "[WARN] Not found at common XAMPP paths") . "\n";
echo "5. php.ini curl.cainfo:  " . (ini_get('curl.cainfo') ?: "[WARN] Not set") . "\n";

if (empty($keyStatus['ready'])) {
    echo "\nCannot test Gemini until the API key issue above is fixed.\n";
    echo "Create a key at https://aistudio.google.com/app/apikey and put it in .env as GEMINI_API_KEY=your_key.\n";
    echo "</pre>";
    exit;
}

echo "\n6. Testing Gemini API call...\n";
$test = asc_gemini_test_connection();
if (!empty($test['success'])) {
    echo "   [OK] " . e($test['message']) . "\n";
    echo "   Model: " . e($test['model'] ?? 'unknown') . "\n";
} else {
    echo "   [FAIL] " . e($test['message'] ?? 'Gemini did not respond.') . "\n";
    echo "   If this is HTTP 401 or 403, regenerate the API key in Google AI Studio and confirm the Generative Language API is enabled for that project.\n";
}

echo "</pre>";
