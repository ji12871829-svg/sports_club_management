<?php
/**
 * tests/error_handler_regression_test.php
 * Regression test for the exact bug reported by the user:
 *   - admin_header.php streams HTML (output already sent)
 *   - a mid-render mysqli_sql_exception fires
 *   - the OLD handler called header() unconditionally -> "Cannot modify
 *     header information" -> its own warning became an ErrorException ->
 *     cascade -> fatal error.
 *
 * The child process reproduces the scenario; the parent asserts the outcome.
 *
 * Run:  php tests/error_handler_regression_test.php
 * Exit code 0 = pass, 1 = fail.
 */

$childMode = ($argv[1] ?? '') === '--child';

if (!$childMode) {
    // ── Parent: run the child and assert ────────────────────────────────
    $php = PHP_BINARY;
    $cmd = sprintf('"%s" "%s" --child 2>&1', $php, __FILE__);
    $out = shell_exec($cmd);
    $code = 0;

    $hasFriendlyPage = strpos((string) $out, 'Something went wrong') !== false;
    $hasFatal = stripos((string) $out, 'Fatal error') !== false;
    $hasHeaderWarning = stripos((string) $out, 'Cannot modify header') !== false;
    $hasPartial = strpos((string) $out, 'partial header output') !== false;

    echo "Child output (truncated):\n" . substr((string) $out, 0, 400) . "\n\n";
    echo "Checks:\n";
    echo "  partial header output present   : " . ($hasPartial ? 'yes' : 'no') . "\n";
    echo "  friendly error page rendered    : " . ($hasFriendlyPage ? 'yes' : 'no') . "\n";
    echo "  'Fatal error' in output         : " . ($hasFatal ? 'YES (fail)' : 'no') . "\n";
    echo "  'Cannot modify header' in output: " . ($hasHeaderWarning ? 'YES (fail)' : 'no') . "\n";

    $ok = $hasFriendlyPage && !$hasFatal && !$hasHeaderWarning;
    echo "\n" . ($ok ? 'PASS — no header cascade; friendly page rendered.' : 'FAIL — error handler still cascades.') . "\n";
    exit($ok ? 0 : 1);
}

// ── Child: reproduce the exact failure scenario ─────────────────────────
require_once __DIR__ . '/../includes/error_handler.php';

// Simulate admin_header.php having already streamed HTML to the client.
echo "<!DOCTYPE html><html><head><title>partial page</title></head><body>…partial header output…\n";

// Simulate a mid-render database exception AFTER output started.
throw new mysqli_sql_exception("Table 'sports_club_db.ghost_table' doesn't exist");
