<?php
include_once '../includes/admin_header.php';
require_once '../config/api_config.php';
require_once '../includes/database_backup.php';

$msg = '';
$secret = config_value('CRON_SECRET');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_backup'])) {
    $result = run_database_backup();
    $msg = $result['message'];
    if (!$result['success']) {
        $msg = 'Backup failed: ' . $msg;
    }
}
?>

<div class="container-fluid py-4">
  <h2 class="mb-3">Database backups</h2>
  <div class="card"><div class="card-body">
    <p>Automated backups save to <code>backups/db/</code> and keep the last 14 files.</p>
    <h6>Windows Task Scheduler / cron</h6>
    <pre class="bg-light p-3 rounded"><?php echo htmlspecialchars('"' . resolve_php_cli_path() . '" "' . dirname(__DIR__) . '/cron/cron_database_backup.php"', ENT_QUOTES); ?></pre>
    <?php if ($secret !== ''): ?>
      <p class="small text-muted">HTTP trigger: <code>cron/cron_database_backup.php?secret=…</code></p>
    <?php else: ?>
      <p class="alert alert-warning small">Set <code>CRON_SECRET</code> in .env for HTTP-triggered backups.</p>
    <?php endif; ?>
    <form method="post">
      <button type="submit" name="run_backup" value="1" class="btn btn-primary">Run backup now</button>
    </form>
    <?php if ($msg): ?><div class="alert alert-info mt-3"><?php echo htmlspecialchars($msg); ?></div><?php endif; ?>
  </div></div>
</div>
