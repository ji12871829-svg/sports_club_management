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
/**
 * Verify a Paystack webhook signature (HMAC-SHA256 of the raw body).
 *
 * Pure and network-free so it is unit-testable. An empty secret, empty
 * signature, or empty body always fails closed (returns false).
 *
 * @param string $rawBody   Raw request body as received (php://input)
 * @param string $sigHeader Value of the x-paystack-signature header
 * @param string|null $secret Secret key; defaults to PAYSTACK_SECRET_KEY
 * @return bool
 */
function paystack_verify_signature($rawBody, $sigHeader, $secret = null)
{
    if ($secret === null) {
        $secret = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
    }
    if ($secret === '' || $rawBody === '' || $sigHeader === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, $sigHeader);
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
