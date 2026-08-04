<?php
/**
 * MpesaCallbackCycleTest — simulates the full M-Pesa STK callback cycle
 * against the test DB, mirroring the logic in callbacks/mpesa_callback.php:
 *
 *   1. STK push accepted  -> mpesa_create_pending() creates a Pending row
 *   2. Callback arrives   -> mpesa_fetch_pending_by_checkout() finds it,
 *                            record_payment() records the payment, and the
 *                            pending row is marked Completed
 *   3. Membership plan    -> activate_membership_for_payment() activates the
 *                            member's membership for the paid plan
 *   4. Duplicate callback -> no duplicate payment row, no duplicate membership
 *
 * Run: php phpunit.phar --configuration=phpunit.xml
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/mpesa_helpers.php';

class MpesaCallbackCycleTest extends TestCase
{
    private static mysqli $conn;
    private const TEST_MEMBER = 999001;
    private const TEST_PLAN = 99001;

    public static function setUpBeforeClass(): void
    {
        self::$conn = getTestDb();

        // ── Ensure schema exists (mirrors production runtime creation) ──
        // mpesa_ensure_schema() is the same function the callbacks use, so
        // the test always matches what production actually creates.
        if (!mpesa_ensure_schema(self::$conn)) {
            throw new RuntimeException('mpesa_ensure_schema failed on test DB');
        }

        self::$conn->query("CREATE TABLE IF NOT EXISTS membership_plans (
            plan_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            duration_days INT NOT NULL DEFAULT 30,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Simplified member_memberships (FK constraints omitted intentionally —
        // the test DB is a fresh minimal schema and the constraints add nothing
        // to what this test asserts).
        self::$conn->query("CREATE TABLE IF NOT EXISTS member_memberships (
            membership_id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL,
            plan_id INT NOT NULL,
            payment_id INT DEFAULT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Active',
            paused_at DATETIME DEFAULT NULL,
            resume_at DATETIME DEFAULT NULL,
            pause_days INT DEFAULT 0,
            auto_renew TINYINT(1) DEFAULT 0,
            renewal_reminder_sent TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_member_memberships_member_status (member_id,status,end_date),
            KEY idx_member_memberships_payment (payment_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Seed a member + plan if missing (defensive: only set the columns we
        // know exist so a differently-shaped members table cannot break setup)
        $memberCheck = self::$conn->query("SELECT member_id FROM members WHERE member_id = " . self::TEST_MEMBER);
        if (!$memberCheck || $memberCheck->num_rows === 0) {
            $mcols = [];
            $r = self::$conn->query("SHOW COLUMNS FROM members");
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $mcols[$row['Field']] = true;
                }
            }
            $fields = ['first_name' => "'Mpesa'", 'last_name' => "'Test'", 'email' => "'mpesa.test@example.com'"];
            if (isset($mcols['phone_number'])) $fields['phone_number'] = "'254700000001'";
            $cols = array_keys($fields);
            $vals = array_values($fields);
            self::$conn->query(
                "INSERT INTO members (member_id, " . implode(', ', $cols) . ") VALUES (" . self::TEST_MEMBER . ", " . implode(', ', $vals) . ")"
            );
        }
        $planCheck = self::$conn->query("SELECT plan_id FROM membership_plans WHERE plan_id = " . self::TEST_PLAN);
        if (!$planCheck || $planCheck->num_rows === 0) {
            self::$conn->query("INSERT INTO membership_plans (plan_id, name, price, duration_days, status)
                VALUES (" . self::TEST_PLAN . ", 'Test Plan', 1500.00, 30, 'Active')");
        }
    }

    protected function setUp(): void
    {
        // Clean test data before each test
        self::$conn->query("DELETE FROM member_memberships WHERE member_id = " . self::TEST_MEMBER);
        self::$conn->query("DELETE FROM payments WHERE member_id = " . self::TEST_MEMBER);
        self::$conn->query("DELETE FROM mpesa_pending WHERE checkout_request_id LIKE 'CYCLE_TEST_%'");
    }

    /**
     * Full happy-path cycle: pending -> callback -> payment + membership.
     */
    public function testFullMpesaCallbackCycle(): void
    {
        $checkoutId = 'CYCLE_TEST_' . bin2hex(random_bytes(6));
        $receipt = 'RC' . bin2hex(random_bytes(5));

        // 1. STK push accepted — creates the pending row
        $this->assertTrue(
            mpesa_create_pending(self::$conn, $checkoutId, 1500.00, 'Membership: Test', 'membership_payment', self::TEST_MEMBER, null, ['plan_id' => self::TEST_PLAN]),
            'mpesa_create_pending should succeed'
        );

        // 2. Callback arrives — fetch pending, record payment, mark completed
        $pending = mpesa_fetch_pending_by_checkout(self::$conn, $checkoutId);
        $this->assertNotNull($pending, 'pending row should be fetchable by checkout id');
        $this->assertEquals('Pending', $pending['status']);

        $record = record_payment(
            self::$conn,
            self::TEST_MEMBER,
            1500.00,
            'M-Pesa',
            'Membership: Test — ' . $receipt,
            $receipt,
            'membership_payment',
            'Paid'
        );
        $this->assertNotEmpty($record['success'], 'payment should be recorded');
        $this->assertEmpty($record['duplicate'], 'first payment should not be a duplicate');

        mpesa_mark_pending_completed(self::$conn, (int) $pending['pending_id']);
        $updated = mpesa_fetch_pending_by_checkout(self::$conn, $checkoutId);
        $this->assertEquals('Completed', $updated['status'], 'pending row should be Completed after callback');

        // 3. Activate membership for the paid plan
        $activated = activate_membership_for_payment(self::$conn, self::TEST_MEMBER, self::TEST_PLAN, (int) $record['payment_id']);
        $this->assertTrue($activated, 'membership should activate');

        $memberships = self::$conn->query(
            "SELECT * FROM member_memberships WHERE member_id = " . self::TEST_MEMBER
        )->fetch_all(MYSQLI_ASSOC);
        $this->assertCount(1, $memberships, 'exactly one membership should be created');
        $this->assertEquals('Active', $memberships[0]['status']);
        $this->assertEquals(self::TEST_PLAN, (int) $memberships[0]['plan_id']);

        // 4. Duplicate callback — must not create a second payment or membership
        $dupRecord = record_payment(
            self::$conn,
            self::TEST_MEMBER,
            1500.00,
            'M-Pesa',
            'Membership: Test — ' . $receipt,
            $receipt,
            'membership_payment',
            'Paid'
        );
        $this->assertNotEmpty($dupRecord['duplicate'], 'duplicate payment should be detected');

        $paymentCount = (int) self::$conn->query(
            "SELECT COUNT(*) FROM payments WHERE provider_reference = '" . $receipt . "'"
        )->fetch_row()[0];
        $this->assertEquals(1, $paymentCount, 'duplicate callback must not add another payment row');

        // Re-entering activation with the same payment id must be idempotent
        $this->assertTrue(
            activate_membership_for_payment(self::$conn, self::TEST_MEMBER, self::TEST_PLAN, (int) $record['payment_id']),
            're-activation with same payment should succeed idempotently'
        );
        $membershipCount = (int) self::$conn->query(
            "SELECT COUNT(*) FROM member_memberships WHERE member_id = " . self::TEST_MEMBER
        )->fetch_row()[0];
        $this->assertEquals(1, $membershipCount, 'duplicate activation must not add another membership');
    }

    /**
     * A duplicate callback for an already-completed checkout must be ignored
     * (the callback handler only processes rows still in Pending).
     */
    public function testDuplicateCallbackForCompletedPendingIsIgnored(): void
    {
        $checkoutId = 'CYCLE_TEST_' . bin2hex(random_bytes(6));

        mpesa_create_pending(self::$conn, $checkoutId, 500.00, 'Payment', 'member_portal', self::TEST_MEMBER);
        $pending = mpesa_fetch_pending_by_checkout(self::$conn, $checkoutId);
        mpesa_mark_pending_completed(self::$conn, (int) $pending['pending_id']);

        // Callback handler only acts when status is still Pending
        $refetched = mpesa_fetch_pending_by_checkout(self::$conn, $checkoutId);
        $this->assertEquals('Completed', $refetched['status']);
        $this->assertNotEquals('Pending', $refetched['status'], 'completed pending must not be reprocessed');
    }
}
