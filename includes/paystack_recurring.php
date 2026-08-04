<?php
/**
 * Paystack Recurring Billing Helpers
 * 
 * Functions to handle Paystack Charge Authorization for recurring membership renewals
 */

/**
 * Charge a saved authorization (recurring billing)
 * 
 * @param string $authorizationCode Paystack authorization code
 * @param string $email Customer email
 * @param int $amount Amount in kobo (1 KES = 100 kobo)
 * @param array $metadata Additional metadata to attach to transaction
 * @return array Response from Paystack API
 */
function paystackChargeAuthorization(
    string $authorizationCode,
    string $email,
    int $amount,
    array $metadata = []
): array {
    $paystackSecretKey = getenv('PAYSTACK_SECRET_KEY');
    if (!$paystackSecretKey) {
        return [
            'status' => false,
            'message' => 'Paystack secret key not configured.'
        ];
    }

    $url = 'https://api.paystack.co/charge';

    $fields = [
        'authorization_code' => $authorizationCode,
        'email' => $email,
        'amount' => $amount,
        'metadata' => $metadata
    ];

    $fields_string = json_encode($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return [
            'status' => false,
            'message' => 'HTTP ' . $httpCode . ': ' . ($response ?: 'Unknown error')
        ];
    }

    $result = json_decode($response, true);
    return $result ?: [
        'status' => false,
        'message' => 'Invalid response from Paystack.'
    ];
}

/**
 * Create a Paystack customer for recurring billing
 * 
 * @param string $email Customer email
 * @param string $firstName Customer first name
 * @param string $lastName Customer last name
 * @param string $phone Customer phone number
 * @return array Response from Paystack API
 */
function paystackCreateCustomer(
    string $email,
    string $firstName,
    string $lastName,
    string $phone = ''
): array {
    $paystackSecretKey = getenv('PAYSTACK_SECRET_KEY');
    if (!$paystackSecretKey) {
        return [
            'status' => false,
            'message' => 'Paystack secret key not configured.'
        ];
    }

    $url = 'https://api.paystack.co/customer';

    $fields = [
        'email' => $email,
        'first_name' => $firstName,
        'last_name' => $lastName,
        'phone' => $phone
    ];

    $fields_string = json_encode($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return [
            'status' => false,
            'message' => 'HTTP ' . $httpCode . ': ' . ($response ?: 'Unknown error')
        ];
    }

    $result = json_decode($response, true);
    return $result ?: [
        'status' => false,
        'message' => 'Invalid response from Paystack.'
    ];
}

/**
 * Disable an authorization (stop recurring billing)
 * 
 * @param string $authorizationCode Authorization code to disable
 * @param string $email Customer email
 * @return array Response from Paystack API
 */
function paystackDisableAuthorization(
    string $authorizationCode,
    string $email
): array {
    $paystackSecretKey = getenv('PAYSTACK_SECRET_KEY');
    if (!$paystackSecretKey) {
        return [
            'status' => false,
            'message' => 'Paystack secret key not configured.'
        ];
    }

    $url = 'https://api.paystack.co/authorization/disable';

    $fields = [
        'authorization_code' => $authorizationCode,
        'email' => $email
    ];

    $fields_string = json_encode($fields);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $paystackSecretKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return [
            'status' => false,
            'message' => 'HTTP ' . $httpCode . ': ' . ($response ?: 'Unknown error')
        ];
    }

    $result = json_decode($response, true);
    return $result ?: [
        'status' => false,
        'message' => 'Invalid response from Paystack.'
    ];
}
