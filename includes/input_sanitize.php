<?php
// ============================================================
//  includes/input_sanitize.php
//  Centralized input sanitization and output escaping helpers.
//  Use these everywhere instead of inline sanitization.
// ============================================================

/**
 * Sanitize a string input: trim, strip tags, remove null bytes.
 */
function sanitize_string(string $input): string
{
    return trim(strip_tags(str_replace("\0", '', $input)));
}

/**
 * Sanitize and validate an email address.
 * Returns the lowercased email or empty string on failure.
 */
function sanitize_email(string $input): string
{
    $email = strtolower(trim($input));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    return $email;
}

/**
 * Cast input to integer within an optional range.
 * Returns $default if the value is not a valid integer.
 */
function sanitize_int(mixed $input, int $default = 0, ?int $min = null, ?int $max = null): int
{
    $val = filter_var($input, FILTER_VALIDATE_INT);
    if ($val === false) {
        return $default;
    }
    if ($min !== null && $val < $min) {
        return $default;
    }
    if ($max !== null && $val > $max) {
        return $default;
    }
    return $val;
}

/**
 * Sanitize a date string (Y-m-d format).
 * Returns the date or $default if invalid.
 */
function sanitize_date(string $input, string $default = ''): string
{
    $input = trim($input);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) && strtotime($input) !== false) {
        return $input;
    }
    return $default;
}

/**
 * Sanitize a URL — only allow http/https schemes.
 */
function sanitize_url(string $input): string
{
    $url = trim($input);
    $filtered = filter_var($url, FILTER_SANITIZE_URL);
    if ($filtered && preg_match('#^https?://#i', $filtered)) {
        return $filtered;
    }
    return '';
}

/**
 * Escape output for safe HTML display.
 * Wrapper around htmlspecialchars with sane defaults.
 */
function esc(mixed $input): string
{
    return htmlspecialchars((string) $input, ENT_QUOTES, 'UTF-8');
}

/**
 * Escape a string for safe use inside a JavaScript string literal.
 */
function esc_js(string $input): string
{
    return addcslashes($input, "\\\'\"\n\r\t/<");
}

/**
 * Escape a string for safe use inside a CSS context (e.g., inline styles).
 */
function esc_css(string $input): string
{
    return preg_replace('/[^\w\s\-.,!%#()\/]/', '', $input);
}

/**
 * Validate that a value is within an allowed list.
 */
function sanitize_enum(mixed $input, array $allowed, string $default = ''): string
{
    $val = (string) $input;
    return in_array($val, $allowed, true) ? $val : $default;
}

/**
 * Sanitize a phone number — allow digits, +, -, spaces, parentheses.
 */
function sanitize_phone(string $input): string
{
    return preg_replace('/[^\d+\-\s()]/', '', trim($input));
}

/**
 * Generic POST input sanitizer — returns trimmed, tag-stripped value.
 */
function post(string $key, string $default = ''): string
{
    return isset($_POST[$key]) ? sanitize_string($_POST[$key]) : $default;
}

/**
 * Generic GET input sanitizer — returns trimmed, tag-stripped value.
 */
function get(string $key, string $default = ''): string
{
    return isset($_GET[$key]) ? sanitize_string($_GET[$key]) : $default;
}

/**
 * Backwards-compatible alias for esc().
 * Kept so existing templates using e($var) don't break.
 */
function e(mixed $input): string
{
    return esc($input);
}
