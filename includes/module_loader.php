<?php
/**
 * includes/module_loader.php
 *
 * Lazy module loader — heavy helper files (AI clients, analytics engines)
 * are only parsed when their functions are actually needed, instead of on
 * every page request. Pages that do NOT use AI never pay the parse cost of
 * gemini_client.php (18 KB of curl wrappers + key resolution) or
 * churn_wellness_analytics.php (13 KB of scoring logic).
 *
 * Usage:
 *   require_once __DIR__ . '/module_loader.php';
 *   asc_require_module('ai');       // loads includes/gemini_client.php once
 *   asc_require_module('churn');    // loads includes/churn_wellness_analytics.php once
 *
 * Every call is idempotent (uses require_once semantics internally).
 */

/**
 * Lazily load a module by name. Returns true on success, false if the
 * module is unknown.
 */
function asc_require_module(string $module): bool
{
    static $loaded = [];

    if (isset($loaded[$module])) {
        return true;
    }

    $map = [
        'ai'    => __DIR__ . '/gemini_client.php',
        'churn' => __DIR__ . '/churn_wellness_analytics.php',
    ];

    if (!isset($map[$module])) {
        return false;
    }

    require_once $map[$module];
    $loaded[$module] = true;
    return true;
}
