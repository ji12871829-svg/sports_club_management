<?php
/**
 * admin/system_health.php
 * Shows database size, last backup, failed logins, pending items, and key stats.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/whatsapp.php';
require_once '../includes/url.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$app_public_url = app_absolute_url('public/index.php');
$app_admin_url  = app_absolute_url('admin/admin_login.php');

$wa_phone_raw = defined('CLUB_WHATSAPP_PHONE') ? trim((string) CLUB_WHATSAPP_PHONE) : '';
$wa_phone_intl = $wa_phone_raw !== '' ? wa_format_phone($wa_phone_raw) : '';
$wa_test_url   = $wa_phone_raw !== '' ? wa_link($wa_phone_raw, CLUB_WHATSAPP_GREETING) : '';
function fmt_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

// DB size
$db_name  = DB_NAME ?? 'sports_club_db';
$db_size  = $conn->query("
    SELECT SUM(data_length + index_length) AS size
    FROM information_schema.tables
    WHERE table_schema = '$db_name'
")->fetch_row()[0] ?? 0;

// Table row counts
$tables = $conn->query("
    SELECT table_name, table_rows, data_length + index_length AS size
    FROM information_schema.tables
    WHERE table_schema = '$db_name'
    ORDER BY table_rows DESC
")->fetch_all(MYSQLI_ASSOC);

// Failed logins (last 24h) — login_attempts has attempted_at + action_type
$failed_logins = (int)($conn->query("SELECT COUNT(*) FROM login_attempts WHERE attempted_at >= NOW() - INTERVAL 24 HOUR AND action_type = 'login'")->fetch_row()[0] ?? 0);

// Pending items
$pending_bookings  = (int)$conn->query("SELECT COUNT(*) FROM bookings WHERE status='Pending'")->fetch_row()[0];
$pending_payments  = (int)$conn->query("SELECT COUNT(*) FROM payments WHERE payment_status IN ('Pending','pending')")->fetch_row()[0];
$open_damage       = (int)$conn->query("SELECT COUNT(*) FROM damage_reports WHERE resolved_at IS NULL")->fetch_row()[0];
$expiring_members  = (int)$conn->query("SELECT COUNT(*) FROM member_memberships WHERE status='Active' AND DATEDIFF(end_date,CURDATE()) BETWEEN 0 AND 7")->fetch_row()[0];

// Last backup
$backup_dir  = __DIR__ . '/../backups/';
$last_backup = null;
if (is_dir($backup_dir)) {
    $files = glob($backup_dir . '*.sql*');
    if ($files) {
        usort($files, fn($a,$b) => filemtime($b) - filemtime($a));
        $last_backup = ['file' => basename($files[0]), 'time' => filemtime($files[0]), 'size' => filesize($files[0])];
    }
}

// PHP / server info
$php_version = PHP_VERSION;
$server      = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';

$conn->close();
?>

<style>
.health-card { border-radius: 12px; padding: 20px; border: 1px solid #e2e8f0; }
.health-ok     { border-left: 4px solid #10b981; }
.health-warn   { border-left: 4px solid #f59e0b; }
.health-danger { border-left: 4px solid #ef4444; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:#0f172a;">
            <i class="fas fa-server text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">System Health</h1>
            <p class="text-muted mb-0 small">Database, backups, and pending items overview</p>
        </div>
        <span class="ms-auto badge bg-success">
            <i class="fas fa-circle me-1" style="font-size:.5rem;"></i> System Online
        </span>
    </div>

    <!-- Pending items -->
    <div class="row g-3 mb-4">
        <?php
        $items = [
            ['Pending Bookings',    $pending_bookings,  'fa-calendar-check', '#3b82f6', 'manage_bookings.php'],
            ['Pending Payments',    $pending_payments,  'fa-credit-card',    '#f59e0b', 'manage_payments.php'],
            ['Open Damage Reports', $open_damage,       'fa-tools',          '#ef4444', 'manage_damage_reports.php'],
            ['Memberships Expiring (7d)', $expiring_members, 'fa-clock',     '#8b5cf6', 'membership_reminders.php'],
        ];
        foreach ($items as [$label, $count, $icon, $color, $link]):
        ?>
        <div class="col-sm-6 col-xl-3">
            <a href="<?php echo e($link); ?>" class="text-decoration-none">
                <div class="card border-0 shadow-sm p-3 h-100">
                    <div class="d-flex align-items-center gap-3">
                        <div class="rounded-circle d-flex align-items-center justify-content-center"
                             style="width:44px;height:44px;background:<?php echo $color; ?>20;flex-shrink:0;">
                            <i class="fas <?php echo $icon; ?>" style="color:<?php echo $color; ?>;"></i>
                        </div>
                        <div>
                            <div class="fw-bold fs-4 <?php echo $count > 0 ? '' : 'text-muted'; ?>">
                                <?php echo $count; ?>
                            </div>
                            <div class="text-muted small"><?php echo e($label); ?></div>
                        </div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <!-- Database info -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-database me-2 text-primary"></i>Database
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Total Size</span>
                        <strong><?php echo fmt_bytes((int)$db_size); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-3">
                        <span class="text-muted">Tables</span>
                        <strong><?php echo count($tables); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-4">
                        <span class="text-muted">Failed Logins (24h)</span>
                        <strong class="<?php echo $failed_logins > 10 ? 'text-danger' : ($failed_logins > 3 ? 'text-warning' : 'text-success'); ?>">
                            <?php echo $failed_logins; ?>
                        </strong>
                    </div>
                    <div class="table-responsive" style="max-height:240px;overflow-y:auto;">
                        <table class="table table-sm mb-0">
                            <thead class="table-light sticky-top">
                                <tr><th>Table</th><th class="text-end">Rows</th><th class="text-end">Size</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tables as $t): ?>
                                <tr>
                                    <td class="small"><?php echo e($t['table_name']); ?></td>
                                    <td class="text-end small"><?php echo number_format((int)$t['table_rows']); ?></td>
                                    <td class="text-end small text-muted"><?php echo fmt_bytes((int)$t['size']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Backup & server info -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-shield-alt me-2 text-success"></i>Last Backup
                </div>
                <div class="card-body">
                    <?php if ($last_backup): ?>
                        <div class="health-card health-ok">
                            <div class="fw-semibold"><?php echo e($last_backup['file']); ?></div>
                            <div class="text-muted small mt-1">
                                <?php echo date('d M Y H:i', $last_backup['time']); ?>
                                &nbsp;·&nbsp; <?php echo fmt_bytes($last_backup['size']); ?>
                            </div>
                            <?php
                            $hours_ago = (time() - $last_backup['time']) / 3600;
                            if ($hours_ago > 48): ?>
                                <div class="text-danger small mt-2">
                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                    Last backup was <?php echo round($hours_ago / 24); ?> days ago — consider running a backup now.
                                </div>
                            <?php endif; ?>
                        </div>
                        <a href="backup_database.php" class="btn btn-sm btn-outline-primary mt-3">
                            <i class="fas fa-download me-1"></i> Run Backup Now
                        </a>
                    <?php else: ?>
                        <div class="health-card health-danger">
                            <div class="fw-semibold text-danger">No backups found</div>
                            <div class="text-muted small mt-1">Run a backup immediately to protect your data.</div>
                        </div>
                        <a href="backup_database.php" class="btn btn-danger mt-3">
                            <i class="fas fa-download me-1"></i> Run Backup Now
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-info-circle me-2 text-secondary"></i>Server Info
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">PHP Version</span>
                        <strong><?php echo e($php_version); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Server</span>
                        <strong class="small"><?php echo e($server); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted">Memory Limit</span>
                        <strong><?php echo ini_get('memory_limit'); ?></strong>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span class="text-muted">Max Upload</span>
                        <strong><?php echo ini_get('upload_max_filesize'); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm mb-0">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-link me-2 text-primary"></i>Site URLs (APP_URL)
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Folder on disk: <code>Apex Sports Club</code> — not <code>sports_club_management</code>.
                        Use these links when sharing via ngrok or testing from another phone.
                    </p>
                    <div class="mb-2">
                        <span class="text-muted small d-block">Public home</span>
                        <a href="<?php echo e($app_public_url); ?>" class="small font-monospace" target="_blank" rel="noopener"><?php echo e($app_public_url); ?></a>
                    </div>
                    <div>
                        <span class="text-muted small d-block">Admin login</span>
                        <a href="<?php echo e($app_admin_url); ?>" class="small font-monospace" target="_blank" rel="noopener"><?php echo e($app_admin_url); ?></a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fab fa-whatsapp me-2 text-success"></i>WhatsApp support widget
                </div>
                <div class="card-body">
                    <?php if ($wa_phone_raw === ''): ?>
                        <div class="alert alert-warning mb-0">
                            <strong>Not configured.</strong> Set <code>CLUB_WHATSAPP_PHONE</code> in <code>.env</code> to show the floating button.
                        </div>
                    <?php else: ?>
                        <p class="text-muted small mb-3">
                            Member messages are <strong>not stored in this admin panel</strong>. They only appear in the
                            WhatsApp app on the phone that owns the number below.
                        </p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">Number in .env</span>
                                    <strong><?php echo e($wa_phone_raw); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between mb-2">
                                    <span class="text-muted">WhatsApp link uses</span>
                                    <strong class="font-monospace">+<?php echo e($wa_phone_intl); ?></strong>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Draft message</span>
                                    <strong class="small text-end" style="max-width:55%;"><?php echo e(CLUB_WHATSAPP_GREETING); ?></strong>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <p class="small fw-semibold mb-2">If you do not see member chats:</p>
                                <ol class="small text-muted mb-3 ps-3">
                                    <li>The member must tap <strong>Send</strong> in WhatsApp (clicking the site button is not enough).</li>
                                    <li>Check WhatsApp on <strong>+<?php echo e($wa_phone_intl); ?></strong> — not another SIM or phone.</li>
                                    <li>Test from a <strong>different phone</strong> than the club line (you cannot chat with yourself).</li>
                                    <li>Look in WhatsApp <strong>Requests / Archived</strong> if the chat is hidden.</li>
                                </ol>
                                <a href="<?php echo e($wa_test_url); ?>" class="btn btn-success btn-sm" target="_blank" rel="noopener">
                                    <i class="fab fa-whatsapp me-1"></i> Open test chat link
                                </a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
