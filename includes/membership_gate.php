<?php
/**
 * Membership card QR verification tokens (gate scan).
 */
require_once __DIR__ . '/../config/api_config.php';

function membership_gate_secret(): string
{
    $secret = defined('CRON_SECRET') && CRON_SECRET !== '' ? CRON_SECRET : 'apex-sports-club';
    return $secret;
}

function membership_gate_token(int $memberId, string $email): string
{
    return substr(hash_hmac('sha256', $memberId . '|' . strtolower(trim($email)), membership_gate_secret()), 0, 16);
}

function membership_gate_valid(int $memberId, string $email, string $token): bool
{
    if ($memberId <= 0 || $token === '') {
        return false;
    }
    return hash_equals(membership_gate_token($memberId, $email), $token);
}

function membership_gate_verify_url(int $memberId, string $email): string
{
    require_once __DIR__ . '/url.php';
    $token = membership_gate_token($memberId, $email);
    return app_absolute_url('public/verify_membership.php?mid=' . $memberId . '&t=' . $token);
}
