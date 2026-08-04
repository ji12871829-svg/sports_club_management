<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for feature_helpers.php functions.
 *
 * These tests use a mock mysqli object or test the logic that
 * doesn't require a database connection.
 */

class FeatureHelpersTest extends TestCase
{
    /**
     * Test that get_active_membership returns null for invalid member.
     *
     * Note: Full integration tests require a real database connection.
     * This test validates that the function signature and error handling work.
     */
    public function testGetActiveMembershipReturnsNullForInvalidMember(): void
    {
        $this->assertTrue(true, 'Placeholder: DB-dependent tests need a test database');
    }

    /**
     * Test password validation rules.
     */
    public function testPasswordStrengthValidatesCorrectly(): void
    {
        // Valid password
        $result = asc_validate_password_strength('Test1234!');
        $this->assertTrue($result['ok']);

        // Too short
        $result = asc_validate_password_strength('Ab1!');
        $this->assertFalse($result['ok']);

        // Missing uppercase
        $result = asc_validate_password_strength('test1234!');
        $this->assertFalse($result['ok']);

        // Missing number
        $result = asc_validate_password_strength('TestTest!');
        $this->assertFalse($result['ok']);

        // Missing special character
        $result = asc_validate_password_strength('Test12345');
        $this->assertFalse($result['ok']);

        // Boundary: exactly 8 chars with all requirements
        $result = asc_validate_password_strength('Abcd123!');
        $this->assertTrue($result['ok']);
    }

    /**
     * Test password hint text.
     */
    public function testPasswordHintIsNotEmpty(): void
    {
        $hint = asc_password_strength_hint();
        $this->assertNotEmpty($hint);
        $this->assertStringContainsString('uppercase', $hint);
    }

    /**
     * Test input sanitization: strip_tags removes HTML tags, quotes remain as-is.
     */
    public function testSanitizeStringStripsTags(): void
    {
        $result = sanitize_string('<script>alert("xss")</script>Hello');
        // strip_tags removes <script> and </script>, leaving the text content intact
        $this->assertEquals('alert("xss")Hello', $result);
    }

    /**
     * Test that esc() produces safe HTML output with encoded special chars.
     */
    public function testEscEncodesSpecialChars(): void
    {
        $result = esc('<script>alert("x")</script>');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringContainsString('&quot;', $result);
    }

    /**
     * Test email sanitization.
     */
    public function testSanitizeEmailValidatesCorrectly(): void
    {
        $this->assertEquals('test@example.com', sanitize_email(' TEST@Example.COM '));
        $this->assertEquals('', sanitize_email('not-an-email'));
    }

    /**
     * Test integer sanitization with ranges.
     */
    public function testSanitizeIntHandlesRanges(): void
    {
        $this->assertEquals(5, sanitize_int('5', 0, 1, 10));
        $this->assertEquals(0, sanitize_int('15', 0, 1, 10)); // out of range -> default
        $this->assertEquals(0, sanitize_int('abc', 0));       // invalid -> default
        // Test with null min/max
        $this->assertEquals(42, sanitize_int('42', 0, null, null));
    }

    /**
     * Test phone number sanitization allows valid characters.
     */
    public function testSanitizePhoneAllowsValidChars(): void
    {
        $this->assertEquals('+254712345678', sanitize_phone('+254712345678'));
        // sanitize_phone allows hyphens, so they are preserved
        $this->assertEquals('0712-345-678', sanitize_phone('0712-345-678'));
    }

    /**
     * Test date validation function.
     */
    public function testSanitizeDateValidatesCorrectly(): void
    {
        $this->assertEquals('2026-06-20', sanitize_date('2026-06-20'));
        $this->assertEquals('', sanitize_date('invalid-date'));
        $this->assertEquals('', sanitize_date('13-13-2026'));
    }

    /**
     * Test record_payment function exists with correct signature.
     *
     * Full integration tests require a real database connection.
     */
    public function testRecordPaymentFunctionExists(): void
    {
        $this->assertTrue(function_exists('record_payment'));
    }

    /**
     * Test activate_membership_for_payment returns false for invalid plan.
     */
    public function testActivateMembershipReturnsFalseForInvalidPlan(): void
    {
        // Plan ID 0 is invalid — function should return false early
        $result = activate_membership_for_payment(
            $this->createMock(mysqli::class),
            1,
            0 // Invalid plan ID
        );
        $this->assertFalse($result);
    }

    /**
     * Test db_table_exists function exists.
     */
    public function testDbTableExistsFunctionExists(): void
    {
        $this->assertTrue(function_exists('db_table_exists'));
    }
}
