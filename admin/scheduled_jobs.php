<?php
// ============================================================
//  admin/scheduled_jobs.php
//  Scheduled jobs manager — see every cron job, run any job
//  manually (with the appropriate bypass), and get the exact
//  Task Scheduler / cron install commands.
// ============================================================
include_once '../includes/admin_header.php';
require_once '../config/api_config.php';
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once '../includes/input_sanitize.php';
require_once '../includes/activity_log.php';
require_once '../includes/database_backup.php'; // resolve_php_cli_path()

$admin_id = (int) ($_SESSION['admin_id'] ?? 0);
$cronSecret = (string) config_value('CRON_SECRET');

/**
 * Per-job metadata. The flags describe what the job's own access control
 * needs before it will run inline from a web request:
 *   - needs_secret      : guard compares against CRON_SECRET (config_value)
 *   - constant_secret   : guard reads a CRON_SECRET *constant* (define it)
 *   - header_secret     : guard reads HTTP_X_CRON_SECRET + getenv()
 *   - manual_bypass     : guard is disabled by CRON_MANUAL_RUN
 */
$jobs = [
    'cron_session_cleanup.php' => [
        'name'     => 'Session Cleanup',
        'desc'     => 'Prunes stale admin sessions (7 days idle) and login attempts (24 h) so the auth tables stay bounded.',
        'schedule' => 'Daily 03:00',
        'icon'     => 'fa-broom',
        'color'    => '#0e7490',
    ],
    'cron_security_alert.php' => [
        'name'          => 'Security Digest',
        'desc'          => 'Emails every admin a daily report of unacknowledged security events, new-device logins, revoked sessions and failed logins.',
        'schedule'      => 'Daily 08:00',
        'icon'          => 'fa-shield-halved',
        'color'         => '#b91c1c',
        'manual_bypass' => true,
    ],
    'cron_membership_renewal.php' => [
        'name'          => 'Membership Renewals',
        'desc'          => 'Processes due renewals, applies lapses and queues the renewal reminder emails.',
        'schedule'      => 'Daily 06:00',
        'icon'          => 'fa-calendar-check',
        'color'         => '#1d5c8f',
        'header_secret' => true,
        'log_glob'      => 'cron/logs/renewal_*.log',
    ],
    'cron_late_payment_reminders.php' => [
        'name'     => 'Late Payment Reminders',
        'desc'     => 'Sends WhatsApp + email reminders for overdue memberships and unpaid damage-report fines.',
        'schedule' => 'Daily 09:00',
        'icon'     => 'fa-bell',
        'color'    => '#b45309',
    ],
    'cron_database_backup.php' => [
        'name'         => 'Database Backup',
        'desc'         => 'Creates a mysqldump into backups/db/ and keeps the last 14 files.',
        'schedule'     => 'Daily 02:00',
        'icon'         => 'fa-database',
        'color'        => '#0f172a',
        'needs_secret' => true,
        'log_glob'     => 'backups/db/*.sql',
    ],
    'cron_achievements.php' => [
        'name'         => 'Achievements',
        'desc'         => 'Recomputes and awards member achievement badges.',
        'schedule'     => 'Hourly',
        'icon'         => 'fa-trophy',
        'color'        => '#a16207',
        'needs_secret' => true,
    ],
    'cron_ai_booking_review.php' => [
        'name'          => 'AI Booking Review',
        'desc'          => 'Reviews recent bookings with AI according to the strictness settings on the AI Cron Settings page.',
        'schedule'      => 'Hourly',
        'icon'          => 'fa-robot',
        'color'         => '#7c3aed',
        'manual_bypass' => true,
    ],
    'cron_attendance_alert.php' => [
        'name'            => 'Attendance Alerts',
        'desc'            => 'Flags members with 3+ consecutive absences and sends the alerts.',
        'schedule'        => 'Daily 18:00',
        'icon'            => 'fa-clipboard-check',
        'color'           => '#0369a1',
        'needs_secret'    => true,
        'constant_secret' => true,
    ],
    'cron_email_campaigns.php' => [
        'name'         => 'Email Campaigns',
        'desc'         => 'Worker for the queued campaign email sender.',
        'schedule'     => 'Every 15 min',
        'icon'         => 'fa-envelope-open-text',
        'color'        => '#be185d',
        'needs_secret' => true,
    ],
    'cron_fine_escalation.php' => [
        'name'            => 'Fine Escalation',
        'desc'            => 'Escalates overdue member fines one stage per cycle.',
        'schedule'        => 'Daily 10:00',
        'icon'            => 'fa-money-bill-wave',
        'color'           => '#c2410c',
        'needs_secret'    => true,
        'constant_secret' => true,
    ],
];

/**
 * Prepare globals so the given job's own access control passes when the job
 * is required inline. Returns [ok, notice|error].
 */
function job_prep_run(array $meta): array
{
    if (!empty($meta['manual_bypass'])) {
        if (!defined('CRON_MANUAL_RUN')) {
            define('CRON_MANUAL_RUN', true);
        }
    }

    if (!empty($meta['needs_secret'])) {
        if (!empty($meta['constant_secret'])) {
            $secret = defined('CRON_SECRET') ? (string) CRON_SECRET : (string) config_value('CRON_SECRET');
            if ($secret === '') {
                return [false, 'CRON_SECRET is not set in .env — this job cannot run from the web. Set a secret, or schedule it via Task Scheduler / cron.'];
            }
            if (!defined('CRON_SECRET')) {
                define('CRON_SECRET', $secret);
            }
            $_GET['secret'] = $secret;
        } else {
            $secret = (string) config_value('CRON_SECRET');
            if ($secret === '') {
                return [false, 'CRON_SECRET is not set in .env — this job cannot run from the web. Set a secret, or schedule it via Task Scheduler / cron.'];
            }
            $_GET['secret'] = $secret;
        }
    }

    if (!empty($meta['header_secret'])) {
        $secret = (string) getenv('CRON_SECRET');
        if ($secret === '') {
            return [false, 'CRON_SECRET is not set in the environment — this job cannot run from the web. Add it to .env, or schedule it via Task Scheduler / cron.'];
        }
        $_SERVER['HTTP_X_CRON_SECRET'] = $secret;
    }

    return [true, ''];
}

/**
 * Latest mtime of files matching a glob, as a human label ('' when none).
 */
function job_last_run(string $glob): string
{
    $root = dirname(__DIR__);
    $files = glob($root . '/' . $glob);
    if (!$files) {
        return '';
    }
    $latest = 0;
    foreach ($files as $f) {
        $t = @filemtime($f);
        if ($t > $latest) {
            $latest = $t;
        }
    }
    if ($latest === 0) {
        return '';
    }

    $diff = time() - $latest;
    if ($diff < 3600) {
        return (int) max(1, floor($diff / 60)) . ' min ago';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' hr ago';
    }

    return (int) floor($diff / 86400) . ' days ago';
}

$message = '';
$error = '';
$runOutput = '';
$showOutput = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_job') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'scheduled_jobs_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $jobFile = basename((string) ($_POST['job'] ?? ''));
        if (!isset($jobs[$jobFile])) {
            $error = 'Unknown job.';
        } else {
            $meta = $jobs[$jobFile];
            $prep = job_prep_run($meta);
            if (!$prep[0]) {
                $error = $prep[1];
            } else {
                // Run the job inline (the established pattern — see
                // membership_reminders.php). If the job exits early on its own
                // (e.g. "nothing to report"), a shutdown fallback still shows
                // its output instead of a truncated page.
                $runOutput = '';
                $jobCompleted = false;
                register_shutdown_function(function () use (&$runOutput, &$jobCompleted) {
                    if (!$jobCompleted) {
                        $runOutput = (string) ob_get_clean();
                        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Job output — Apex Sports Club</title>'
                            . '<style>body{background:#0f172a;color:#e2e8f0;font-family:ui-monospace,Consolas,monospace;padding:24px;margin:0;}'
                            . 'h1{font-family:system-ui,sans-serif;font-size:15px;color:#94a3b8;margin:0 0 12px;font-weight:600;}pre{white-space:pre-wrap;font-size:13px;line-height:1.6;}</style>'
                            . '</head><body><h1>Scheduled job output — the job finished early and returned its own status:</h1><pre>'
                            . htmlspecialchars($runOutput) . '</pre></body></html>';
                    }
                });
                ob_start();
                try {
                    require __DIR__ . '/../cron/' . $jobFile;
                } catch (\Throwable $t) {
                    echo 'Job threw an exception: ' . $t->getMessage() . "\n";
                }
                $runOutput = (string) ob_get_clean();
                $jobCompleted = true;

                // Best-effort audit trail. Some jobs close $conn themselves
                // (e.g. cron_achievements), so never let the log take the page
                // down after a successful run.
                try {
                    log_activity($conn, 'Manually ran scheduled job: ' . $meta['name'], 'Settings', $admin_id);
                } catch (\Throwable $e) {
                    error_log('[scheduled_jobs] activity log failed: ' . $e->getMessage());
                }
                $message = 'Job "<strong>' . e($meta['name']) . '</strong>" completed — output below.';
                $showOutput = true;
            }
        }
    }
}

$phpCli = resolve_php_cli_path();
$baseDir = dirname(__DIR__);
?>

<div class="container-fluid py-4">

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-4">
        <div>
            <h2 class="fw-bold mb-1" style="color:#0f172a;">Scheduled Jobs</h2>
            <p class="text-muted mb-0 small">Every automated job in <code>cron/</code> — run any of them now, or copy the exact scheduler command.</p>
        </div>
        <?php if ($cronSecret !== ''): ?>
            <span class="badge rounded-pill px-3 py-2" style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;">
                <i class="fas fa-check-circle me-1"></i>CRON_SECRET configured
            </span>
        <?php else: ?>
            <span class="badge rounded-pill px-3 py-2" style="background:#fffbeb;color:#b45309;border:1px solid #fde68a;">
                <i class="fas fa-triangle-exclamation me-1"></i>CRON_SECRET not set
            </span>
        <?php endif; ?>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-circle-exclamation me-2"></i><?php echo e($error); ?></div>
    <?php endif; ?>

    <?php if ($message): ?>
        <div class="alert alert-success border-0 shadow-sm">
            <div class="fw-semibold mb-2" style="color:#047857;"><?php echo $message; ?></div>
            <?php if ($showOutput && $runOutput !== ''): ?>
                <pre class="small bg-white border p-3 rounded text-secondary mb-0 font-monospace" style="font-size:0.8rem;max-height:320px;overflow:auto;"><?php echo e($runOutput); ?></pre>
            <?php elseif ($showOutput): ?>
                <p class="small text-muted mb-0">The job produced no output.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white border-0 py-3">
            <div class="d-flex align-items-center">
                <i class="fas fa-list-check me-2" style="color:#1d5c8f;"></i>
                <strong>Job queue</strong>
                <span class="badge bg-light text-secondary ms-2"><?php echo count($jobs); ?> jobs</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light" style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.5px;color:#64748b;">
                        <tr>
                            <th class="ps-4 py-3">Job</th>
                            <th class="py-3">Schedule</th>
                            <th class="py-3 d-none d-md-table-cell">What it does</th>
                            <th class="py-3 d-none d-lg-table-cell">Recent activity</th>
                            <th class="py-3 text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($jobs as $file => $meta):
                            $lastRun = !empty($meta['log_glob']) ? job_last_run($meta['log_glob']) : '';
                        ?>
                            <tr>
                                <td class="ps-4 py-3">
                                    <div class="d-flex align-items-center">
                                        <span class="d-inline-flex align-items-center justify-content-center rounded-3 me-3" style="width:38px;height:38px;background:<?php echo $meta['color']; ?>14;color:<?php echo $meta['color']; ?>;font-size:15px;">
                                            <i class="fas <?php echo $meta['icon']; ?>"></i>
                                        </span>
                                        <div>
                                            <div class="fw-semibold" style="color:#0f172a;"><?php echo e($meta['name']); ?></div>
                                            <code class="small text-muted"><?php echo e($file); ?></code>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3">
                                    <span class="badge" style="background:#f1f5f9;color:#334155;font-weight:500;"><?php echo e($meta['schedule']); ?></span>
                                </td>
                                <td class="py-3 d-none d-md-table-cell" style="max-width:340px;">
                                    <span class="small text-secondary"><?php echo e($meta['desc']); ?></span>
                                </td>
                                <td class="py-3 d-none d-lg-table-cell small text-muted">
                                    <?php echo $lastRun !== '' ? $lastRun : '<span class="text-muted opacity-50">—</span>'; ?>
                                </td>
                                <td class="py-3 text-end pe-4">
                                    <form method="post" class="d-inline">
                                        <?php echo csrf_field('scheduled_jobs_csrf'); ?>
                                        <input type="hidden" name="action" value="run_job">
                                        <input type="hidden" name="job" value="<?php echo e($file); ?>">
                                        <button type="submit" class="btn btn-sm" style="background:#1d5c8f;color:#fff;" title="Run this job now">
                                            <i class="fas fa-play me-1"></i>Run now
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-secondary ms-1" data-bs-toggle="collapse"
                                            data-bs-target="#cmd-<?php echo md5($file); ?>" title="Install command">
                                        <i class="fas fa-terminal"></i>
                                    </button>
                                </td>
                            </tr>
                            <tr class="d-none d-md-table-row">
                                <td colspan="5" class="p-0 border-0">
                                    <div class="collapse" id="cmd-<?php echo md5($file); ?>">
                                        <div class="px-4 py-3 bg-light border-top">
                                            <div class="row g-3">
                                                <div class="col-md-6">
                                                    <div class="small fw-semibold mb-1 text-secondary text-uppercase" style="font-size:0.7rem;letter-spacing:0.5px;">Windows Task Scheduler</div>
                                                    <pre class="small bg-white border rounded p-2 mb-0 font-monospace" style="font-size:0.78rem;white-space:pre-wrap;"><?php echo htmlspecialchars('"' . $phpCli . '" "' . $baseDir . '/cron/' . $file . '"', ENT_QUOTES); ?></pre>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="small fw-semibold mb-1 text-secondary text-uppercase" style="font-size:0.7rem;letter-spacing:0.5px;">Linux cron</div>
                                                    <pre class="small bg-white border rounded p-2 mb-0 font-monospace" style="font-size:0.78rem;white-space:pre-wrap;">php /path/to/club/cron/<?php echo e($file); ?></pre>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white border-0 py-3">
            <i class="fas fa-circle-info me-2" style="color:#1d5c8f;"></i><strong>Notes</strong>
        </div>
        <div class="card-body small text-secondary" style="line-height:1.8;">
            <p class="mb-2">
                <i class="fas fa-lock me-1 text-muted"></i>
                Jobs marked with a secret guard need <code>CRON_SECRET</code> set in <code>.env</code> before they can be run from the web.
                Without one, schedule them via Windows Task Scheduler or Linux cron instead — those run with full CLI access and no secret.
            </p>
            <p class="mb-2">
                <i class="fas fa-shield-halved me-1 text-muted"></i>
                <strong>Security Digest</strong> and <strong>AI Booking Review</strong> bypass their own web-access guards when run from here,
                since you are already authenticated as an admin. The digest sends only when there is something to report.
            </p>
            <p class="mb-0">
                <i class="fas fa-hourglass-half me-1 text-muted"></i>
                Running a job here executes it immediately and in-process — heavy jobs (backups, campaigns) may take a few seconds and
                consume web-server time. For production automation, prefer the scheduled commands above.
            </p>
            <p class="mb-0 mt-2">
                <i class="fas fa-hourglass me-1 text-muted"></i>
                <strong>Membership Renewals</strong> allows a manual web run at most once per hour (its own guard) and refuses to run
                while another renewal process is in flight, so a double-charge can never happen.
            </p>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>
