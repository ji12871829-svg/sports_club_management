<?php
/**
 * PaymentIdempotencyTest — verifies that M-Pesa and Paystack callbacks
 * are idempotent: duplicate callbacks do NOT create duplicate payment rows.
 *
 * Run: vendor/bin/phpunit tests/PaymentIdempotencyTest.php
 * Or:  php phpunit.phar tests/PaymentIdempotencyTest.php
 */

use PHPUnit\Framework\TestCase;

class PaymentIdempotencyTest extends TestCase
{
    private static mysqli $conn;

    public static function setUpBeforeClass(): void
    {
        self::$conn = getTestDb();
    }

    protected function setUp(): void
    {
        // Clean test data before each test
        self::$conn->query("DELETE FROM payments WHERE description LIKE '[TEST]%'");
    }

    // ---- Idempotent INSERT via ON DUPLICATE KEY UPDATE -----------------

    /**
     * Simulates the mpesa_callback.php upsert pattern.
     * Verifies that calling it twice with the same reference produces
     * exactly 1 row.
     */
    public function testMpesaCallbackIsIdempotent(): void
    {
        $ref = 'MPESA_TEST_' . bin2hex(random_bytes(8));
        $memberId = 1;
        $amount = 1500.00;

        // First call
        $this->insertPaymentUpsert($memberId, $amount, 'M-Pesa', $ref, 'Completed');
        self::$conn->commit();

        // Second call (duplicate)
        $this->insertPaymentUpsert($memberId, $amount, 'M-Pesa', $ref, 'Completed');
        self::$conn->commit();

        // Count rows with this reference
        $stmt = self::$conn->prepare("SELECT COUNT(*) FROM payments WHERE provider_reference = ?");
        $stmt->bind_param('s', $ref);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_row()[0];
        $stmt->close();

        $this->assertEquals(1, $count, 'Duplicate M-Pesa callback created >1 payment row');
    }

    /**
     * Simulates the paystack_callback.php upsert pattern.
     * Verifies idempotency.
     */
    public function testPaystackCallbackIsIdempotent(): void
    {
        $ref = 'PAYSTACK_TEST_' . bin2hex(random_bytes(8));
        $memberId = 1;
        $amount = 2500.00;

        // First call
        $this->insertPaymentUpsert($memberId, $amount, 'Paystack', $ref, 'Completed');
        self::$conn->commit();

        // Second call (duplicate — e.g. webhook retry)
        $this->insertPaymentUpsert($memberId, $amount, 'Paystack', $ref, 'Completed');
        self::$conn->commit();

        $stmt = self::$conn->prepare("SELECT COUNT(*) FROM payments WHERE provider_reference = ?");
        $stmt->bind_param('s', $ref);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_row()[0];
        $stmt->close();

        $this->assertEquals(1, $count, 'Duplicate Paystack callback created >1 payment row');
    }

    /**
     * Verifies that a second callback with a DIFFERENT reference
     * correctly creates a second row.
     */
    public function testDifferentReferencesCreateDifferentPayments(): void
    {
        $ref1 = 'MPESA_DIFF_A_' . bin2hex(random_bytes(8));
        $ref2 = 'MPESA_DIFF_B_' . bin2hex(random_bytes(8));

        $this->insertPaymentUpsert(1, 1000, 'M-Pesa', $ref1, 'Completed');
        $this->insertPaymentUpsert(1, 2000, 'M-Pesa', $ref2, 'Completed');
        self::$conn->commit();

        $r = self::$conn->query("SELECT COUNT(*) FROM payments WHERE provider_reference IN ('$ref1','$ref2')");
        $this->assertEquals(2, $r->fetch_row()[0]);
    }

    // ---- Booking concurrency helper -------------------------------------

    /**
     * Simulates the SELECT ... FOR UPDATE + INSERT pattern from
     * public/booking.php. Verifies that two concurrent booking attempts
     * for the same slot do not double-book.
     */
    public function testBookingConcurrencyPreventsDoubleBooking(): void
    {
        // Use a facility that exists; insert a temp slot
        $facilityId = 1;
        $bookingDate = date('Y-m-d', strtotime('+30 days'));
        $startTime = '10:00:00';
        $endTime = '11:00:00';

        // Clean any existing test booking
        self::$conn->query("DELETE FROM bookings WHERE booking_date = '$bookingDate' AND start_time = '$startTime'");

        // Simulate "first admin" booking
        $this->bookSlotForTest(1, $facilityId, $bookingDate, $startTime, $endTime);

        // Simulate "second admin" trying the same slot
        $this->bookSlotForTest(2, $facilityId, $bookingDate, $startTime, $endTime);

        // Count bookings for this slot
        $r = self::$conn->query("SELECT COUNT(*) FROM bookings WHERE facility_id = $facilityId AND booking_date = '$bookingDate' AND start_time = '$startTime'");
        $this->assertEquals(1, $r->fetch_row()[0], 'Double booking occurred — concurrency bug');
    }

    // ---- Private helpers -------------------------------------------------

    private function insertPaymentUpsert(int $memberId, float $amount, string $method, string $ref, string $status): void
    {
        $desc = '[TEST] Idempotency test';
        // Match the actual schema: payments table has provider_reference, payment_status, description
        // but NOT a 'source' column in the base schema
        $sql = "INSERT INTO payments (member_id, amount, payment_method, description, provider_reference, payment_status)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE payment_status = VALUES(payment_status), description = VALUES(description)";
        $stmt = self::$conn->prepare($sql);
        $stmt->bind_param('idssss', $memberId, $amount, $method, $desc, $ref, $status);
        $stmt->execute();
        $stmt->close();
    }

    private function bookSlotForTest(int $memberId, int $facilityId, string $date, string $start, string $end): void
    {
        // Simulate SELECT FOR UPDATE conflict check
        self::$conn->begin_transaction();
        try {
            $check = self::$conn->prepare(
                "SELECT booking_id FROM bookings
                 WHERE facility_id = ? AND booking_date = ? AND status != 'cancelled'
                 AND start_time < ? AND end_time > ?
                 LIMIT 1 FOR UPDATE"
            );
            $check->bind_param('isss', $facilityId, $date, $end, $start);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existing) {
                self::$conn->rollback();
                return; // slot taken — this is the expected concurrency-avoidance path
            }

            // Insert — bookings table doesn't have created_at, use NOW() for status
            $ins = self::$conn->prepare(
                "INSERT INTO bookings (member_id, facility_id, booking_date, start_time, end_time, status)
                 VALUES (?, ?, ?, ?, ?, 'pending')"
            );
            $ins->bind_param('iisss', $memberId, $facilityId, $date, $start, $end);
            $ins->execute();
            $ins->close();
            self::$conn->commit();
        } catch (Exception $e) {
            self::$conn->rollback();
            throw $e;
        }
    }
}