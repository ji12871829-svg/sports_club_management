<?php
/**
 * Shared database backup helpers (CLI cron, HTTP trigger, admin UI).
 */

function resolve_mysqldump_path(): string
{
    $configured = config_value('MYSQLDUMP_PATH');
    if ($configured !== '') {
        return $configured;
    }

    $projectRoot = dirname(__DIR__);
    $candidates = [
        dirname($projectRoot, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
        'C:' . DIRECTORY_SEPARATOR . 'xampp' . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
        'mysqldump',
    ];

    foreach ($candidates as $path) {
        if ($path === 'mysqldump') {
            return $path;
        }
        if (is_file($path)) {
            return $path;
        }
    }

    return 'mysqldump';
}

function resolve_php_cli_path(): string
{
    $configured = config_value('PHP_CLI_PATH');
    if ($configured !== '' && is_file($configured)) {
        return $configured;
    }

    $projectRoot = dirname(__DIR__);
    $candidates = [
        dirname($projectRoot, 2) . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
        'C:' . DIRECTORY_SEPARATOR . 'xampp' . DIRECTORY_SEPARATOR . 'php' . DIRECTORY_SEPARATOR . 'php.exe',
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    if (PHP_SAPI === 'cli' && is_file(PHP_BINARY)) {
        return PHP_BINARY;
    }

    return 'php';
}

/**
 * @return array{success: bool, message: string, filename?: string}
 */
function run_database_backup(?string $backupDir = null): array
{
    $backupDir = $backupDir ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'db';

    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
        return ['success' => false, 'message' => 'Cannot create backup directory.'];
    }

    $filename = 'backup_' . DB_NAME . '_' . date('Y-m-d_His') . '.sql';
    $filepath = $backupDir . DIRECTORY_SEPARATOR . $filename;
    $mysqldump = resolve_mysqldump_path();

    $args = [
        $mysqldump,
        '--host=' . DB_HOST,
        '--user=' . DB_USER,
        '--single-transaction',
        '--routines',
        '--triggers',
        DB_NAME,
    ];

    if (DB_PASSWORD !== '') {
        $args[] = '--password=' . DB_PASSWORD;
    }

    $stderr = '';
    $exitCode = run_mysqldump_to_file($args, $filepath, $stderr);

    if ($exitCode !== 0 || !is_file($filepath) || filesize($filepath) < 50) {
        if (is_file($filepath)) {
            unlink($filepath);
        }

        $detail = trim($stderr);
        $message = 'Backup failed. Ensure mysqldump is available or set MYSQLDUMP_PATH in .env.';
        if ($detail !== '') {
            $message .= ' ' . $detail;
        }

        return ['success' => false, 'message' => $message];
    }

    prune_old_backups($backupDir, 14);

    $sizeKb = round(filesize($filepath) / 1024);
    return [
        'success' => true,
        'message' => 'Backup saved: ' . $filename . ' (' . $sizeKb . ' KB)',
        'filename' => $filename,
    ];
}

function run_mysqldump_to_file(array $args, string $filepath, string &$stderr): int
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $filepath, 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $args,
        $descriptors,
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        $stderr = 'Could not start mysqldump.';
        return 1;
    }

    fclose($pipes[0]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return proc_close($process);
}

function prune_old_backups(string $backupDir, int $keep = 14): void
{
    $files = glob($backupDir . DIRECTORY_SEPARATOR . 'backup_*.sql') ?: [];
    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));

    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}
