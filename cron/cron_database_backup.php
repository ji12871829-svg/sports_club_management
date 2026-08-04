<?php
/**
 * Automated database backup — run via CLI cron, e.g. daily at 2am:
 * php "C:/xampp/htdocs/Apex Sports Club/cron/cron_database_backup.php"
 *
 * Optional HTTP trigger (set CRON_SECRET in .env):
 * GET /cron/cron_database_backup.php?secret=YOUR_CRON_SECRET
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../includes/database_backup.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $secret = config_value('CRON_SECRET');
    $provided = $_GET['secret'] ?? '';
    if ($secret === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$result = run_database_backup();

if (!$result['success']) {
    if ($isCli) {
        fwrite(STDERR, $result['message'] . PHP_EOL);
    } else {
        http_response_code(500);
        echo $result['message'];
    }
    exit(1);
}

if ($isCli) {
    echo $result['message'] . PHP_EOL;
} else {
    echo $result['message'];
}

