<?php
/**
 * RFC 6238 TOTP (Google Authenticator compatible).
 */

function totp_generate_secret(int $length = 16): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    $bytes = random_bytes($length);

    for ($i = 0; $i < $length; $i++) {
        $secret .= $chars[ord($bytes[$i]) & 31];
    }

    return $secret;
}

function totp_base32_decode(string $secret): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/\s+/', '', $secret));
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';

    for ($i = 0, $len = strlen($secret); $i < $len; $i++) {
        $pos = strpos($chars, $secret[$i]);
        if ($pos === false) {
            continue;
        }
        $buffer = ($buffer << 5) | $pos;
        $bitsLeft += 5;
        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xff);
        }
    }

    return $output;
}

function totp_code(string $secret, ?int $timeSlice = null): string
{
    $timeSlice = $timeSlice ?? (int) floor(time() / 30);
    $secretKey = totp_base32_decode($secret);
    $time = pack('N*', 0, $timeSlice);
    $hash = hash_hmac('sha1', $time, $secretKey, true);
    $offset = ord($hash[19]) & 0x0f;
    $binary = (
        ((ord($hash[$offset]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    );
    $otp = $binary % 1000000;

    return str_pad((string) $otp, 6, '0', STR_PAD_LEFT);
}

function totp_verify(string $secret, string $code, int $window = 1): bool
{
    $code = preg_replace('/\s+/', '', trim($code));
    if (!preg_match('/^\d{6}$/', $code)) {
        return false;
    }

    $timeSlice = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $timeSlice + $i), $code)) {
            return true;
        }
    }

    return false;
}

function totp_provisioning_uri(string $secret, string $email, string $issuer = 'Apex Sports Club'): string
{
    $label = rawurlencode($issuer . ':' . $email);
    $params = http_build_query([
        'secret' => $secret,
        'issuer' => $issuer,
        'algorithm' => 'SHA1',
        'digits' => 6,
        'period' => 30,
    ]);

    return 'otpauth://totp/' . $label . '?' . $params;
}

function totp_qr_image_url(string $otpauthUri, int $size = 200): string
{
    return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&data=' . rawurlencode($otpauthUri);
}
