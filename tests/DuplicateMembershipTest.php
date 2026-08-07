<?php
/**
 * DuplicateMembershipTest — exercises find_duplicate_memberships() in
 * includes/feature_helpers.php against the test DB.
 *
 * The detector flags members holding *overlapping* Active memberships for
 * the same plan (typically a double-activated payment or a manual data-entry
 * error). Sequential renewals (one period ending before the next starts),
 * NULL start/end dates, and different plans must NOT be flagged.
 *
 * Run: php phpunit.phar --configuration=phpunit.xml
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/feature_helpers.php';

class DuplicateMembershipTest extends TestCase
{
    private static mysqli $conn;
    private const TEST_MEMBER = 999002;
    private const TEST_PLAN_A = 99002;
    private const TEST_PLAN_B = 99003;

    public static function setUpBeforeClass(): void
    {
        self::$conn = getTestDb();

        // ── Ensure schema exists (mirrors the MpesaCallbackCycleTest setup) ──
        self::$conn->query("CREATE TABLE IF NOT EXISTS membership_plans (
            plan_id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            price DECIMAL(10,2) NOT NULL DEFAULT 0,
            duration_days INT NOT NULL DEFAULT 30,
            description TEXT,
            status VARCHAR(20) NOT NULL DEFAULT 'Active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

        // Seed the member (only the columns we know exist) and both plans
        $memberCheck = self::$conn->query("SELECT member_id FROM members WHERE member_id = " . self::TEST_MEMBER);
        if (!$memberCheck || $memberCheck->num_rows === 0) {
            $mcols = [];
            $r = self::$conn->query("SHOW COLUMNS FROM members");
            if ($r) {
                while ($row = $r->fetch_assoc()) {
                    $mcols[$row['Field']] = true;
                }
            }
            $fields = ['first_name' => "'Dup'", 'last_name' => "'Test'", 'email' => "'dup.test@example.com'"];
            if (isset($mcols['phone_number'])) $fields['phone_number'] = "'254700000002'";
            if (isset($mcols['password'])) $fields['password'] = "'" . password_hash('testpass123', PASSWORD_DEFAULT) . "'";
            self::$conn->query(
                "INSERT INTO members (member_id, " . implode(', ', array_keys($fields)) . ")
                 VALUES (" . self::TEST_MEMBER . ", " . implode(', ', array_values($fields)) . ")"
            );
        }
        foreach ([self::TEST_PLAN_A, self::TEST_PLAN_B] as $pid) {
            $planCheck = self::$conn->query("SELECT plan_id FROM membership_plans WHERE plan_id = " . $pid);
            if (!$planCheck || $planCheck->num_rows === 0) {
                self::$conn->query("INSERT INTO membership_plans (plan_id, name, price, duration_days, status)
                    VALUES (" . $pid . ", 'Dup Plan " . $pid . "', 1000.00, 30, 'Active')");
            }
        }
    }

    protected function setUp(): void
    {
        self::$conn->query("DELETE FROM member_memberships WHERE member_id = " . self::TEST_MEMBER);
    }

    private function insertMembership(string $planId, string $startDate, string $endDate, string $status = 'Active'): void
    {
        self::$conn->query(
            "INSERT INTO member_memberships (member_id, plan_id, start_date, end_date, status)
             VALUES (" . self::TEST_MEMBER . ", " . $planId . ", '" . $startDate . "', '" . $endDate . "', '" . $status . "')"
        );
    }

    private function duplicateRows(): array
    {
        return find_duplicate_memberships(self::$conn);
    }

    /**
     * Two Active memberships for the same member+plan with overlapping
     * periods must be flagged.
     */
    public function testOverlappingActiveMembershipsAreFlagged(): void
    {
        $this->insertMembership((string) self::TEST_PLAN_A, '2026-08-01', '2026-08-31');
        $this->insertMembership((string) self::TEST_PLAN_A, '2026-08-15', '2026-09-14');

        $dups = $this->duplicateRows();
        $this->assertCount(1, $dups, 'one member+plan group should be flagged');
        $this->assertEquals(self::TEST_MEMBER, (int) $dups[0]['member_id']);
        $this->assertEquals(self::TEST_PLAN_A, (int) $dups[0]['plan_id']);
        $this->assertEquals(2, (int) $dups[0]['overlap_count']);
    }

    /**
     * Sequential renewals (one period ends the day before the next starts)
     * are a normal renewal pattern and must NOT be flagged.
     */
    public function testSequentialRenewalsAreNotFlagged(): void
    {
        $this->insertMembership((string) self::TEST_PLAN_A, '2026-08-01', '2026-08-31');
        $this->insertMembership((string) self::TEST_PLAN_A, '2026-09-01', '2026-09-30');

        $this->assertCount(0, $this->duplicateRows(), 'sequential renewals must not be flagged');
    }

    /**
     * Memberships missing start/end dates cannot prove an overlap and must
     * not be flagged (NULL comparisons evaluate to false). The detector's
     * query handles NULL dates defensively for legacy/schema-drift rows,
     * but production migration 005 declares the columns NOT NULL, so this
     * case is only reachable if the schema allows it.
     */
    public function testNullDatesAreNotFlagged(): void
    {
        $col = self::$conn->query("SHOW COLUMNS FROM member_memberships LIKE 'start_date'")->fetch_assoc();
        if (strtoupper($col['Null'] ?? 'NO') !== 'YES') {
            $this->markTestSkipped('member_memberships.start_date is NOT NULL in this schema — NULL case not reachable');
        }

        self::$conn->query(
            "INSERT INTO member_memberships (member_id, plan_id, start_date, end_date, status)
             VALUES (" . self::TEST_MEMBER . ", " . self::TEST_PLAN_A . ", NULL, NULL, 'Active')"
        );
        self::$conn->query(
            "INSERT INTO member_memberships (member_id, plan_id, start_date, end_date, status)
             VALUES (" . self::TEST_MEMBER . ", " . self::TEST_PLAN_A . ", '2026-08-01', '2026-08-31', 'Active')"
        );

        $this->assertCount(0, $this->duplicateRows(), 'NULL-date memberships must not be flagged');
    }

    /**
     * Overlapping Active memberships on *different* plans are legitimate
     * (e.g. gym + swimming) and must not be flagged.
     */
    public function testDifferentPlansAreNotFlagged(): void
    {
        $this->insertMembership((string) self::TEST_PLAN_A, '2026-08-01', '2026-08-31');
        $this->insertMembership((string) self::TEST_PLAN_B, '2026-08-01', '2026-08-31');

        $this->assertCount(0, $this->duplicateRows(), 'different plans must not be flagged');
    }

    /**
     * An Inactive membership overlapping an Active one is not a duplicate
     * (only Active rows participate).
     */
    public function testInactiveMembershipIsNotFlagged(): void
    {
        $this->insertMembership((string) self::TEST_PLAN_A, '2026-08-01', '2026-08-31', 'Active');
        $this->insertMembership((string) self::TEST_PLAN_A, '2026-08-15', '2026-09-14', 'Cancelled');

        $this->assertCount(0, $this->duplicateRows(), 'inactive memberships must not be flagged');
    }

    /**
     * Overlaps in two different plans for the same member produce one
     * flagged group per plan.
     */
    public function testOverlapsAcrossTwoPlansProduceTwoGroups(): void
    {
        $this->insertMembership((string) self::TEST_PLAN_A, '2026-08-01', '2026-08-31');
        $this->insertMembership((string) self::TEST_PLAN_A, '2026-08-15', '2026-09-14');
        $this->insertMembership((string) self::TEST_PLAN_B, '2026-08-01', '2026-08-31');
        $this->insertMembership((string) self::TEST_PLAN_B, '2026-08-15', '2026-09-14');

        $dups = $this->duplicateRows();
        $this->assertCount(2, $dups, 'one flagged group per overlapping plan');
    }
}
