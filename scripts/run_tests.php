<?php

declare(strict_types=1);

/**
 * CI-friendly PHPUnit wrapper.
 *
 * Runs phpunit with --testdox output, then prints a compact summary line
 * (tests / assertions / failures / errors / skipped) and exits non-zero if
 * any test failed or errored, or if the run itself could not complete.
 *
 * Usage:
 *   php scripts/run_tests.php
 *   php scripts/run_tests.php --filter 'MpesaCallbackUrlTest|PaystackSignatureTest'
 *
 * Skips are reported but do NOT fail the build: this suite legitimately
 * skips schema-dependent cases (e.g. NOT NULL columns) on some databases.
 */

$phpunitBin = __DIR__ . '/../vendor/bin/phpunit';
$config = __DIR__ . '/../phpunit.xml';

if (!is_file($phpunitBin)) {
    fwrite(STDERR, "phpunit binary not found: {$phpunitBin}\n");
    exit(1);
}

$args = array_slice($argv, 1);
$cmd = escapeshellarg(PHP_BINARY)
    . ' ' . escapeshellarg($phpunitBin)
    . ' --configuration ' . escapeshellarg($config)
    . ' --colors=never --testdox';

if ($args !== []) {
    $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $args));
}

exec($cmd . ' 2>&1', $output, $exitCode);

$text = implode("\n", $output);

$tests = 0;
$assertions = 0;
$failures = 0;
$errors = 0;
$skipped = 0;

if (preg_match('/Tests:\s*(\d+)/i', $text, $m)) {
    $tests = (int) $m[1];
}
if (preg_match('/Assertions:\s*(\d+)/i', $text, $m)) {
    $assertions = (int) $m[1];
}
if (preg_match('/Failures:\s*(\d+)/i', $text, $m)) {
    $failures = (int) $m[1];
}
if (preg_match('/Errors:\s*(\d+)/i', $text, $m)) {
    $errors = (int) $m[1];
}
if (preg_match('/Skipped:\s*(\d+)/i', $text, $m)) {
    $skipped = (int) $m[1];
}

// Fallback when phpunit's summary line is terse
if ($tests === 0 && preg_match('/OK.*Tests:\s*(\d+),\s*Assertions:\s*(\d+)/i', $text, $m)) {
    $tests = (int) $m[1];
    $assertions = (int) $m[2];
}
if (preg_match('/OK, but incomplete, skipped, or risky tests!/i', $text)) {
    // skipped count already captured if present
}
if (preg_match('/FAILURES!|ERRORS!/i', $text)) {
    if ($failures === 0 && $errors === 0) {
        $failures = 1; // conservative: something failed
    }
}

$status = ($exitCode === 0 && $failures === 0 && $errors === 0) ? 'PASS' : 'FAIL';
printf(
    "[tests] %s | tests=%d assertions=%d failures=%d errors=%d skipped=%d (exit=%d)\n",
    $status,
    $tests,
    $assertions,
    $failures,
    $errors,
    $skipped,
    $exitCode
);

if ($status === 'FAIL') {
    echo $text, "\n";
    exit(1);
}

exit(0);
