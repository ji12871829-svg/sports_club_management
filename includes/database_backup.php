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
    usort($files, static fn ($a, $b) => filemtime($b) <=> filemtime($a));

    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }
}

/**
 * Locate the mysql client binary (sibling of mysqldump), used for restores.
 * Overridable via MYSQL_CLIENT_PATH in .env.
 */
function resolve_mysql_client_path(): string
{
    $configured = config_value('MYSQL_CLIENT_PATH');
    if ($configured !== '') {
        return $configured;
    }

    $projectRoot = dirname(__DIR__);
    $candidates = [
        dirname($projectRoot, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe',
        'C:' . DIRECTORY_SEPARATOR . 'xampp' . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe',
        'mysql',
    ];

    foreach ($candidates as $path) {
        if ($path === 'mysql') {
            return $path;
        }
        if (is_file($path)) {
            return $path;
        }
    }

    return 'mysql';
}

/**
 * Validate that a file looks like a SQL dump before it is offered for
 * restore. Returns an error string, or '' when the file is acceptable.
 */
function validate_backup_file(string $filepath): string
{
    if (!is_file($filepath)) {
        return 'Backup file not found.';
    }
    if (filesize($filepath) < 50) {
        return 'Backup file is too small to be a valid dump.';
    }
    $head = (string) file_get_contents($filepath, false, null, 0, 4096);
    $looksLikeDump = stripos($head, 'CREATE TABLE') !== false
        || stripos($head, 'INSERT INTO') !== false
        || stripos($head, 'MySQL dump') !== false
        || stripos($head, 'MariaDB dump') !== false;
    if (!$looksLikeDump) {
        return 'File does not look like a SQL dump.';
    }

    return '';
}

/**
 * Restore a validated dump into the configured database using the mysql
 * client with the dump piped via stdin. Foreign-key checks are disabled for
 * the duration so table load order does not matter.
 *
 * @return array{success: bool, message: string, output?: string}
 */
function restore_database_backup(string $filepath): array
{
    // Defense in depth: never pipe an arbitrary path into the mysql client,
    // even if a future caller forgets to sanitize. The file must resolve
    // inside the backups dir and match the backup_*.sql naming scheme.
    $backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'db';
    $real = realpath($filepath);
    $realDir = realpath($backupDir);
    if ($real === false || $realDir === false || strpos($real, $realDir . DIRECTORY_SEPARATOR) !== 0) {
        return ['success' => false, 'message' => 'Backup file must live inside the backups directory.'];
    }
    $name = basename($real);
    if (strpos($name, 'backup_') !== 0 || substr(strtolower($name), -4) !== '.sql') {
        return ['success' => false, 'message' => 'Invalid backup filename.'];
    }
    $filepath = $real;

    $error = validate_backup_file($filepath);
    if ($error !== '') {
        return ['success' => false, 'message' => $error];
    }

    $mysql = resolve_mysql_client_path();
    $args = [
        $mysql,
        '--host=' . DB_HOST,
        '--user=' . DB_USER,
        '--init-command=SET FOREIGN_KEY_CHECKS=0',
        DB_NAME,
    ];
    if (DB_PASSWORD !== '') {
        $args[] = '--password=' . DB_PASSWORD;
    }

    $descriptors = [
        0 => ['file', $filepath, 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($args, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return ['success' => false, 'message' => 'Could not start the mysql client.'];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    $output = trim($stderr . '\n' . $stdout);
    if ($exitCode !== 0) {
        $message = 'Restore failed (exit ' . $exitCode . ').';
        if ($output !== '') {
            $message .= ' ' . $output;
        }

        return ['success' => false, 'message' => $message, 'output' => $output];
    }

    return [
        'success' => true,
        'message' => 'Database restored from ' . basename($filepath) . '.',
        'output' => $output,
    ];
}
