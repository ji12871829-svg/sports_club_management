<?php
require_once __DIR__ . '/../config/api_config.php';

function mpesaBaseUrl() {
    return MPESA_ENV === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

function getMpesaToken() {
    $credentials = base64_encode(MPESA_CONSUMER_KEY . ':' . MPESA_CONSUMER_SECRET);
    $curl = curl_init(mpesaBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $credentials]
    ]);

    $responseBody = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpCode  = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($responseBody === false) {
        error_log('M-Pesa token request failed: ' . $curlError);
        return null;
    }

    $response = json_decode($responseBody, true);
    if (!is_array($response) || empty($response['access_token'])) {
        error_log('M-Pesa token response invalid. HTTP ' . $httpCode . ' body=' . $responseBody);
        return null;
    }

    return $response['access_token'];
}

function mpesaSTKPush($phone, $amount, $description = 'Apex Sports Club Payment') {
    $missingConfig = [];
    if (trim(MPESA_CONSUMER_KEY) === '') $missingConfig[] = 'MPESA_CONSUMER_KEY';
    if (trim(MPESA_CONSUMER_SECRET) === '') $missingConfig[] = 'MPESA_CONSUMER_SECRET';
    if (trim(MPESA_SHORTCODE) === '') $missingConfig[] = 'MPESA_SHORTCODE';
    if (trim(MPESA_PASSKEY) === '') $missingConfig[] = 'MPESA_PASSKEY';
    if (trim(MPESA_CALLBACK_URL) === '') $missingConfig[] = 'MPESA_CALLBACK_URL';

    if ($missingConfig) {
        return ['error' => 'Missing M-Pesa config: ' . implode(', ', $missingConfig)];
    }

    $token     = getMpesaToken();
    if (!$token) return ['error' => 'Could not get M-Pesa token'];

    $timestamp = date('YmdHis');
    $password  = base64_encode(MPESA_SHORTCODE . MPESA_PASSKEY . $timestamp);

    $payload = [
        'BusinessShortCode' => MPESA_SHORTCODE,
        'Password'          => $password,
        'Timestamp'         => $timestamp,
        'TransactionType'   => 'CustomerPayBillOnline',
        'Amount'            => (int) $amount,
        'PartyA'            => $phone,
        'PartyB'            => MPESA_SHORTCODE,
        'PhoneNumber'       => $phone,
        'CallBackURL'       => MPESA_CALLBACK_URL,
        'AccountReference'  => 'ApexClub',
        'TransactionDesc'   => $description
    ];

    $curl = curl_init(mpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest');
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]
    ]);
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}

function formatMpesaPhone($phone) {
    $phone = preg_replace('/\D/', '', $phone);
    if (substr($phone, 0, 1) === '0') $phone = '254' . substr($phone, 1);
    if (substr($phone, 0, 1) === '+') $phone = substr($phone, 1);
    return $phone;
}
