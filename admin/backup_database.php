<?php
include_once '../includes/admin_header.php';
require_once '../config/api_config.php';
require_once '../includes/database_backup.php';

$msg = '';
$msgType = 'info';
$secret = config_value('CRON_SECRET');
$backupDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'db';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['run_backup'])) {
        $result = run_database_backup();
        $msg = $result['message'];
        $msgType = $result['success'] ? 'success' : 'danger';
        if (!$result['success']) {
            $msg = 'Backup failed: ' . $msg;
        }
    } elseif (($_POST['action'] ?? '') === 'restore') {
        // Restore overwrites the entire database — the most destructive action
        // in the app. Gate it behind settings.edit (super-admin level) on top
        // of the page-level backup.create check.
        $canRestore = isset($conn) && $conn instanceof mysqli
            && isset($__admin_id)
            && asc_has_permission($conn, 'settings.edit', 'admin', (int) $__admin_id);
        if (!$canRestore) {
            $msg = 'You do not have permission to restore the database. Contact a super admin.';
            $msgType = 'danger';
        } else {
            $name = basename((string) ($_POST['backup_file'] ?? ''));
            if (strpos($name, 'backup_') !== 0 || substr(strtolower($name), -4) !== '.sql') {
                $msg = 'Invalid backup filename.';
                $msgType = 'danger';
            } else {
                @set_time_limit(0); // a large dump can exceed the default limit
                $filepath = $backupDir . DIRECTORY_SEPARATOR . $name;
                $result = restore_database_backup($filepath);
                $msg = $result['message'];
                $msgType = $result['success'] ? 'success' : 'danger';
            }
        }
    }
}

// Newest backups for the restore list.
$backups = glob($backupDir . DIRECTORY_SEPARATOR . 'backup_*.sql') ?: [];
usort($backups, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
$backups = array_slice($backups, 0, 14);
?>

<div class="container-fluid py-4">
  <div class="d-flex align-items-center gap-3 mb-4">
    <div class="rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;background:#1d5c8f;">
      <i class="fas fa-database text-white"></i>
    </div>
    <div>
      <h1 class="mb-0 fw-bold fs-4">Database Backups</h1>
      <p class="text-muted mb-0 small">Create and restore full SQL backups — files live in <code>backups/db/</code>, last 14 kept.</p>
    </div>
  </div>

  <?php if ($msg): ?>
    <div class="alert alert-<?php echo $msgType; ?> alert-dismissible fade show py-2" role="alert">
      <?php echo htmlspecialchars($msg); ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white py-3"><strong><i class="fas fa-circle-plus me-2 text-primary"></i>Create a backup</strong></div>
        <div class="card-body">
          <p class="small text-secondary">Runs <code>mysqldump --single-transaction</code> (no downtime) and keeps the 14 most recent files.</p>
          <form method="post">
            <?php echo csrf_field('admin_csrf'); ?>
            <button type="submit" name="run_backup" value="1" class="btn btn-primary">
              <i class="fas fa-play me-1"></i>Run backup now
            </button>
          </form>

          <hr class="my-4">

          <h6 class="text-secondary text-uppercase" style="font-size:0.75rem;letter-spacing:0.5px;">Schedule it</h6>
          <pre class="bg-light p-3 rounded small mb-2"><?php echo htmlspecialchars('"' . resolve_php_cli_path() . '" "' . dirname(__DIR__) . '/cron/cron_database_backup.php"', ENT_QUOTES); ?></pre>
          <?php if ($secret !== ''): ?>
            <p class="small text-muted mb-0">HTTP trigger: <code>cron/cron_database_backup.php?secret=…</code></p>
          <?php else: ?>
            <p class="alert alert-warning small mb-0">Set <code>CRON_SECRET</code> in .env for HTTP-triggered backups.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><strong><i class="fas fa-rotate-left me-2 text-danger"></i>Restore a backup</strong></div>
        <div class="card-body p-0">
          <?php if (!$backups): ?>
            <div class="text-center py-5 text-muted small">
              <i class="fas fa-box-open fa-2x mb-2 d-block text-secondary opacity-50"></i>
              No backups yet — create one first.
            </div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table table-hover align-middle mb-0">
                <thead class="bg-light" style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;">
                  <tr>
                    <th class="ps-4 py-2">File</th>
                    <th class="py-2">Created</th>
                    <th class="py-2 text-end pe-4">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($backups as $b): ?>
                    <tr>
                      <td class="ps-4 py-2">
                        <code class="small"><?php echo htmlspecialchars(basename($b)); ?></code>
                        <span class="small text-muted">(<?php echo round(filesize($b) / 1024); ?> KB)</span>
                      </td>
                      <td class="py-2 small text-secondary"><?php echo date('d M Y, H:i', (int) filemtime($b)); ?></td>
                      <td class="py-2 text-end pe-4">
                        <form method="post" class="d-inline" onsubmit="return confirm('Restore the database from this backup? All current data will be replaced.');">
                          <?php echo csrf_field('admin_csrf'); ?>
                          <input type="hidden" name="action" value="restore">
                          <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars(basename($b)); ?>">
                          <button type="submit" class="btn btn-sm btn-outline-danger" title="Restore this backup">
                            <i class="fas fa-rotate-left me-1"></i>Restore
                          </button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="p-3 small text-muted">
              <i class="fas fa-triangle-exclamation me-1 text-warning"></i>
              Restoring overwrites current data. CLI runbook: <code>php scripts/restore_backup.php --list</code> / <code>--dry-run</code> / <code>--confirm</code>.
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
