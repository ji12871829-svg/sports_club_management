<?php

/**
 * includes/health_redis.php — Redis session-store status probe for
 * public/health.php's redis_sessions check.
 *
 * Shared with tests so the unit tests exercise the exact same logic the
 * endpoint runs (no mirrored copies that can drift).
 *
 * Returns one of:
 *   ['configured' => false, 'mode' => 'files']              — REDIS_HOST unset
 *   ['configured' => true,  'mode' => 'redis', ...]          — reachable + PONG
 *   ['configured' => true,  'mode' => 'files-fallback', 'reachable' => false]
 *                                                             — configured but down
 * Throws RuntimeException when configured but unreachable, mirroring how the
 * endpoint's check wrapper turns that into a fail status.
 */
function health_redis_probe(): array
{
    $host = getenv('REDIS_HOST');
    if ($host === false || trim($host) === '') {
        return ['configured' => false, 'mode' => 'files'];
    }
    $port     = (int) (getenv('REDIS_PORT') ?: 6379);
    $password = (string) (getenv('REDIS_PASSWORD') ?: '');

    $errno = 0;
    $errstr = '';
    $ctx = stream_context_create(['socket' => ['timeout' => 1]]);
    $socket = @stream_socket_client('tcp://' . trim($host) . ':' . $port, $errno, $errstr, 1, STREAM_CLIENT_CONNECT, $ctx);
    if (!is_resource($socket)) {
        throw new \RuntimeException('Redis configured but unreachable at ' . trim($host) . ':' . $port);
    }
    stream_set_timeout($socket, 1);
    if ($password !== '') {
        $authCmd = "*2\r\n$4\r\nAUTH\r\n$" . strlen($password) . "\r\n" . $password . "\r\n";
        @fwrite($socket, $authCmd);
        @fgets($socket); // consume AUTH reply; an error surfaces on the PING below
    }
    @fwrite($socket, "*1\r\n$4\r\nPING\r\n");
    $reply = @fgets($socket);
    @fclose($socket);
    if ($reply === false || stripos((string) $reply, 'PONG') === false) {
        throw new \RuntimeException('Redis PING failed' . ($reply !== false ? ': ' . trim((string) $reply) : ''));
    }

    return [
        'configured' => true,
        'reachable'  => true,
        'mode'       => 'redis',
    ];
}
