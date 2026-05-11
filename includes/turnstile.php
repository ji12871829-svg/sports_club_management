<?php
// ============================================================
//  includes/turnstile.php
//  Cloudflare Turnstile — Free CAPTCHA replacement
//  Sign up: dash.cloudflare.com → Turnstile
// ============================================================

require_once __DIR__ . '/../config/api_config.php';

function verifyTurnstile($token) {

    // Check token is not empty
    if (empty($token)) return false;

    // Check secret key is defined in api_config.php
    if (!defined('CF_TURNSTILE_SECRET_KEY') || empty(CF_TURNSTILE_SECRET_KEY)) {
        error_log('CF_TURNSTILE_SECRET_KEY is not defined in api_config.php');
        return false;
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => CF_TURNSTILE_SECRET_KEY,
            'response' => $token,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded']
    ]);

    $response  = curl_exec($curl);
    curl_close($curl);

    $result = json_decode($response, true);
    return isset($result['success']) && $result['success'] === true;
}