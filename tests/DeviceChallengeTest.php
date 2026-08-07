<?php
/**
 * DeviceChallengeTest — regression coverage for the unknown-device email
 * challenge helpers in includes/session_config.php:
 *  - pending-state validity (fresh vs expired)
 *  - code verification (correct / wrong, hash_equals, one-shot)
 *  - challenge needed gating (no history, unseen device, 2FA skip)
 *  - fail-open behaviour when the email cannot be delivered
 *  - security-event telemetry on challenge issue
 */

use PHPUnit\Framework\TestCase;

/**
 * Test-local sendEmail stub. The real helper lazy-loads send_email.php only
 * when sendEmail is undefined, so defining it here keeps the tests hermetic
 * (no Brevo key needed). Return value is controlled per test via
 * $GLOBALS['__asc_test_email_ok']; the captured message is stashed in
 * $GLOBALS['__asc_test_last_email'].
 */
if (!function_exists('sendEmail')) {
    function sendEmail($toEmail, $toName, $subject, $htmlBody)
    {
        $GLOBALS['__asc_test_last_email'] = [
            'to'      => $toEmail,
            'subject' => $subject,
            'body'    => $htmlBody,
        ];

        return $GLOBALS['__asc_test_email_ok'] ?? true;
    }
}

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

class DeviceChallengeTest extends TestCase
{
    /** @var mysqli|null */
    private static $conn = null;

    public static function setUpBeforeClass(): void
    {
        self::$conn = getTestDb();
        // Same minimal schema as SessionSecurityTest, plus security_events
        // (migration 057) so challenge telemetry can be asserted.
        self::$conn->query("CREATE TABLE IF NOT EXISTS admins (
            admin_id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(120) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            totp_secret VARCHAR(64) NULL,
            totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
            totp_confirmed_at TIMESTAMP NULL,
            recovery_codes TEXT NULL,
            auth_epoch INT NOT NULL DEFAULT 0
        )");
        $sql = file_get_contents(__DIR__ . '/../migrations/063_create_admin_sessions.sql');
        if ($sql !== false) {
            self::$conn->multi_query($sql);
            while (self::$conn->next_result()) {
            }
        }
        self::$conn->query("CREATE TABLE IF NOT EXISTS security_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(40) NOT NULL,
            severity ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
            ip_address VARCHAR(45) NULL DEFAULT NULL,
            actor VARCHAR(120) NULL DEFAULT NULL,
            details VARCHAR(500) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sec_events_type_time (event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    protected function setUp(): void
    {
        unset($_SESSION['admin_device_pending']);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.30';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';
    }

    protected function tearDown(): void
    {
        unset($_SESSION['admin_device_pending']);
        unset($GLOBALS['__asc_test_last_email']);
        $GLOBALS['__asc_test_email_ok'] = true;
    }

    /** Create a throwaway admin; returns [adminId, email]. */
    private function makeAdmin(bool $totp = false): array
    {
        $conn = self::$conn;
        $email = 'devchallenge-' . bin2hex(random_bytes(4)) . '@apex.test';
        $hash = password_hash('TestPass!123', PASSWORD_DEFAULT);
        if ($totp) {
            $stmt = $conn->prepare('INSERT INTO admins (email, password, totp_secret, totp_enabled) VALUES (?, ?, ?, 1)');
            $secret = str_repeat('A', 32);
            $stmt->bind_param('sss', $email, $hash, $secret);
        } else {
            $stmt = $conn->prepare('INSERT INTO admins (email, password) VALUES (?, ?)');
            $stmt->bind_param('ss', $email, $hash);
        }
        $stmt->execute();
        $adminId = (int) $conn->insert_id;
        $stmt->close();

        return [$adminId, $email];
    }

    /** Give the admin a prior session row from a DIFFERENT device. */
    private function seedPriorSession(int $adminId, string $ip = '198.51.100.40', string $ua = 'Windows · Chrome'): void
    {
        $stmt = self::$conn->prepare(
            'INSERT INTO admin_sessions (admin_id, session_token, ip_address, user_agent)
             VALUES (?, ?, ?, ?)'
        );
        $otherToken = str_repeat('b', 64);
        $stmt->bind_param('isss', $adminId, $otherToken, $ip, $ua);
        $stmt->execute();
        $stmt->close();
    }

    private function cleanupAdmin(int $adminId): void
    {
        self::$conn->query('DELETE FROM admin_sessions WHERE admin_id = ' . $adminId);
        $stmt = self::$conn->prepare('DELETE FROM admins WHERE admin_id = ?');
        $stmt->bind_param('i', $adminId);
        $stmt->execute();
        $stmt->close();
    }

    // ---- Pending-state validity -----------------------------------------

    public function testPendingValidAcceptsFreshChallenge(): void
    {
        $_SESSION['admin_device_pending'] = [
            'admin_id'  => 1,
            'email'     => 'a@b.c',
            'code_hash' => hash('sha256', '123456'),
            'expires'   => time() + 600,
        ];
        $this->assertTrue(admin_device_pending_valid(), 'fresh pending challenge should be valid');
    }

    public function testPendingValidRejectsExpiredChallenge(): void
    {
        $_SESSION['admin_device_pending'] = [
            'admin_id'  => 1,
            'email'     => 'a@b.c',
            'code_hash' => hash('sha256', '123456'),
            'expires'   => time() - 1,
        ];
        $this->assertFalse(admin_device_pending_valid(), 'expired challenge must be invalid');
        $this->assertArrayNotHasKey('admin_device_pending', $_SESSION, 'expired pending should be cleared');
    }

    // ---- Code verification ----------------------------------------------

    public function testChallengeVerifyAcceptsCorrectCodeOnce(): void
    {
        $_SESSION['admin_device_pending'] = [
            'admin_id'  => 1,
            'email'     => 'a@b.c',
            'code_hash' => hash('sha256', '654321'),
            'expires'   => time() + 600,
        ];
        $this->assertTrue(admin_device_challenge_verify('654321'), 'correct code should verify');
        $this->assertArrayNotHasKey('admin_device_pending', $_SESSION, 'pending must be cleared on success');
    }

    public function testChallengeVerifyRejectsWrongCode(): void
    {
        $_SESSION['admin_device_pending'] = [
            'admin_id'  => 1,
            'email'     => 'a@b.c',
            'code_hash' => hash('sha256', '654321'),
            'expires'   => time() + 600,
        ];
        $this->assertFalse(admin_device_challenge_verify('000000'), 'wrong code must not verify');
        $this->assertArrayHasKey('admin_device_pending', $_SESSION, 'pending survives a wrong code');
    }

    // ---- Challenge gating -----------------------------------------------

    public function testChallengeNeededFalseWithoutPriorHistory(): void
    {
        [$adminId, $email] = $this->makeAdmin();
        try {
            $this->assertFalse(
                admin_device_challenge_needed(self::$conn, $adminId),
                'first-ever login must not challenge'
            );
        } finally {
            $this->cleanupAdmin($adminId);
        }
    }

    public function testChallengeNeededTrueForUnseenDevice(): void
    {
        [$adminId, $email] = $this->makeAdmin();
        $this->seedPriorSession($adminId, '198.51.100.40', 'Windows · Chrome');
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';
        try {
            $this->assertTrue(
                admin_device_challenge_needed(self::$conn, $adminId),
                'an unseen device with prior history must be challenged'
            );
        } finally {
            $this->cleanupAdmin($adminId);
        }
    }

    public function testChallengeNeededSkipsAccountsWith2fa(): void
    {
        [$adminId, $email] = $this->makeAdmin(true);
        $this->seedPriorSession($adminId);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';
        try {
            $this->assertFalse(
                admin_device_challenge_needed(self::$conn, $adminId),
                '2FA already guards the account — no extra challenge'
            );
        } finally {
            $this->cleanupAdmin($adminId);
        }
    }

    // ---- Challenge start: fail-open + happy path -------------------------

    public function testChallengeStartFailsOpenWhenEmailFails(): void
    {
        [$adminId, $email] = $this->makeAdmin();
        $this->seedPriorSession($adminId);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';
        $GLOBALS['__asc_test_email_ok'] = false; // delivery fails
        try {
            $this->assertFalse(
                admin_device_challenge_start(self::$conn, $adminId, $email),
                'undeliverable code must fail OPEN so the admin is not locked out'
            );
            $this->assertArrayNotHasKey(
                'admin_device_pending',
                $_SESSION,
                'no pending state may survive a failed delivery'
            );
        } finally {
            $this->cleanupAdmin($adminId);
        }
    }

    public function testChallengeStartIssuesChallengeAndVerifies(): void
    {
        [$adminId, $email] = $this->makeAdmin();
        $this->seedPriorSession($adminId);
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:128.0) Gecko/20100101 Firefox/128.0';
        try {
            $this->assertTrue(
                admin_device_challenge_start(self::$conn, $adminId, $email),
                'a deliverable challenge should start'
            );
            $this->assertTrue(admin_device_pending_valid(), 'pending challenge should be active');

            // The emailed code must verify, and the pending state must go.
            $body = $GLOBALS['__asc_test_last_email']['body'] ?? '';
            $this->assertSame($email, $GLOBALS['__asc_test_last_email']['to'] ?? '');
            $this->assertMatchesRegularExpression('/[0-9]{6}/', $body, 'email must contain a 6-digit code');
            preg_match('/>([0-9]{6})</', $body, $m);
            $this->assertNotEmpty($m[1] ?? '', 'code should be extractable from the email body');
            $this->assertTrue(admin_device_challenge_verify($m[1]), 'emailed code must verify');

            // Telemetry: the issued challenge is in the security-events register.
            $stmt = self::$conn->prepare(
                "SELECT COUNT(*) FROM security_events WHERE event_type = 'device_challenge_issued' AND actor = ?"
            );
            $actor = 'admin:' . $adminId;
            $stmt->bind_param('s', $actor);
            $stmt->execute();
            $stmt->bind_result($issued);
            $stmt->fetch();
            $stmt->close();
            $this->assertGreaterThanOrEqual(1, $issued, 'an issued challenge should be logged as a security event');
        } finally {
            self::$conn->query("DELETE FROM security_events WHERE actor = 'admin:" . $adminId . "'");
            $this->cleanupAdmin($adminId);
        }
    }
}
