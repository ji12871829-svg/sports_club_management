<?php
/**
 * Campaign email queue worker.
 *
 * CLI:
 *   php cron/cron_email_campaigns.php
 *
 * HTTP:
 *   GET /cron/cron_email_campaigns.php?secret=YOUR_CRON_SECRET
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/email_campaigns.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $secret = config_value('CRON_SECRET');
    $provided = $_GET['secret'] ?? '';
    if ($secret === '' || !hash_equals($secret, $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$result = process_queued_campaigns($conn, 3, 100);
$conn->close();

$message = 'Campaign worker completed. Processed: ' . $result['processed'] .
    ', Sent: ' . $result['sent'] .
    ', Failed: ' . $result['failed'];

if ($isCli) {
    echo $message . PHP_EOL;
} else {
    echo $message;
}


