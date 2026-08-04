<?php
// Loads application configuration from a local .env file.
//
// Real API keys and passwords should live in .env only.
// Commit .env.example to GitHub so other developers know which values to set.

load_dotenv(__DIR__ . '/../.env');

// Base URL of the app, auto-detected from the current request so the project
// works regardless of the folder name it is deployed under (e.g. /Apex Sports Club/,
// /sports_club_management/, or the web root). Used by includes/header.php and footer.php.
if (!defined('BASE_URL')) {
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
    $scriptName = '/' . trim(str_replace('\\', '/', $scriptName), '/');
    $scriptParts = explode('/', $scriptName);
    array_pop($scriptParts); // strip the file name
    if (in_array(end($scriptParts), ['public', 'admin', 'callbacks'], true)) {
        array_pop($scriptParts); // strip the public|admin|callbacks segment to reach the project root
    }
    define('BASE_URL', implode('/', $scriptParts));
    unset($scriptName, $scriptParts);
}

// Full site URL (scheme + host + BASE_URL), used by includes/send_email.php for
// absolute links in outgoing emails (email clients can't resolve relative paths).
// Override in .env with SITE_URL=... if auto-detection is wrong (e.g. behind a proxy).
if (!defined('SITE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    define('SITE_URL', rtrim(config_value('SITE_URL', '') ?: $scheme . '://' . $host . BASE_URL, '/'));
    unset($scheme, $host);
}

define('DB_HOST', config_value('DB_HOST', 'localhost'));
define('DB_USER', config_value('DB_USER', 'root'));
define('DB_PASSWORD', config_value('DB_PASSWORD', ''));
define('DB_NAME', config_value('DB_NAME', 'sports_club_db'));

define('BREVO_API_KEY', config_value('BREVO_API_KEY'));
define('CLUB_EMAIL_FROM', config_value('CLUB_EMAIL_FROM', 'ji12871829@gmail.com'));
define('CLUB_EMAIL_NAME', config_value('CLUB_EMAIL_NAME', 'Apex Sports Club'));

define('RECAPTCHA_SITE_KEY', config_value('RECAPTCHA_SITE_KEY'));
define('RECAPTCHA_SECRET_KEY', config_value('RECAPTCHA_SECRET_KEY'));

define('API_SPORTS_KEY', config_value('API_SPORTS_KEY'));

define('GEMINI_API_KEY', config_value('GEMINI_API_KEY'));
define('OPENROUTER_API_KEY', config_value('OPENROUTER_API_KEY'));

define('PAYSTACK_SECRET_KEY', config_value('PAYSTACK_SECRET_KEY'));
define('PAYSTACK_PUBLIC_KEY', config_value('PAYSTACK_PUBLIC_KEY'));
define('PAYSTACK_CALLBACK_URL', config_value('PAYSTACK_CALLBACK_URL', 'https://traverse-proofread-thirty.ngrok-free.dev/sports_club_management/admin/paystack_callback.php'));
// --- MPESA DARAJA ---
define('MPESA_CONSUMER_KEY', config_value('MPESA_CONSUMER_KEY'));
define('MPESA_CONSUMER_SECRET', config_value('MPESA_CONSUMER_SECRET'));
define('MPESA_SHORTCODE', config_value('MPESA_SHORTCODE', '174379'));  // sandbox shortcode
define('MPESA_PASSKEY', config_value('MPESA_PASSKEY'));
define('MPESA_CALLBACK_URL', config_value('MPESA_CALLBACK_URL', 'https://your-ngrok-domain.ngrok-free.app/mpesa_callback.php'));
define('MPESA_ENV', config_value('MPESA_ENV', 'sandbox')); // change to 'production' when live


define('CLUB_LAT', (float) config_value('CLUB_LAT', '-1.286389'));
define('CLUB_LNG', (float) config_value('CLUB_LNG', '36.817223'));
define('CLUB_CITY', config_value('CLUB_CITY', 'Nairobi'));

function config_value(string $name, string $default = ''): string
{
    $value = getenv($name);
    if ($value !== false) {
        return $value;
    }

    if (array_key_exists($name, $_ENV)) {
        return $_ENV[$name];
    }

    if (array_key_exists($name, $_SERVER)) {
        return $_SERVER[$name];
    }

    return $default;
}

function load_dotenv(string $path): void
{
    if (!file_exists($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2) + [1 => '']);
        if ($name === '') {
            continue;
        }

        if (
            strlen($value) >= 2
            && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if (getenv($name) === false) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
