<?php
/**
 * schedule_alert_cron.php
 * Registers cron_profiler_alert.php as a daily Windows Task Scheduler task
 * so the slow-page digest emails itself without manual scheduling.
 *
 * Usage:
 *   php schedule_alert_cron.php            # create the task (daily 06:00)
 *   php schedule_alert_cron.php --remove   # delete the task
 *   php schedule_alert_cron.php --time 08:30
 *   php schedule_alert_cron.php --dry-run  # just print the command
 *
 * Requires: ASC_PROFILER_EMAIL_TO set in .env (otherwise the digest itself
 * is a no-op and there is no point scheduling it).
 */

// ── Task definition ────────────────────────────────────────────────────
$TASK_NAME = 'ApexProfilerSlowDigest';
$phpExe    = PHP_BINARY;
$script    = __DIR__ . '/cron_profiler_alert.php';
$time      = '06:00';

$args = $_SERVER['argv'] ?? [];
$remove = in_array('--remove', $args, true);
$dryRun = in_array('--dry-run', $args, true);
foreach ($args as $i => $a) {
    if ($a === '--time' && isset($args[$i + 1])) {
        $time = preg_replace('/[^0-9:]/', '', $args[$i + 1]);
    }
}

$env = [];
if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2) + [1 => '']);
        $env[$k] = trim($v, "\"'");
    }
}

if ($remove) {
    $cmd = 'schtasks /Delete /TN "' . $TASK_NAME . '" /F';
    echo ($dryRun ? '[dry-run] ' : '') . $cmd . PHP_EOL;
    if (!$dryRun) {
        passthru($cmd, $code);
        echo $code === 0 ? "Task '{$TASK_NAME}' removed.\n" : "Remove returned exit code {$code} (may not exist).\n";
    }
    exit(0);
}

if (empty($env['ASC_PROFILER_EMAIL_TO'])) {
    echo "WARNING: ASC_PROFILER_EMAIL_TO is not set in .env — the digest would do nothing.\n";
    echo "Set it first (e.g. ASC_PROFILER_EMAIL_TO=admin@sportsclub.com), then re-run this script.\n";
}

$quotedPhp  = '"' . str_replace('"', '\"', $phpExe) . '"';
$quotedScr  = '"' . str_replace('"', '\"', $script) . '"';
$cmd = 'schtasks /Create /TN "' . $TASK_NAME . '"'
     . ' /TR ' . $quotedPhp . ' ' . $quotedScr
     . ' /SC DAILY /ST ' . $time
     . ' /F';

echo "Task:     {$TASK_NAME}\n";
echo "Schedule: Daily at {$time}\n";
echo "Command:\n  {$cmd}\n\n";

if ($dryRun) {
    echo "(dry-run — not executed)\n";
    exit(0);
}

passthru($cmd, $code);
if ($code === 0) {
    echo "\n✓ Scheduled. To verify: schtasks /Query /TN \"{$TASK_NAME}\" /V /FO LIST\n";
    echo "  To remove:  php schedule_alert_cron.php --remove\n";
} else {
    echo "\nschtasks returned exit code {$code}. If this is a non-admin shell, run it as Administrator.\n";
}
