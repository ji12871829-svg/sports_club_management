<?php

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/security_events.php';

function csrf_ensure(string $key = 'csrf_token'): string
{
    asc_session_start();

    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }

    return $_SESSION[$key];
}

function csrf_verify(?string $posted, string $key = 'csrf_token'): bool
{
    asc_session_start();

    $token = $_SESSION[$key] ?? '';
    $valid = is_string($posted) && $posted !== '' && $token !== '' && hash_equals($token, $posted);

    if (!$valid && isset($_POST) && (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')) {
        // A POST that failed the token check is a real CSRF signal (a stale
        // back-button resubmit is the benign case). Throttle at the DB layer
        // (once per IP per minute) rather than in-session, so a sessionless
        // bot flood cannot write a row per request. Actor = IP, keyed to the
        // form so different forms are not conflated.
        log_security_event_throttled(
            'csrf_reject',
            'warning',
            'CSRF token missing or invalid on POST to ' . ($_SERVER['SCRIPT_NAME'] ?? 'unknown'),
            ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . $key
        );
    }

    return $valid;
}

function csrf_field(string $key = 'csrf_token'): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrf_ensure($key), ENT_QUOTES, 'UTF-8')
        . '">';
}

/**
 * Verify a posted token against ANY csrf token currently stored in the
 * session. Used by the central admin CSRF enforcement (admin_header.php)
 * because individual pages legitimately use different token keys.
 *
 * Comparison is constant-time (hash_equals) against every candidate.
 */
function csrf_valid_any(?string $posted): bool
{
    asc_session_start();

    if (!is_string($posted) || $posted === '') {
        return false;
    }

    foreach ($_SESSION as $key => $value) {
        if (is_string($key) && strpos($key, 'csrf') !== false && is_string($value) && $value !== '' && hash_equals($value, $posted)) {
            return true;
        }
    }

    return false;
}
