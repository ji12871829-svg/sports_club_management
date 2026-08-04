<?php
/**
 * includes/object_storage.php
 * Object storage abstraction with two drivers:
 *
 *   local  (default) — files on the local filesystem under uploads/
 *   s3     (optional) — any S3-compatible object store (AWS S3, Cloudflare R2,
 *                       MinIO, DigitalOcean Spaces…) using AWS Signature V4
 *                       via cURL — no SDK required.
 *
 * Object keys use the same relative paths the app already stores in the DB
 * (e.g. "uploads/images/profile/xxx.jpg"), so nothing else in the app
 * changes: process_uploadedImage() still returns the same string, and the
 * rendering helpers resolve it through asc_storage_url().
 *
 * Configure in .env:
 *   ASC_STORAGE_DRIVER=local|s3
 *   ASC_S3_ENDPOINT=https://<bucket-endpoint>       (required for s3)
 *   ASC_S3_REGION=us-east-1                          (default us-east-1)
 *   ASC_S3_BUCKET=<bucket>                           (required for s3)
 *   ASC_S3_ACCESS_KEY=…                              (required for s3)
 *   ASC_S3_SECRET_KEY=…                              (required for s3)
 *   ASC_S3_PUBLIC_URL=https://cdn.example.com        (optional public URL)
 */

require_once __DIR__ . '/../config/api_config.php';

if (!class_exists('AscStorage', false)) {
    class AscStorage
    {
        /** Return the configured driver: 'local' or 's3'. */
        public static function driver(): string
        {
            $d = strtolower(trim((string) config_value('ASC_STORAGE_DRIVER', 'local')));
            return $d === 's3' ? 's3' : 'local';
        }

        public static function isS3(): bool
        {
            return self::driver() === 's3';
        }

        /** Upload a local file to storage under $key. Returns true on success. */
        public static function put(string $key, string $localFile, ?string $contentType = null): bool
        {
            $key = self::sanitizeKey($key);
            if ($key === '' || !is_file($localFile)) {
                return false;
            }

            if (!self::isS3()) {
                $dest = self::localPath($key);
                $dir  = dirname($dest);
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                    return false;
                }
                return copy($localFile, $dest);
            }

            $body = (string) file_get_contents($localFile);
            return self::s3Request('PUT', $key, $body, $contentType);
        }

        /** Write a raw string to storage under $key (handy for small files). */
        public static function putString(string $key, string $contents, ?string $contentType = null): bool
        {
            $key = self::sanitizeKey($key);
            if ($key === '') {
                return false;
            }
            if (!self::isS3()) {
                $dest = self::localPath($key);
                $dir  = dirname($dest);
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                    return false;
                }
                return file_put_contents($dest, $contents) !== false;
            }
            return self::s3Request('PUT', $key, $contents, $contentType);
        }

        /** Delete an object. Returns true when gone (or never existed). */
        public static function delete(string $key): bool
        {
            $key = self::sanitizeKey($key);
            if ($key === '') {
                return false;
            }
            if (!self::isS3()) {
                $dest = self::localPath($key);
                return (!is_file($dest)) || unlink($dest);
            }
            return self::s3Request('DELETE', $key);
        }

        /** Check whether an object exists. */
        public static function exists(string $key): bool
        {
            $key = self::sanitizeKey($key);
            if ($key === '') {
                return false;
            }
            if (!self::isS3()) {
                return is_file(self::localPath($key));
            }
            return self::s3Request('HEAD', $key);
        }

        /** Public URL for an object (BASE_URL-relative for local, absolute for s3). */
        public static function url(string $key): string
        {
            $key = self::sanitizeKey($key);
            if (!self::isS3()) {
                $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
                return $base . '/' . ltrim($key, '/');
            }

            $public = rtrim((string) config_value('ASC_S3_PUBLIC_URL', ''), '/');
            if ($public !== '') {
                return $public . '/' . ltrim($key, '/');
            }
            return rtrim(self::s3Endpoint(), '/') . '/' . self::s3Bucket() . '/' . ltrim($key, '/');
        }

        /** Local filesystem path for a key (used by the local driver). */
        public static function localPath(string $key): string
        {
            return dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $key), '/');
        }

        /**
         * Normalize a key: backslashes → slashes, strip leading slashes,
         * reject traversal. Returns '' when unsafe.
         */
        public static function sanitizeKey(string $key): string
        {
            $key = str_replace('\\', '/', trim($key));
            $key = ltrim($key, '/');
            if ($key === '' || $key === '.' || $key === '..') {
                return '';
            }
            // Reject any path segment that escapes the store root.
            foreach (explode('/', $key) as $seg) {
                if ($seg === '..') {
                    return '';
                }
            }
            return $key;
        }

        /** Self-test: verifies the configured driver actually works. */
        public static function test(): array
        {
            if (!self::isS3()) {
                $dir = dirname(__DIR__) . '/uploads';
                if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
                    return ['ok' => false, 'driver' => 'local', 'message' => 'Cannot create uploads/ directory.'];
                }
                return ['ok' => true, 'driver' => 'local', 'message' => 'Local filesystem ready (uploads/ writable).'];
            }

            if (self::s3Endpoint() === '' || self::s3Bucket() === '') {
                return ['ok' => false, 'driver' => 's3', 'message' => 'ASC_S3_ENDPOINT and ASC_S3_BUCKET are required for the s3 driver.'];
            }
            if (self::s3AccessKey() === '' || self::s3SecretKey() === '') {
                return ['ok' => false, 'driver' => 's3', 'message' => 'ASC_S3_ACCESS_KEY and ASC_S3_SECRET_KEY are required for the s3 driver.'];
            }

            // Probe: put + head + delete a tiny object.
            $probe = '.asc_storage_probe_' . bin2hex(random_bytes(4));
            if (!self::putString($probe, 'ok', 'text/plain')) {
                return ['ok' => false, 'driver' => 's3', 'message' => 'Put failed — check endpoint, region, bucket and credentials.'];
            }
            $exists = self::exists($probe);
            self::delete($probe);
            if (!$exists) {
                return ['ok' => false, 'driver' => 's3', 'message' => 'Head failed after successful put — check bucket permissions.'];
            }
            return ['ok' => true, 'driver' => 's3', 'message' => 'S3-compatible store reachable (put/head/delete OK).'];
        }

        // ── env accessors ────────────────────────────────────────────────
        private static function s3Endpoint(): string
        {
            return rtrim((string) config_value('ASC_S3_ENDPOINT', ''), '/');
        }

        private static function s3Region(): string
        {
            $r = trim((string) config_value('ASC_S3_REGION', 'us-east-1'));
            return $r !== '' ? $r : 'us-east-1';
        }

        private static function s3Bucket(): string
        {
            return trim((string) config_value('ASC_S3_BUCKET', ''));
        }

        private static function s3AccessKey(): string
        {
            return (string) config_value('ASC_S3_ACCESS_KEY', '');
        }

        private static function s3SecretKey(): string
        {
            return (string) config_value('ASC_S3_SECRET_KEY', '');
        }

        // ── AWS Signature V4 (path-style requests) ──────────────────────
        private static function s3Request(string $method, string $key, string $body = '', ?string $contentType = null): bool
        {
            $endpoint = self::s3Endpoint();
            $bucket   = self::s3Bucket();
            if ($endpoint === '' || $bucket === '') {
                return false;
            }

            $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
            $url        = $endpoint . '/' . $bucket . '/' . $encodedKey;
            $now        = gmdate('Ymd\THis\Z');
            $date       = substr($now, 0, 8);
            $region     = self::s3Region();
            $service    = 's3';
            $scope      = $date . '/' . $region . '/' . $service . '/aws4_request';
            $payload    = hash('sha256', $body);

            $headers = [
                'Host'                 => (string) parse_url($endpoint, PHP_URL_HOST),
                'x-amz-date'           => $now,
                'x-amz-content-sha256' => $payload,
                'Content-Type'         => $contentType ?: 'application/octet-stream',
            ];
            if ($method === 'HEAD') {
                unset($headers['Content-Type']);
            }

            // Canonical request
            ksort($headers);
            $canonicalHeaders = '';
            $signedNames      = [];
            foreach ($headers as $name => $value) {
                $ln = strtolower($name);
                $canonicalHeaders .= $ln . ':' . trim($value) . "\n";
                $signedNames[] = $ln;
            }
            $signedList   = implode(';', $signedNames);
            $canonicalReq = $method . "\n" . asc_storage_urlpath($url) . "\n\n" . $canonicalHeaders . "\n" . $signedList . "\n" . $payload;

            $stringToSign = "AWS4-HMAC-SHA256\n" . $now . "\n" . $scope . "\n" . hash('sha256', $canonicalReq);

            $kDate    = hash_hmac('sha256', $date, 'AWS4' . self::s3SecretKey(), true);
            $kRegion  = hash_hmac('sha256', $region, $kDate, true);
            $kService = hash_hmac('sha256', $service, $kRegion, true);
            $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
            $signature = hash_hmac('sha256', $stringToSign, $kSigning);

            $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . self::s3AccessKey() . '/' . $scope
                . ', SignedHeaders=' . $signedList
                . ', Signature=' . $signature;

            $httpHeaders = [];
            foreach ($headers as $name => $value) {
                $httpHeaders[] = $name . ': ' . $value;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $httpHeaders,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT        => 30,
            ]);
            if ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            if ($method === 'HEAD') {
                curl_setopt($ch, CURLOPT_NOBODY, true);
            }
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return $code >= 200 && $code < 300;
        }
    }

    /** Helper: path component of a URL (kept local for the canonical request). */
    if (!function_exists('asc_storage_urlpath')) {
        function asc_storage_urlpath(string $url): string
        {
            $path = (string) parse_url($url, PHP_URL_PATH);
            return $path !== '' ? $path : '/';
        }
    }

    /** Shortcuts so callers don't need the class name everywhere. */
    if (!function_exists('asc_storage_put')) {
        function asc_storage_put(string $key, string $localFile, ?string $contentType = null): bool
        {
            return AscStorage::put($key, $localFile, $contentType);
        }
    }
    if (!function_exists('asc_storage_delete')) {
        function asc_storage_delete(string $key): bool
        {
            return AscStorage::delete($key);
        }
    }
    if (!function_exists('asc_storage_exists')) {
        function asc_storage_exists(string $key): bool
        {
            return AscStorage::exists($key);
        }
    }
    if (!function_exists('asc_storage_url')) {
        function asc_storage_url(string $key): string
        {
            return AscStorage::url($key);
        }
    }
    if (!function_exists('asc_storage_test')) {
        function asc_storage_test(): array
        {
            return AscStorage::test();
        }
    }
    if (!function_exists('asc_storage_driver')) {
        function asc_storage_driver(): string
        {
            return AscStorage::driver();
        }
    }
}
