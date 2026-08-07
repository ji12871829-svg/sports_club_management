<?php

/**
 * includes/health_token.php — optional access-token guard for public/health.php.
 *
 * When HEALTH_TOKEN is configured, the endpoint requires the token via one of:
 *   Authorization: Bearer <token>
 *   X-Health-Token: <token>
 *   ?token=<token>
 * Comparison uses hash_equals (constant-time). When the configured token is
 * empty the endpoint stays open (local dev / simple setups).
 *
 * Kept in its own file so both the endpoint and the unit tests exercise the
 * exact same function.
 */
function health_token_authorized(string $configuredToken): bool
{
    if ($configuredToken === '') {
        return true; // not configured — open endpoint
    }
    $presented = '';
    $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (stripos($authHeader, 'Bearer ') === 0) {
        $presented = trim(substr($authHeader, 7));
    } elseif (isset($_SERVER['HTTP_X_HEALTH_TOKEN'])) {
        $presented = trim((string) $_SERVER['HTTP_X_HEALTH_TOKEN']);
    } elseif (isset($_GET['token'])) {
        $presented = trim((string) $_GET['token']);
    }

    return $presented !== '' && hash_equals($configuredToken, $presented);
}
