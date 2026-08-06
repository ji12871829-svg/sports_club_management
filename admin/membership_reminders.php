<?php
/**
 * admin/membership_reminders.php
 * View expiring memberships and manually trigger reminder emails.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';
require_once '../includes/send_email.php';
require_once '../includes/url.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';

// ── Manual send single reminder ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_reminder') {
        $membership_id = (int)($_POST['membership_id'] ?? 0);
        $stmt = $conn->prepare("
            SELECT mm.*, m.first_name, m.last_name, m.email, mp.name AS plan_name, mp.price
            FROM member_memberships mm
            JOIN members m ON m.member_id = mm.member_id
            JOIN membership_plans mp ON mp.plan_id = mm.plan_id
            WHERE mm.membership_id = ?
        ");
        $stmt->bind_param("i", $membership_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            $days_left   = (int)ceil((strtotime($row['end_date']) - time()) / 86400);
            $subject     = "⏰ Your {$row['plan_name']} membership expires in {$days_left} day(s) — Apex Sports Club";
            $renew_url   = app_absolute_url('public/memberships.php');
            $end_fmt     = date('l, d F Y', strtotime($row['end_date']));
            $html = "
            <div style='font-family:sans-serif;max-width:540px;margin:30px auto;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;background:#fff;'>
              <div style='background:#1d5c8f;padding:28px 24px;text-align:center;'>
                <h2 style='color:#fff;margin:0;font-size:20px;font-weight:800;'>⏰ Membership Expiring Soon</h2>
              </div>
              <div style='padding:28px 24px;color:#334155;'>
                <p>Hi <strong>" . e($row['first_name']) . "</strong>,</p>
                <p>Your <strong>" . e($row['plan_name']) . "</strong> membership expires on <strong>{$end_fmt}</strong> ({$days_left} day(s) remaining).</p>
                <p>Renew now to keep your access to all club services.</p>
                <div style='text-align:center;margin-top:24px;'>
                  <a href='{$renew_url}' style='display:inline-block;background:#1d5c8f;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;'>Renew Membership →</a>
                </div>
              </div>
              <div style='background:#f8fafc;padding:14px;text-align:center;color:#94a3b8;font-size:12px;'>Apex Sports Club · Membership Reminders</div>
            </div>";

            $name = $row['first_name'] . ' ' . $row['last_name'];
            if (sendEmail($row['email'], $name, $subject, $html)) {
                log_activity($conn, 'Sent manual renewal reminder', 'Memberships', $membership_id,
                    "To: {$row['email']}, expires {$row['end_date']}");
                $message = "
                <div class='alert alert-success border-0 shadow-sm d-flex align-items-center' role='alert'>
                    <i class='fas fa-check-circle me-3 text-success fs-5'></i>
                    <div>
                        Reminder sent to <strong>" . e($row['email']) . "</strong>.
                    </div>
                </div>";
            } else {
                $message = "
                <div class='alert alert-danger border-0 shadow-sm d-flex align-items-center' role='alert'>
                    <i class='fas fa-exclamation-triangle me-3 text-danger fs-5'></i>
                    <div>
                        Failed to send email. Check your Brevo API key.
                    </div>
                </div>";
            }
        }
    } elseif ($action === 'run_cron') {
        ob_start();
        if (file_exists(__DIR__ . '/../cron/cron_membership_renewal.php')) {
            require __DIR__ . '/../cron/cron_membership_renewal.php';
        }
        $out = ob_get_clean();
        log_activity($conn, 'Manually ran membership renewal cron', 'Memberships');
        $message = "
        <div class='alert alert-success border-0 shadow-sm' role='alert'>
            <div class='d-flex align-items-center mb-2'>
                <i class='fas fa-check-circle me-3 text-success fs-5'></i>
                <div class='fw-semibold text-success-dark'>Cron job ran.</div>
            </div>
            <pre class='small bg-white border p-2.5 rounded text-secondary mb-0 font-monospace' style='font-size: 0.8rem;'>" . e($out ?: 'No output.') . "</pre>
        </div>";
    }
    } elseif ($action === 'run_late_reminders') {
        ob_start();
        if (file_exists(__DIR__ . '/../cron/cron_late_payment_reminders.php')) {
            require __DIR__ . '/../cron/cron_late_payment_reminders.php';
        }
        $out = ob_get_clean();
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Late payment reminders sent.</div>';
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$total_active = (int)$conn->query("SELECT COUNT(*) FROM member_memberships WHERE status='Active'")->fetch_row()[0];
$expiring_7   = (int)$conn->query("SELECT COUNT(*) FROM member_memberships WHERE status='Active' AND DATEDIFF(end_date,CURDATE()) BETWEEN 0 AND 7")->fetch_row()[0];
$expired      = (int)$conn->query("SELECT COUNT(*) FROM member_memberships WHERE status='Active' AND end_date < CURDATE()")->fetch_row()[0];

// Expiring in next 30 days
$expiring = $conn->query("
    SELECT mm.membership_id, mm.end_date, mm.status,
           DATEDIFF(mm.end_date, CURDATE()) AS days_left,
           m.first_name, m.last_name, m.email,
           mp.name AS plan_name, mp.price
    FROM member_memberships mm
    JOIN members m ON m.member_id = mm.member_id
    JOIN membership_plans mp ON mp.plan_id = mm.plan_id
    WHERE mm.status = 'Active'
      AND mm.end_date BETWEEN DATE_SUB(CURDATE(),INTERVAL 3 DAY) AND DATE_ADD(CURDATE(),INTERVAL 30 DAY)
    ORDER BY mm.end_date ASC
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<style>
    body { background-color: #f8fafc !important; color: #334155 !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    .page-header-text { font-size: 1.5rem; font-weight: 700; color: #0f172a; letter-spacing: -0.025em; }
    
    /* Premium Performance Stats Framework */
    .dashboard-stat-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); padding: 1.5rem; display: flex; align-items: center; justify-content: space-between; position: relative; overflow: hidden; }
    .dashboard-stat-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
    .stat-blue::before { background-color: #1d5c8f; }
    .stat-amber::before { background-color: #d97706; }
    .stat-rose::before { background-color: #dc2626; }
    .stat-metric { font-size: 2rem; font-weight: 700; color: #0f172a; line-height: 1; margin-bottom: 0.25rem; }
    .stat-label { font-size: 0.775rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em; }
    
    /* Workspace Containers */
    .dashboard-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); overflow: hidden; }
    .card-header-frame { font-size: 0.95rem; font-weight: 600; color: #0f172a; padding: 1.25rem 1.5rem; border-bottom: 1px solid #f1f5f9; background: #ffffff; display: flex; align-items: center; }
    
    /* Actions & Form Element Baselines */
    .btn-action-primary { background-color: #0f172a !important; color: #ffffff !important; border: none !important; border-radius: 8px !important; padding: 0.55rem 1.25rem !important; font-size: 0.875rem !important; font-weight: 500 !important; transition: background-color 0.1s ease !important; }
    .btn-action-primary:hover { background-color: #1e293b !important; }
    .btn-action-outline { color: #475569 !important; background-color: #ffffff !important; border: 1px solid #cbd5e1 !important; border-radius: 6px !important; font-size: 0.825rem !important; font-weight: 500 !important; padding: 0.35rem 0.75rem !important; transition: all 0.15s ease !important; }
    .btn-action-outline:hover { background-color: #f8fafc !important; color: #0f172a !important; border-color: #94a3b8 !important; }

    /* Minimal High-Density Tables */
    .table-container th { background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.85rem 1.5rem; }
    .table-container td { padding: 1rem 1.5rem; border-bottom: 1px solid #f1f5f9; font-size: 0.875rem; color: #334155; vertical-align: middle; }
    .table-container tr:hover td { background-color: #fafafa; }
    
    /* Clean Context Badge Capsules */
    .sys-badge { display: inline-flex; align-items: center; padding: 0.25rem 0.5rem; font-size: 0.75rem; font-weight: 600; border-radius: 6px; line-height: 1; border: 1px solid transparent; }
    .badge-danger-soft { background-color: #fef2f2; color: #dc2626; border-color: #fecaca; }
    .badge-warning-soft { background-color: #fef3c7; color: #d97706; border-color: #fde68a; }
    .badge-success-soft { background-color: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
</style>

<div class="container-fluid py-4 px-md-4">
    
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-white border shadow-sm" style="width:46px; height:46px;">
                <i class="fas fa-bell text-dark"></i>
            </div>
            <div>
                <h1 class="page-header-text mb-0">Membership Renewal Reminders</h1>
                <p class="text-muted mb-0 small">Monitor expiring memberships and send reminder emails</p>
            </div>
        </div>
        <div class="d-flex gap-2 ms-sm-auto flex-wrap">
            <form method="POST">
                <input type="hidden" name="action" value="run_cron">
                <button type="submit" class="btn btn-action-primary" onclick="return confirm('Run the renewal cron job now?')">
                    <i class="fas fa-play me-2" style="font-size: 0.775rem;"></i>Run Renewal Job Now
                </button>
            </form>
            <form method="POST">
                <input type="hidden" name="action" value="run_late_reminders">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Send late payment reminders via WhatsApp + email?')">
                    <i class="fab fa-whatsapp me-2"></i>Send Late Payment Reminders
                </button>
            </form>
        </div>
    </div>

    <?php if ($message) echo $message; ?>

    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="dashboard-stat-card stat-blue">
                <div>
                    <div class="stat-metric"><?php echo $total_active; ?></div>
                    <div class="stat-label">Active Memberships</div>
                </div>
                <i class="fas fa-user-check fa-lg text-slate-300 opacity-50"></i>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="dashboard-stat-card stat-amber">
                <div>
                    <div class="stat-metric"><?php echo $expiring_7; ?></div>
                    <div class="stat-label">Expiring in 7 Days</div>
                </div>
                <i class="fas fa-hourglass-half fa-lg text-slate-300 opacity-50"></i>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="dashboard-stat-card stat-rose">
                <div>
                    <div class="stat-metric"><?php echo $expired; ?></div>
                    <div class="stat-label">Already Expired</div>
                </div>
                <i class="fas fa-history fa-lg text-slate-300 opacity-50"></i>
            </div>
        </div>
    </div>

    <div class="card dashboard-card">
        <div class="card-header-frame">
            <i class="fas fa-clock text-warning me-2"></i>
            <span class="fw-semibold">Expiring / Recently Expired</span>
            <span class="badge bg-light text-dark border ms-2 px-2.5 py-1" style="font-size: 0.75rem; font-weight: 700;"><?php echo count($expiring); ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($expiring)): ?>
                <div class="text-center py-5 text-muted small">
                    <i class="fas fa-check-circle fa-2x mb-2 d-block text-success opacity-75"></i>
                    No memberships expiring soon.
                </div>
            <?php else: ?>
            <div class="table-responsive table-container">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Member</th>
                            <th>Plan</th>
                            <th>Expires</th>
                            <th>Days Left</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expiring as $r):
                            $days = (int)$r['days_left'];
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold text-slate-900"><?php echo e($r['first_name'].' '.$r['last_name']); ?></div>
                                <div class="small text-muted" style="font-size: 0.8rem;"><?php echo e($r['email']); ?></div>
                            </td>
                            <td class="fw-medium text-slate-700"><?php echo e($r['plan_name']); ?></td>
                            <td class="text-secondary"><?php echo e(date('d M Y', strtotime($r['end_date']))); ?></td>
                            <td>
                                <?php if ($days < 0): ?>
                                    <span class="sys-badge badge-danger-soft">Expired <?php echo abs($days); ?>d ago</span>
                                <?php elseif ($days <= 1): ?>
                                    <span class="sys-badge badge-danger-soft"><?php echo $days; ?> day<?php echo $days !== 1 ? 's' : ''; ?></span>
                                <?php elseif ($days <= 3): ?>
                                    <span class="sys-badge badge-warning-soft"><?php echo $days; ?> days</span>
                                <?php else: ?>
                                    <span class="sys-badge badge-success-soft"><?php echo $days; ?> days</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="send_reminder">
                                    <input type="hidden" name="membership_id" value="<?php echo e($r['membership_id']); ?>">
                                    <button type="submit" class="btn btn-action-outline">
                                        <i class="fas fa-envelope me-1.5" style="font-size: 0.75rem;"></i>Send Reminder
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card dashboard-card mt-4">
        <div class="card-header-frame" style="padding: 1rem 1.5rem; font-size: 0.875rem;">
            <i class="fas fa-tools me-2 text-slate-400"></i>
            <span class="fw-semibold text-slate-800">Automate with Windows Task Scheduler</span>
        </div>
        <div class="card-body p-4 bg-light-smooth" style="background-color: #fafafa;">
            <ol class="mb-0 text-secondary ps-3" style="line-height: 2.2; font-size: 0.85rem;">
                <li>Open <strong>Task Scheduler</strong> → <strong>Create Basic Task</strong></li>
                <li>Trigger: <strong>Daily at 08:00 AM</strong></li>
                <li>Program: <code class="bg-white border px-1.5 py-0.5 rounded text-dark">C:\xampp\php\php.exe</code></li>
                <li>Arguments: <code class="bg-white border px-1.5 py-0.5 rounded text-dark">"C:\xampp\htdocs\Apex Sports Club\cron\cron_membership_renewal.php"</code></li>
            </ol>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>

