<?php
// ============================================================
//  includes/cache.php
//  Lightweight caching layer with APCu, file fallback, and
//  Redis support when available.
// ============================================================

define('CACHE_DIR', sys_get_temp_dir() . '/apex_cache');
define('CACHE_DEFAULT_TTL', 300); // 5 minutes

class AscCache
{
    private static ?object $redis = null;
    private static ?bool $apcuAvailable = null;
    private static ?bool $redisAvailable = null;

    /**
     * Get cached value by key.
     */
    public static function get(string $key, $default = null)
    {
        $key = self::prefix($key);

        // Redis
        if (self::redisAvailable()) {
            $data = self::$redis->get($key);
            if ($data !== false) {
                return json_decode($data, true);
            }
            return $default;
        }

        // APCu
        if (self::apcuAvailable()) {
            $data = apcu_fetch($key, $success);
            if ($success) {
                return $data;
            }
            return $default;
        }

        // File fallback
        $file = self::fileKey($key);
        if (file_exists($file) && (time() - filemtime($file)) < self::getTTL($key)) {
            return json_decode(file_get_contents($file), true);
        }
        return $default;
    }

    /**
     * Store value in cache.
     */
    public static function set(string $key, $value, int $ttl = CACHE_DEFAULT_TTL): bool
    {
        $key = self::prefix($key);
        $data = json_encode($value);

        // Redis
        if (self::redisAvailable()) {
            return self::$redis->setex($key, $ttl, $data);
        }

        // APCu
        if (self::apcuAvailable()) {
            return apcu_store($key, $value, $ttl);
        }

        // File fallback
        $file = self::fileKey($key);
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $data, LOCK_EX);
        touch($file, time() + $ttl);
        return true;
    }

    /**
     * Get value or compute and store it.
     */
    public static function remember(string $key, int $ttl, callable $callback)
    {
        $value = self::get($key);
        if ($value !== null) {
            return $value;
        }
        $value = $callback();
        self::set($key, $value, $ttl);
        return $value;
    }

    /**
     * Delete a cached key.
     */
    public static function delete(string $key): bool
    {
        $key = self::prefix($key);

        if (self::redisAvailable()) {
            return self::$redis->del($key) > 0;
        }

        if (self::apcuAvailable()) {
            return apcu_delete($key);
        }

        $file = self::fileKey($key);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return false;
    }

    /**
     * Clear all cached data.
     */
    public static function flush(): bool
    {
        if (self::redisAvailable()) {
            return self::$redis->flushDB();
        }

        if (self::apcuAvailable()) {
            return apcu_clear_cache();
        }

        // Delete cache directory contents
        $cacheDir = CACHE_DIR;
        if (is_dir($cacheDir)) {
            $files = glob($cacheDir . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
        return true;
    }

    /**
     * Get value as JSON, returning the decoded object directly.
     */
    public static function getJson(string $key, $default = null)
    {
        return self::get($key, $default);
    }

    // ── Internal helpers ──────────────────────────────────────

    private static function prefix(string $key): string
    {
        return 'apex:' . $key;
    }

    private static function fileKey(string $key): string
    {
        return CACHE_DIR . '/' . md5($key) . '.json';
    }

    private static function getTTL(string $key): int
    {
        // Approximate TTL from file modification time
        return CACHE_DEFAULT_TTL;
    }

    private static function apcuAvailable(): bool
    {
        if (self::$apcuAvailable === null) {
            self::$apcuAvailable = function_exists('apcu_fetch') && apcu_enabled();
        }
        return self::$apcuAvailable;
    }

    private static function redisAvailable(): bool
    {
        if (self::$redisAvailable === null) {
            if (class_exists('Redis')) {
                try {
                    self::$redis = new Redis();
                    self::$redis->connect('127.0.0.1', 6379, 1.0);
                    self::$redisAvailable = true;
                } catch (Exception $e) {
                    self::$redisAvailable = false;
                }
            } else {
                self::$redisAvailable = false;
            }
        }
        return self::$redisAvailable;
    }
}

// ── Convenience functions ──────────────────────────────────

function cache_get(string $key, $default = null)
{
    return AscCache::get($key, $default);
}

function cache_set(string $key, $value, int $ttl = CACHE_DEFAULT_TTL): bool
{
    return AscCache::set($key, $value, $ttl);
}

function cache_remember(string $key, int $ttl, callable $callback)
{
    return AscCache::remember($key, $ttl, $callback);
}

function cache_delete(string $key): bool
{
    return AscCache::delete($key);
}

function cache_flush(): bool
{
    return AscCache::flush();
}
