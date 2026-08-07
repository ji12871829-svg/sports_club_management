<?php

/**
 * scripts/restore_backup.php — database restore runbook.
 *
 * Restores a validated backup from backups/db/ into the configured DB using
 * the mysql client. Always preview with --dry-run first; real restores
 * require an explicit --confirm.
 *
 * Usage:
 *   php scripts/restore_backup.php --list                 list available backups
 *   php scripts/restore_backup.php                        show the newest backup (dry-run)
 *   php scripts/restore_backup.php <file.sql> --dry-run   validate only
 *   php scripts/restore_backup.php <file.sql> --confirm   restore for real
 *
 * Exit codes: 0 ok, 1 validation/usage error, 2 restore failed.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'This script must be run from the command line.' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/database_backup.php';

$backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'db';
$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$confirm = in_array('--confirm', $args, true);
$list = in_array('--list', $args, true);
$target = null;
foreach ($args as $a) {
    if (in_array($a, ['--dry-run', '--confirm', '--list'], true)) {
        continue;
    }
    $target = $a;
}

if ($list) {
    $files = glob($backupDir . DIRECTORY_SEPARATOR . 'backup_*.sql') ?: [];
    usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
    if (!$files) {
        echo "No backups found in $backupDir\n";
        exit(0);
    }
    echo "Available backups (newest first):\n";
    foreach ($files as $i => $f) {
        printf("  [%d] %s  (%s, %d KB)\n", $i + 1, basename($f), date('Y-m-d H:i', (int) filemtime($f)), (int) round(filesize($f) / 1024));
    }
    exit(0);
}

if ($target === null) {
    $files = glob($backupDir . DIRECTORY_SEPARATOR . 'backup_*.sql') ?: [];
    usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
    $target = $files[0] ?? null;
    if ($target === null) {
        fwrite(STDERR, "No backups found. Run cron_database_backup.php first.\n");
        exit(1);
    }
    echo 'No file given — using newest backup: ' . basename($target) . "\n";
}

$filepath = $target;
// PHP 7.4-safe guards (strpos, not str_starts_with/str_ends_with).
if (strpos(basename($filepath), 'backup_') !== 0 || substr(strtolower($filepath), -4) !== '.sql') {
    fwrite(STDERR, "Refusing: only backups/db/backup_*.sql files are restorable.\n");
    exit(1);
}
$filepath = $backupDir . DIRECTORY_SEPARATOR . basename($filepath);

$error = validate_backup_file($filepath);
if ($error !== '') {
    fwrite(STDERR, "Validation failed: $error\n");
    exit(1);
}
echo 'Validated: ' . basename($filepath) . ' (' . round(filesize($filepath) / 1024) . " KB)\n";

if ($dryRun) {
    echo "DRY-RUN: would restore into database '" . DB_NAME . "' using mysql client.\n";
    echo "Re-run with --confirm to execute.\n";
    exit(0);
}

if (!$confirm) {
    fwrite(STDERR, "This will OVERWRITE data in database '" . DB_NAME . "'. Re-run with --confirm to execute, or --dry-run to preview.\n");
    exit(1);
}

$result = restore_database_backup($filepath);
if (!$result['success']) {
    fwrite(STDERR, $result['message'] . PHP_EOL);
    exit(2);
}
echo $result['message'] . "\n";
if (!empty($result['output'])) {
    echo "--- client output ---\n" . $result['output'] . "\n";
}
exit(0);
