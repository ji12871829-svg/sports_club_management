<?php

/**
 * includes/redis_session.php — optional Redis-backed PHP session handler.
 *
 * Enabled ONLY when Redis is explicitly configured via env:
 *   REDIS_HOST      (default 127.0.0.1)
 *   REDIS_PORT      (default 6379)
 *   REDIS_PASSWORD  (optional)
 *   REDIS_SESSION_DB (optional, default 0)
 *   REDIS_SESSION_TTL (optional seconds; default = session.gc_maxlifetime)
 *
 * Requires nothing beyond PHP streams (tcp://) — a tiny RESP client speaks
 * enough of the protocol for GET/SET/DEL/PING. If Redis is not configured,
 * or the initial probe fails, the default (files) handler is left in place
 * so the app degrades gracefully with zero config.
 */

if (!class_exists('AscRedisSessionHandler')) {

    class AscRedisSessionHandler implements SessionHandlerInterface
    {
        /** @var string */
        private $host;
        /** @var int */
        private $port;
        /** @var string */
        private $password;
        /** @var int */
        private $db;
        /** @var int */
        private $ttl;
        /** @var resource|null */
        private $socket = null;

        public function __construct(array $opts)
        {
            $this->host = (string) ($opts['host'] ?? '127.0.0.1');
            $this->port = (int) ($opts['port'] ?? 6379);
            $this->password = (string) ($opts['password'] ?? '');
            $this->db = (int) ($opts['db'] ?? 0);
            $this->ttl = (int) ($opts['ttl'] ?? 1440);
            if ($this->ttl < 120) {
                $this->ttl = 120; // never allow a session to expire in < 2 min
            }
        }

        /**
         * Probe connectivity with a PING before the handler is installed so a
         * down Redis falls back to the files handler instead of breaking logins.
         */
        public function probe(): bool
        {
            if (!$this->connect()) {
                return false;
            }
            $reply = $this->cmd('PING', []);

            return $reply !== null && strtoupper((string) $reply) === 'PONG';
        }

        private function connect(): bool
        {
            if ($this->socket !== null) {
                return true;
            }
            $errno = 0;
            $errstr = '';
            $ctx = stream_context_create(['socket' => ['timeout' => 1]]);
            $socket = @stream_socket_client(
                'tcp://' . $this->host . ':' . $this->port,
                $errno,
                $errstr,
                1,
                STREAM_CLIENT_CONNECT,
                $ctx
            );
            if (!is_resource($socket)) {
                return false;
            }
            stream_set_timeout($socket, 1);
            $this->socket = $socket;

            if ($this->password !== '') {
                $this->cmd('AUTH', [$this->password]);
            }
            if ($this->db > 0) {
                $this->cmd('SELECT', [(string) $this->db]);
            }

            return true;
        }

        /** Send a RESP command and read the reply. Returns null on errors. */
        private function cmd(string $command, array $args): ?string
        {
            if (!$this->connect()) {
                return null;
            }
            $payload = '*' . (count($args) + 1) . "\r\n";
            $payload .= '$' . strlen($command) . "\r\n" . $command . "\r\n";
            foreach ($args as $arg) {
                $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
            }
            if (@fwrite($this->socket, $payload) === false) {
                return null;
            }

            return $this->readReply();
        }

        private function readReply(): ?string
        {
            $line = fgets($this->socket);
            if ($line === false || $line === '') {
                return null;
            }
            $type = $line[0];
            $body = trim(substr($line, 1));

            if ($type === '-') {
                return null; // error reply
            }
            if ($type === '+') {
                return $body;
            }
            if ($type === ':') {
                return $body;
            }
            if ($type === '$') {
                $len = (int) $body;
                if ($len < 0) {
                    return null; // nil bulk (missing key)
                }
                $data = '';
                while (strlen($data) < $len + 2) {
                    $chunk = fread($this->socket, $len + 2 - strlen($data));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $data .= $chunk;
                }

                return substr($data, 0, $len);
            }
            if ($type === '*') {
                $count = (int) $body;
                for ($i = 0; $i < $count; $i++) {
                    $this->readReply();
                }

                return 'OK'; // array reply — drained
            }

            return null;
        }

        private function key(string $id): string
        {
            return 'apex_sess:' . $id;
        }

        public function open($path, $name): bool
        {
            return true;
        }

        public function close(): bool
        {
            if ($this->socket !== null) {
                @fclose($this->socket);
                $this->socket = null;
            }

            return true;
        }

        public function read($id): string
        {
            $reply = $this->cmd('GET', [$this->key($id)]);

            return is_string($reply) ? $reply : '';
        }

        public function write($id, $data): bool
        {
            $this->cmd('SET', [$this->key($id), $data, 'EX', (string) $this->ttl]);

            return true;
        }

        public function destroy($id): bool
        {
            $this->cmd('DEL', [$this->key($id)]);

            return true;
        }

        public function gc($max_lifetime): int
        {
            // Expiry is enforced server-side via SET ... EX; nothing to sweep.
            return 0;
        }
    }
}

/**
 * Install the Redis session handler when configured and reachable. Returns
 * true when Redis sessions are active, false when falling back to files.
 * Safe to call multiple times.
 */
function redis_session_init(): bool
{
    static $initialised = false;
    static $active = false;
    if ($initialised) {
        return $active;
    }
    $initialised = true;

    $host = getenv('REDIS_HOST');
    if ($host === false || trim($host) === '') {
        return false; // not configured — files handler
    }

    $opts = [
        'host'     => trim($host),
        'port'     => (int) (getenv('REDIS_PORT') ?: 6379),
        'password' => (string) (getenv('REDIS_PASSWORD') ?: ''),
        'db'       => (int) (getenv('REDIS_SESSION_DB') ?: 0),
        'ttl'      => (int) (getenv('REDIS_SESSION_TTL') ?: ini_get('session.gc_maxlifetime')),
    ];

    $handler = new AscRedisSessionHandler($opts);
    if (!$handler->probe()) {
        error_log('[redis_session] configured but unreachable — falling back to files handler');
        return false;
    }

    session_set_save_handler($handler, true);
    $active = true;
    error_log('[redis_session] Redis session handler active on ' . $opts['host'] . ':' . $opts['port']);

    return true;
}
