<?php
/**
 * schedule_alert_cron.php
 * Registers a daily alert cron as a Windows Task Scheduler task so the
 * email digests run without manual scheduling.
 *
 * Usage:
 *   php schedule_alert_cron.php                     # profiler digest (daily 06:00)
 *   php schedule_alert_cron.php --cron payment      # payment health check
 *   php schedule_alert_cron.php --remove            # delete the selected task
 *   php schedule_alert_cron.php --time 08:30
 *   php schedule_alert_cron.php --dry-run           # just print the command
 *
 * Tasks:
 *   --cron profiler (default) → ApexProfilerSlowDigest → cron_profiler_alert.php
 *                              (requires ASC_PROFILER_EMAIL_TO in .env)
 *   --cron payment            → ApexPaymentHealth      → cron_payment_health.php
 *                              (requires ASC_PAYMENT_ALERT_EMAIL_TO in .env)
 */

// ── Task definition ────────────────────────────────────────────────────
$CRONS = [
    'profiler' => [
        'task_name' => 'ApexProfilerSlowDigest',
        'script'    => 'cron_profiler_alert.php',
        'env_key'   => 'ASC_PROFILER_EMAIL_TO',
        'label'     => 'profiler slow-page digest',
    ],
    'payment' => [
        'task_name' => 'ApexPaymentHealth',
        'script'    => 'cron_payment_health.php',
        'env_key'   => 'ASC_PAYMENT_ALERT_EMAIL_TO',
        'label'     => 'payment configuration health check',
    ],
];

$args = $_SERVER['argv'] ?? [];
$cron = 'profiler';
foreach ($args as $i => $a) {
    if ($a === '--cron' && isset($args[$i + 1])) {
        $cron = strtolower($args[$i + 1]);
    }
}
if (!isset($CRONS[$cron])) {
    fwrite(STDERR, "Unknown --cron '{$cron}'. Supported: " . implode(', ', array_keys($CRONS)) . "\n");
    exit(1);
}

$TASK_NAME = $CRONS[$cron]['task_name'];
$script    = __DIR__ . '/' . $CRONS[$cron]['script'];
$envKey    = $CRONS[$cron]['env_key'];
$label     = $CRONS[$cron]['label'];
$phpExe    = PHP_BINARY;
$time      = '06:00';

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

if (empty($env[$envKey])) {
    echo "WARNING: {$envKey} is not set in .env — the {$label} would do nothing.\n";
    echo "Set it first (e.g. {$envKey}=admin@sportsclub.com), then re-run this script.\n";
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
