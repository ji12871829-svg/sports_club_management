<?php
/**
 * MpesaCallbackUrlTest — verifies the fail-fast callback-URL validation
 * in includes/mpesa.php.
 *
 * Safaricom's Daraja API rejects plain-HTTP callback URLs with
 * 400.002.02 "Bad Request - Invalid CallBackURL". The app must fail fast
 * with a clear message instead of surfacing the cryptic API error.
 *
 * Run: php phpunit.phar --configuration=phpunit.xml
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/mpesa.php';

class MpesaCallbackUrlTest extends TestCase
{
    /**
     * A plain http:// URL (what caused the user-facing bug) must be rejected.
     */
    public function testHttpCallbackUrlIsRejected(): void
    {
        $error = mpesa_callback_url_error('http://localhost/Apex%20Sports%20Club/callbacks/mpesa_callback.php');

        $this->assertNotNull($error, 'http:// callback URL should be rejected');
        $this->assertStringContainsString('https://', $error, 'Error should tell the user https:// is required');
        $this->assertStringContainsString('Invalid CallBackURL', $error);
    }

    /**
     * localhost URLs must be rejected even if https:// — Safaricom can
     * never reach them.
     */
    public function testLocalhostHttpsCallbackUrlIsRejected(): void
    {
        $error = mpesa_callback_url_error('https://localhost/callbacks/mpesa_callback.php');

        $this->assertNotNull($error, 'localhost callback URL should be rejected');
    }

    /**
     * Placeholder ngrok-style domains must be rejected so a misconfigured
     * .env fails loudly instead of silently.
     */
    public function testPlaceholderCallbackUrlIsRejected(): void
    {
        $error = mpesa_callback_url_error('https://your-ngrok-domain.ngrok-free.app/callbacks/mpesa_callback.php');

        $this->assertNotNull($error, 'placeholder callback URL should be rejected');
        $this->assertStringContainsString('placeholder', $error);
    }

    /**
     * A real https:// public URL passes validation.
     */
    public function testValidHttpsCallbackUrlPasses(): void
    {
        $error = mpesa_callback_url_error('https://traverse-proofread-thirty.ngrok-free.dev/Apex%20Sports%20Club/callbacks/mpesa_callback.php');

        $this->assertNull($error, 'valid https:// callback URL should pass validation');
    }

    /**
     * An empty callback URL must be rejected.
     */
    public function testEmptyCallbackUrlIsRejected(): void
    {
        $error = mpesa_callback_url_error('');

        $this->assertNotNull($error, 'empty callback URL should be rejected');
        $this->assertStringContainsString('empty', $error);
    }

    /**
     * Uppercase HTTPS:// scheme must be accepted (case-insensitive check).
     */
    public function testUppercaseHttpsSchemePasses(): void
    {
        $error = mpesa_callback_url_error('HTTPS://payments.myclub.co.ke/callback.php');

        $this->assertNull($error, 'uppercase HTTPS:// should be accepted');
    }
}
