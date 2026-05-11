<?php
require_once __DIR__ . '/../config/api_config.php';

if (!defined('PAYSTACK_SECRET_KEY') || trim(PAYSTACK_SECRET_KEY) === '') {
    throw new RuntimeException('PAYSTACK_SECRET_KEY is not configured. Copy .env.example to .env and set your Paystack key there.');
}

/**
 * Initialize a Paystack transaction
 * @param string $email
 * @param float  $amount
 * @param string $callbackUrl
 * @param array  $metadata
 * @return array
 */
function paystackInitTransaction($email, $amount, $callbackUrl, $metadata = []) {
    $payload = [
        'email'        => $email,
        'amount'       => (int) round($amount * 100),
        'callback_url' => $callbackUrl,
        'metadata'     => $metadata
    ];

    $curl = curl_init('https://api.paystack.co/transaction/initialize');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Content-Type: application/json'
        ]
    ]);

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}

/**
 * Verify a Paystack transaction by reference
 * @param string $reference
 * @return array
 */
function paystackVerifyTransaction($reference) {
    $curl = curl_init('https://api.paystack.co/transaction/verify/' . urlencode($reference));
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . PAYSTACK_SECRET_KEY,
            'Content-Type: application/json'
        ]
    ]);

    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}
