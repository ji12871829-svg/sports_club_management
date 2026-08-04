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

if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

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
              <div style='background:#2563eb;padding:28px 24px;text-align:center;'>
                <h2 style='color:#fff;margin:0;font-size:20px;font-weight:800;'>⏰ Membership Expiring Soon</h2>
              </div>
              <div style='padding:28px 24px;color:#334155;'>
                <p>Hi <strong>" . e($row['first_name']) . "</strong>,</p>
                <p>Your <strong>" . e($row['plan_name']) . "</strong> membership expires on <strong>{$end_fmt}</strong> ({$days_left} day(s) remaining).</p>
                <p>Renew now to keep your access to all club services.</p>
                <div style='text-align:center;margin-top:24px;'>
                  <a href='{$renew_url}' style='display:inline-block;background:#2563eb;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;'>Renew Membership →</a>
                </div>
              </div>
              <div style='background:#f8fafc;padding:14px;text-align:center;color:#94a3b8;font-size:12px;'>Apex Sports Club · Membership Reminders</div>
            </div>";

            $name = $row['first_name'] . ' ' . $row['last_name'];
            if (sendEmail($row['email'], $name, $subject, $html)) {
                log_activity($conn, 'Sent manual renewal reminder', 'Memberships', $membership_id,
                    "To: {$row['email']}, expires {$row['end_date']}");
                $message = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Reminder sent to <strong>" . e($row['email']) . "</strong>.</div>";
            } else {
                $message = "<div class='alert alert-danger'>Failed to send email. Check your Brevo API key.</div>";
            }
        }
    } elseif ($action === 'run_cron') {
        ob_start();
        if (file_exists(__DIR__ . '/../cron/cron_membership_renewal.php')) {
            require __DIR__ . '/../cron/cron_membership_renewal.php';
        }
        $out = ob_get_clean();
        log_activity($conn, 'Manually ran membership renewal cron', 'Memberships');
        $message = "<div class='alert alert-success'><i class='fas fa-check-circle me-2'></i>Cron job ran.<pre class='small bg-light p-2 rounded mt-2 mb-0'>" . e($out ?: 'No output.') . "</pre></div>";
    }
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
.stat-pill { border-radius: 12px; padding: 20px; color: #fff; }
</style>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:#2563eb;">
            <i class="fas fa-bell text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">Membership Renewal Reminders</h1>
            <p class="text-muted mb-0 small">Monitor expiring memberships and send reminder emails</p>
        </div>
        <form method="POST" class="ms-auto">
            <input type="hidden" name="action" value="run_cron">
            <button type="submit" class="btn btn-primary"
                    onclick="return confirm('Run the renewal cron job now?')">
                <i class="fas fa-play me-2"></i>Run Renewal Job Now
            </button>
        </form>
    </div>

    <?php if ($message) echo $message; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4">
        <div class="col-sm-4">
            <div class="stat-pill" style="background:#2563eb;">
                <div class="fs-2 fw-bold"><?php echo $total_active; ?></div>
                <div class="small opacity-75">Active Memberships</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="stat-pill" style="background:#d97706;">
                <div class="fs-2 fw-bold"><?php echo $expiring_7; ?></div>
                <div class="small opacity-75">Expiring in 7 Days</div>
            </div>
        </div>
        <div class="col-sm-4">
            <div class="stat-pill" style="background:#dc2626;">
                <div class="fs-2 fw-bold"><?php echo $expired; ?></div>
                <div class="small opacity-75">Already Expired</div>
            </div>
        </div>
    </div>

    <!-- Expiring table -->
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex align-items-center">
            <i class="fas fa-clock text-warning me-2"></i>
            <strong>Expiring / Recently Expired</strong>
            <span class="badge bg-warning text-dark ms-2"><?php echo count($expiring); ?></span>
        </div>
        <div class="card-body p-0">
            <?php if (empty($expiring)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-check-circle fa-2x mb-2 d-block text-success"></i>
                    No memberships expiring soon.
                </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Member</th><th>Plan</th><th>Expires</th>
                            <th>Days Left</th><th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expiring as $r):
                            $days = (int)$r['days_left'];
                        ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo e($r['first_name'].' '.$r['last_name']); ?></div>
                                <small class="text-muted"><?php echo e($r['email']); ?></small>
                            </td>
                            <td><?php echo e($r['plan_name']); ?></td>
                            <td><?php echo e(date('d M Y', strtotime($r['end_date']))); ?></td>
                            <td>
                                <?php if ($days < 0): ?>
                                    <span class="badge bg-danger">Expired <?php echo abs($days); ?>d ago</span>
                                <?php elseif ($days <= 1): ?>
                                    <span class="badge bg-danger"><?php echo $days; ?> day<?php echo $days !== 1 ? 's' : ''; ?></span>
                                <?php elseif ($days <= 3): ?>
                                    <span class="badge bg-warning text-dark"><?php echo $days; ?> days</span>
                                <?php else: ?>
                                    <span class="badge bg-success"><?php echo $days; ?> days</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="action" value="send_reminder">
                                    <input type="hidden" name="membership_id" value="<?php echo e($r['membership_id']); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        <i class="fas fa-envelope me-1"></i>Send Reminder
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

    <!-- Setup note -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-white"><i class="fas fa-clock me-2 text-primary"></i><strong>Automate with Windows Task Scheduler</strong></div>
        <div class="card-body small text-muted">
            <ol class="mb-0" style="line-height:2.2">
                <li>Open <strong>Task Scheduler</strong> → <strong>Create Basic Task</strong></li>
                <li>Trigger: <strong>Daily at 08:00 AM</strong></li>
                <li>Program: <code>C:\xampp\php\php.exe</code></li>
                <li>Arguments: <code>"C:\xampp\htdocs\Apex Sports Club\cron\cron_membership_renewal.php"</code></li>
            </ol>
        </div>
    </div>
</div>

