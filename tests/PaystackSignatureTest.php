<?php
/**
 * PaystackSignatureTest — verifies the webhook signature gate
 * (includes/paystack.php::paystack_verify_signature).
 *
 * Paystack webhooks carry `x-paystack-signature` = HMAC-SHA256 of the raw
 * body signed with the secret key. Forged or tampered requests must be
 * rejected before any data is trusted. The function is pure and takes the
 * secret as a parameter, so tests run without touching the network or the
 * configured production key.
 *
 * Run: php phpunit.phar --configuration=phpunit.xml
 */

use PHPUnit\Framework\TestCase;

// includes/paystack.php throws unless PAYSTACK_SECRET_KEY is defined — the
// function under test accepts its own secret, so a dummy constant suffices.
if (!defined('PAYSTACK_SECRET_KEY')) {
    define('PAYSTACK_SECRET_KEY', 'sk_test_dummy_for_unit_test');
}
require_once __DIR__ . '/../includes/paystack.php';

class PaystackSignatureTest extends TestCase
{
    /** A body signed with the correct secret must pass. */
    public function testValidSignaturePasses(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"REF_123"}}';
        $secret = 'sk_test_unit_secret';
        $sig = hash_hmac('sha256', $body, $secret);

        $this->assertTrue(paystack_verify_signature($body, $sig, $secret));
    }

    /** A signature computed with the wrong secret must be rejected. */
    public function testForgedSignatureRejected(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"REF_123"}}';
        $wrong = hash_hmac('sha256', $body, 'attacker_secret');

        $this->assertFalse(paystack_verify_signature($body, $wrong, 'sk_test_unit_secret'));
    }

    /** Tampering with the body invalidates the signature. */
    public function testTamperedBodyRejected(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"REF_123"}}';
        $secret = 'sk_test_unit_secret';
        $sig = hash_hmac('sha256', $body, $secret);

        $tampered = '{"event":"charge.success","data":{"reference":"REF_123","amount":999}}';
        $this->assertFalse(paystack_verify_signature($tampered, $sig, $secret));
    }

    /** An empty signature header must fail closed (never "pass by default"). */
    public function testEmptySignatureFailsClosed(): void
    {
        $body = '{"event":"charge.success"}';
        $this->assertFalse(paystack_verify_signature($body, '', 'sk_test_unit_secret'));
    }

    /** An empty body must fail closed. */
    public function testEmptyBodyFailsClosed(): void
    {
        $sig = hash_hmac('sha256', '', 'sk_test_unit_secret');
        $this->assertFalse(paystack_verify_signature('', $sig, 'sk_test_unit_secret'));
    }

    /** A missing secret must fail closed even with a matching-looking sig. */
    public function testMissingSecretFailsClosed(): void
    {
        $body = '{"event":"charge.success"}';
        $sig = hash_hmac('sha256', $body, '');
        $this->assertFalse(paystack_verify_signature($body, $sig, ''));
    }

    /** Default-secret overload resolves the configured key (constant above). */
    public function testDefaultSecretUsesConfiguredKey(): void
    {
        $body = '{"event":"charge.success"}';
        $sig = hash_hmac('sha256', $body, PAYSTACK_SECRET_KEY);
        $this->assertTrue(paystack_verify_signature($body, $sig));
    }
}
