<?php
/**
 * includes/assets.php
 * Cache-busting asset helper.
 *
 * Appends a filemtime()-based version query string (?v=...) to LOCAL asset
 * URLs so the browser never serves a stale cached copy after a file is
 * edited. External/CDN URLs (Bootstrap, Font Awesome, Google Fonts, unpkg…)
 * are already pinned to explicit versions and are passed through untouched.
 */

if (!function_exists('asc_asset')) {
    /**
     * Return an asset URL with a filemtime() cache-buster appended.
     *
     * Results are memoized per request so pages that reference the same asset
     * many times only stat the file once.
     *
     * @param string      $url    The URL used in the HTML (may be absolute,
     *                            BASE_URL-relative, or relative like ../public/css/admin.css).
     * @param string|null $fsPath Optional absolute filesystem path to stat.
     *                            When null, the path is resolved against the
     *                            project root (this file lives in includes/).
     * @return string URL with ?v=… appended, or the original URL if the file
     *                can't be stat'ed or the URL is external.
     */
    function asc_asset(string $url, ?string $fsPath = null): string
    {
        // Memoize per request: key on URL + resolved path.
        static $cache = [];
        $cacheKey = $url . '|' . ($fsPath ?? '');
        if (array_key_exists($cacheKey, $cache)) {
            return $cache[$cacheKey];
        }

        // External URLs (http, https, protocol-relative, data:) are versioned upstream.
        if (preg_match('#^(https?:)?//#i', $url) || strpos($url, 'data:') === 0) {
            return $cache[$cacheKey] = $url;
        }

        // Resolve the filesystem path when the caller didn't provide one.
        if ($fsPath === null) {
            $root = dirname(__DIR__); // project root (includes/ is one level deep)
            $rel  = $url;
            // Strip any BASE_URL prefix (e.g. "/Apex Sports Club").
            if (defined('BASE_URL') && BASE_URL !== '' && strpos($rel, BASE_URL) === 0) {
                $rel = substr($rel, strlen(BASE_URL));
            }
            $rel = ltrim(str_replace('\\', '/', $rel), '/');

            // Refuse to guess paths with ../ segments — callers must pass an
            // explicit $fsPath for relative URLs. Returning the URL un-busted
            // is safer than stat'ing the wrong location.
            if ($rel === '..' || strpos($rel, '../') !== false) {
                return $cache[$cacheKey] = $url;
            }

            $fsPath = $root . '/' . $rel;
        }

        if (is_file($fsPath)) {
            $v   = (int) filemtime($fsPath);
            $sep = (strpos($url, '?') !== false) ? '&' : '?';
            return $cache[$cacheKey] = $url . $sep . 'v=' . $v;
        }

        // Can't stat — leave the URL untouched rather than breaking it.
        return $cache[$cacheKey] = $url;
    }
}
