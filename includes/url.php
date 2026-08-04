<?php

require_once __DIR__ . '/../config/api_config.php';

function app_base_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    foreach (['/public/', '/admin/', '/callbacks/'] as $marker) {
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            return rtrim(substr($script, 0, $pos), '/');
        }
    }

    return rtrim(dirname($script), '/');
}

function app_url(string $path = ''): string
{
    $queryString = '';
    if (str_contains($path, '?')) {
        [$path, $queryString] = explode('?', $path, 2);
    }

    $base = app_base_url();
    $path = ltrim($path, '/');
    $fullPath = $path === '' ? ($base ?: '/') : $base . '/' . $path;

    $relative = implode('/', array_map(
        static fn($segment) => $segment === '' ? '' : rawurlencode(rawurldecode($segment)),
        explode('/', $fullPath)
    ));

    if ($queryString !== '') {
        $relative .= '?' . $queryString;
    }

    return $relative;
}

function app_is_placeholder_app_url(string $url): bool
{
    return $url !== '' && preg_match('#(^https?://)?(www\.)?(your-domain|example)\.com#i', $url);
}

function app_request_scheme(): string
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
}

/** Full URL for emails and external links (uses APP_URL from .env when set). */
function app_absolute_url(string $path = ''): string
{
    $appUrl = rtrim((string) config_value('APP_URL', ''), '/');
    if (app_is_placeholder_app_url($appUrl)) {
        $appUrl = '';
    }

    $pathOnly = $path;
    $queryString = '';
    if (str_contains($path, '?')) {
        [$pathOnly, $queryString] = explode('?', $path, 2);
    }

    $relative = app_url($pathOnly);
    if ($queryString !== '') {
        $relative .= '?' . $queryString;
    }

    if ($appUrl !== '') {
        $relative = app_remove_duplicate_app_url_path($appUrl, $relative);
        return $appUrl . ($relative === '' || $relative === '/' ? '' : $relative);
    }

    return app_request_scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $relative;
}

/**
 * Remove duplicate app base path when APP_URL already contains the site's install folder.
 */
function app_remove_duplicate_app_url_path(string $appUrl, string $relative): string
{
    $appPath = parse_url($appUrl, PHP_URL_PATH) ?: '';
    if ($appPath !== '') {
        $appPath = rtrim($appPath, '/');
        if ($appPath !== '' && str_starts_with($relative, $appPath . '/')) {
            $relative = substr($relative, strlen($appPath));
        }
    }

    return $relative;
}

/**
 * Reset links and other emails: use the host the user is on now (e.g. ngrok)
 * so links work even when APP_URL in .env is stale or still a placeholder.
 */
function app_absolute_url_for_email(string $path, array $queryParams = []): string
{
    $pathOnly = $path;
    if (str_contains($path, '?')) {
        [$pathOnly, $qs] = explode('?', $path, 2);
        parse_str($qs, $parsed);
        $queryParams = array_merge($parsed, $queryParams);
    }

    $relative = app_url($pathOnly);
    if ($queryParams !== []) {
        $relative .= '?' . http_build_query($queryParams);
    }

    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        return app_request_scheme() . '://' . $_SERVER['HTTP_HOST'] . $relative;
    }

    $appUrl = rtrim((string) config_value('APP_URL', ''), '/');
    if ($appUrl !== '' && !app_is_placeholder_app_url($appUrl)) {
        $relative = app_remove_duplicate_app_url_path($appUrl, $relative);
        return $appUrl . $relative;
    }

    return app_request_scheme() . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $relative;
}
