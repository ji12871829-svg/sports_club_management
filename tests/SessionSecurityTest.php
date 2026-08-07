<?php
/**
 * SessionSecurityTest — regression coverage for the admin session layer:
 *  - session helpers (record / list / revoke / is_revoked / token stability)
 *  - device label + geo-hint helpers (pure functions)
 *  - login rate-limiter retry_after math (lockout countdown source)
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

class SessionSecurityTest extends TestCase
{
    /** @var mysqli|null */
    private static $conn = null;

    public static function setUpBeforeClass(): void
    {
        self::$conn = getTestDb();
        // Minimal schema (mirrors other DB-backed tests; full migrations run in CI).
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
        // admin_sessions from migration 063.
        $sql = file_get_contents(__DIR__ . '/../migrations/063_create_admin_sessions.sql');
        if ($sql !== false) {
            self::$conn->multi_query($sql);
            while (self::$conn->next_result()) {
            }
        }
        // login_attempts (rate limiter auto-creates too, but be explicit).
        self::$conn->query("CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            action_type VARCHAR(20) NOT NULL DEFAULT 'login',
            INDEX idx_email_time (email, attempted_at),
            INDEX idx_ip_time (ip_address, attempted_at)
        )");
    }

    // ---- Pure helpers ---------------------------------------------------

    public function testAdminSessionTokenIsSha256(): void
    {
        $token = admin_session_token();
        $this->assertSame(64, strlen($token), 'token should be a 64-char sha256 hex');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $token, 'token should be lowercase hex');
    }

    public function testUaLabelDetectsBrowserAndOs(): void
    {
        $this->assertSame('Windows · Chrome', admin_session_ua_label('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0 Safari/537.36'));
        $this->assertSame('Android · Firefox', admin_session_ua_label('Mozilla/5.0 (Android 13; Mobile; rv:121.0) Gecko/121.0 Firefox/121.0'));
        $this->assertSame('Unknown device', admin_session_ua_label(''));
    }

    public function testGeoHintClassifiesLocalRanges(): void
    {
        $this->assertSame('Local network', admin_session_geo_hint('192.168.1.10'));
        $this->assertSame('Local network', admin_session_geo_hint('10.0.0.5'));
        $this->assertSame('Local network', admin_session_geo_hint('127.0.0.1'));
        $this->assertSame('Local network', admin_session_geo_hint('::1'));
        $this->assertSame('Unknown', admin_session_geo_hint(''));
    }

    public function testGeoHintFallsBackGracefullyForPublicIp(): void
    {
        // No mmdb bundled in the test env → the call must not throw and must
        // return a non-empty string (dash, country name, or similar).
        $hint = admin_session_geo_hint('8.8.8.8', '/nonexistent/GeoLite2-Country.mmdb');
        $this->assertNotEmpty($hint, 'public IP should resolve or degrade to a non-empty value');
    }

    public function testTimeAgoAndAgeHelpers(): void
    {
        $this->assertSame('just now', admin_session_time_ago(date('Y-m-d H:i:s')));
        $this->assertStringContainsString('min', admin_session_time_ago(date('Y-m-d H:i:s', time() - 300)));
        $this->assertSame('1 min', admin_session_age(date('Y-m-d H:i:s', time() - 60)));
        $this->assertSame('—', admin_session_time_ago(''));
    }

    // ---- DB-backed session helpers --------------------------------------

    public function testSessionRecordListAndRevoke(): void
    {
        $conn = self::$conn;

        // Use a throwaway admin so the real admin row is untouched.
        $email = 'sess-test-' . bin2hex(random_bytes(4)) . '@apex.test';
        $hash = password_hash('TestPass!123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO admins (email, password) VALUES (?, ?)');
        $stmt->bind_param('ss', $email, $hash);
        $stmt->execute();
        $adminId = (int) $conn->insert_id;
        $stmt->close();

        try {
            // Headers may already be sent by the runner, so we cannot swap
            // session_id(). Insert the current session row directly with the
            // current token instead.
            $token = admin_session_token();
            $stmt = $conn->prepare(
                "INSERT INTO admin_sessions (admin_id, session_token, ip_address, user_agent)
                 VALUES (?, ?, '198.51.100.7', 'TestBrowser/1.0')
                 ON DUPLICATE KEY UPDATE last_activity = CURRENT_TIMESTAMP"
            );
            $stmt->bind_param('is', $adminId, $token);
            $stmt->execute();
            $stmt->close();

            $rows = admin_sessions_list($conn, $adminId);
            $this->assertCount(1, $rows, 'one session should be listed');
            $this->assertNotEmpty($rows[0]['is_current'], 'the recorded session is the current one');
            $this->assertSame(64, strlen($rows[0]['session_token']));

            // Revoke it and confirm the guard would force a logout.
            $this->assertTrue(admin_sessions_revoke($conn, $adminId, (int) $rows[0]['id']), 'revoke should succeed');
            $this->assertTrue(admin_sessions_is_revoked($conn, $adminId), 'revoked session should be detected');
            $this->assertCount(0, admin_sessions_list($conn, $adminId), 'revoked sessions are hidden from the panel');
        } finally {
            $conn->query('DELETE FROM admin_sessions WHERE admin_id = ' . $adminId);
            $stmt = $conn->prepare('DELETE FROM admins WHERE admin_id = ?');
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function testNewDeviceDetectionIgnoresFirstLogin(): void
    {
        $conn = self::$conn;
        $email = 'sess-newdev-' . bin2hex(random_bytes(4)) . '@apex.test';
        $hash = password_hash('TestPass!123', PASSWORD_DEFAULT);
        $stmt = $conn->prepare('INSERT INTO admins (email, password) VALUES (?, ?)');
        $stmt->bind_param('ss', $email, $hash);
        $stmt->execute();
        $adminId = (int) $conn->insert_id;
        $stmt->close();

        try {
            // No prior history → not a "new device" alert case (only the
            // current session exists, so admin_sessions_is_new_device must
            // stay false).
            $token = admin_session_token();
            $stmt = $conn->prepare(
                "INSERT INTO admin_sessions (admin_id, session_token, ip_address, user_agent)
                 VALUES (?, ?, '198.51.100.8', 'TestBrowser/1.0')
                 ON DUPLICATE KEY UPDATE last_activity = CURRENT_TIMESTAMP"
            );
            $stmt->bind_param('is', $adminId, $token);
            $stmt->execute();
            $stmt->close();
            $this->assertFalse(
                admin_sessions_is_new_device($conn, $adminId),
                'first-ever recorded session must not trigger the alert'
            );

            // Add a second session from a DIFFERENT device (different token +
            // UA) → the current device is now "unseen" and should be flagged.
            $stmt = $conn->prepare(
                "INSERT INTO admin_sessions (admin_id, session_token, ip_address, user_agent)
                 VALUES (?, ?, '198.51.100.9', 'SomeOtherBrowser/1.0')"
            );
            $otherToken = str_repeat('a', 64);
            $stmt->bind_param('is', $adminId, $otherToken);
            $stmt->execute();
            $stmt->close();
            $this->assertTrue(
                admin_sessions_is_new_device($conn, $adminId),
                'a second, unseen device should be flagged'
            );
        } finally {
            $conn->query('DELETE FROM admin_sessions WHERE admin_id = ' . $adminId);
            $stmt = $conn->prepare('DELETE FROM admins WHERE admin_id = ?');
            $stmt->bind_param('i', $adminId);
            $stmt->execute();
            $stmt->close();
        }
    }

    // ---- Rate limiter retry_after ---------------------------------------

    public function testRetryAfterIsWithinWindowWhenLocked(): void
    {
        $conn = self::$conn;
        $bucket = 'sess-lock-' . bin2hex(random_bytes(4)) . '@apex.test';
        for ($i = 0; $i < 5; $i++) {
            $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, '203.0.113.9')");
            $stmt->bind_param('s', $bucket);
            $stmt->execute();
            $stmt->close();
        }

        try {
            $rate = check_login_attempts($conn, $bucket);
            $this->assertFalse($rate['allowed'], '5 attempts should lock out');
            $this->assertArrayHasKey('retry_after', $rate, 'lockout should include retry_after');
            $this->assertGreaterThanOrEqual(1, $rate['retry_after']);
            $this->assertLessThanOrEqual(900, $rate['retry_after'], 'retry_after cannot exceed the 15-min window');
        } finally {
            $stmt = $conn->prepare('DELETE FROM login_attempts WHERE email = ?');
            $stmt->bind_param('s', $bucket);
            $stmt->execute();
            $stmt->close();
        }
    }

    public function testAllowedBeneathThreshold(): void
    {
        $conn = self::$conn;
        $bucket = 'sess-ok-' . bin2hex(random_bytes(4)) . '@apex.test';
        $stmt = $conn->prepare("INSERT INTO login_attempts (email, ip_address) VALUES (?, '203.0.113.10')");
        $stmt->bind_param('s', $bucket);
        $stmt->execute();
        $stmt->close();

        try {
            $rate = check_login_attempts($conn, $bucket);
            $this->assertTrue($rate['allowed'], 'single attempt should still be allowed');
            $this->assertSame(4, $rate['remaining'], '5 - 1 = 4 remaining');
        } finally {
            $stmt = $conn->prepare('DELETE FROM login_attempts WHERE email = ?');
            $stmt->bind_param('s', $bucket);
            $stmt->execute();
            $stmt->close();
        }
    }
}
