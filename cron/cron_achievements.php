<?php
/**
 * Recompute and award member achievements.
 *
 * CLI:
 *   php cron/cron_achievements.php
 *
 * HTTP:
 *   GET /cron/cron_achievements.php?secret=YOUR_CRON_SECRET
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/achievements.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $secret = config_value('CRON_SECRET');
    $provided = $_GET['secret'] ?? '';
    if ($secret === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$result = asc_recompute_achievements($conn);
$conn->close();

$message = 'Achievements recomputed. Newly awarded: ' . (int) $result['awarded'];
if ($isCli) {
    echo $message . PHP_EOL;
} else {
    echo $message;
}


