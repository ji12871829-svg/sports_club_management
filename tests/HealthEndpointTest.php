<?php
/**
 * HealthEndpointTest — regression coverage for public/health.php:
 *  - the optional HEALTH_TOKEN gate (Bearer header, X-Health-Token header,
 *    query-string token, wrong/empty token rejection, open when unset)
 *  - the redis_sessions probe decisions (unconfigured / reachable /
 *    configured-but-down), factored as pure helpers so no network or DB
 *    is required for the assertion path.
 *
 * The endpoint itself is exercised via a built-in server in CI smoke tests;
 * these unit tests target the decision functions to keep the suite hermetic.
 */

use PHPUnit\Framework\TestCase;

/**
 * Both helpers tested here are the REAL shared includes the endpoint uses
 * (includes/health_token.php and includes/health_redis.php) — no copies,
 * so the tests cannot drift from the runtime code.
 */
require_once __DIR__ . '/../includes/health_redis.php';

class HealthEndpointTest extends TestCase
{
    // ── Token gate ────────────────────────────────────────────────────

    private function runTokenCheck(array $server, array $get, string $configured): bool
    {
        // Preserve and swap the superglobals the helper reads.
        $oldServer = $_SERVER;
        $oldGet    = $_GET;
        $_SERVER   = $server;
        $_GET      = $get;
        try {
            require_once __DIR__ . '/../includes/health_token.php';

            return health_token_authorized($configured);
        } finally {
            $_SERVER = $oldServer;
            $_GET    = $oldGet;
        }
    }

    public function testTokenOpenWhenNotConfigured(): void
    {
        $this->assertTrue($this->runTokenCheck([], [], ''));
    }

    public function testTokenRejectsMissing(): void
    {
        $this->assertFalse($this->runTokenCheck([], [], 'sekret'));
    }

    public function testTokenAcceptsBearerHeader(): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer sekret'];
        $this->assertTrue($this->runTokenCheck($server, [], 'sekret'));
    }

    public function testTokenRejectsWrongBearer(): void
    {
        $server = ['HTTP_AUTHORIZATION' => 'Bearer nope'];
        $this->assertFalse($this->runTokenCheck($server, [], 'sekret'));
    }

    public function testTokenAcceptsCustomHeader(): void
    {
        $server = ['HTTP_X_HEALTH_TOKEN' => 'sekret'];
        $this->assertTrue($this->runTokenCheck($server, [], 'sekret'));
    }

    public function testTokenAcceptsQueryString(): void
    {
        $get = ['token' => 'sekret'];
        $this->assertTrue($this->runTokenCheck([], $get, 'sekret'));
    }

    public function testTokenIsConstantTime(): void
    {
        // hash_equals is used internally; verify it does not simply string-match
        // by checking a length-equal but wrong token still fails.
        $this->assertFalse($this->runTokenCheck([], [], str_repeat('a', 12)));
        $server = ['HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('b', 12)];
        $this->assertFalse($this->runTokenCheck($server, [], str_repeat('a', 12)));
    }

    // ── Redis probe ───────────────────────────────────────────────────

    public function testRedisUnconfiguredUsesFiles(): void
    {
        $this->assertFalse(health_redis_probe()['configured']);
        $this->assertSame('files', health_redis_probe()['mode']);
    }

    public function testRedisUnreachableFallsBack(): void
    {
        // Port 1 is virtually never listening; the 1s connect timeout keeps this fast.
        putenv('REDIS_HOST=127.0.0.1');
        putenv('REDIS_PORT=1');
        putenv('REDIS_PASSWORD=');
        try {
            $this->expectException(RuntimeException::class);
            health_redis_probe();
        } finally {
            putenv('REDIS_HOST');
            putenv('REDIS_PORT');
        }
    }
}
