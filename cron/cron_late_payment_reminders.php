<?php
/**
 * cron/cron_late_payment_reminders.php
 * Sends WhatsApp + email reminders to members with:
 *   - Overdue membership (expired but still Active)
 *   - Unpaid damage report fines
 *
 * Schedule daily at 09:00:
 *   Windows Task Scheduler:
 *     Program:   C:\xampp\php\php.exe
 *     Arguments: "C:\xampp\htdocs\Apex Sports Club\cron\cron_late_payment_reminders.php"
 *
 *   Linux cron: 0 9 * * * php /path/to/cron/cron_late_payment_reminders.php
 */

define('RUNNING_AS_CRON', true);
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/whatsapp.php';

$log    = [];
$log[]  = '[' . date('Y-m-d H:i:s') . '] Late payment reminder job started.';
$total_wa    = 0;
$total_email = 0;

// ── 1. Expired memberships (end_date passed, still Active) ───────────────────
$expired = $conn->query("
    SELECT mm.membership_id, mm.end_date,
           m.first_name, m.last_name, m.email, m.phone_number,
           mp.name AS plan_name, mp.price
    FROM member_memberships mm
    JOIN members m ON m.member_id = mm.member_id
    JOIN membership_plans mp ON mp.plan_id = mm.plan_id
    WHERE mm.status = 'Active'
      AND mm.end_date < CURDATE()
      AND m.email IS NOT NULL
")->fetch_all(MYSQLI_ASSOC);

$log[] = "  [Expired memberships] Found " . count($expired);

foreach ($expired as $row) {
    $days_overdue = (int)floor((time() - strtotime($row['end_date'])) / 86400);
    $name         = trim($row['first_name'] . ' ' . $row['last_name']);
    $renew_url    = APP_URL . '/public/memberships.php';

    // WhatsApp
    if ($row['phone_number']) {
        $wa_msg = "⚠️ Hi {$row['first_name']}, your Apex Sports Club {$row['plan_name']} membership expired {$days_overdue} day(s) ago.\n\nRenew now to keep your access: {$renew_url}";
        if (wa_notify($row['phone_number'], $wa_msg)) {
            $total_wa++;
            $log[] = "    ✓ WhatsApp sent to {$row['phone_number']} ({$name})";
        }
    }

    // Email
    $subject = "⚠️ Your membership has expired — Apex Sports Club";
    $html = "
    <div style='font-family:sans-serif;max-width:540px;margin:30px auto;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;background:#fff;'>
      <div style='background:#dc2626;padding:28px 24px;text-align:center;'>
        <h2 style='color:#fff;margin:0;font-size:20px;font-weight:800;'>⚠️ Membership Expired</h2>
      </div>
      <div style='padding:28px 24px;color:#334155;line-height:1.7;'>
        <p>Hi <strong>" . htmlspecialchars($row['first_name']) . "</strong>,</p>
        <p>Your <strong>" . htmlspecialchars($row['plan_name']) . "</strong> membership expired <strong>{$days_overdue} day(s) ago</strong> on " . date('d M Y', strtotime($row['end_date'])) . ".</p>
        <p>You currently have limited access to club services. Renew now to restore full access.</p>
        <div style='text-align:center;margin-top:24px;'>
          <a href='{$renew_url}' style='display:inline-block;background:#dc2626;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-weight:700;'>Renew Membership →</a>
        </div>
      </div>
      <div style='background:#f8fafc;padding:14px;text-align:center;color:#94a3b8;font-size:12px;'>Apex Sports Club · Membership Reminders</div>
    </div>";

    if (sendEmail($row['email'], $name, $subject, $html)) {
        $total_email++;
        $log[] = "    ✓ Email sent to {$row['email']} ({$name})";
    }
}

// ── 2. Unpaid damage report fines ────────────────────────────────────────────
$fines = $conn->query("
    SELECT dr.report_id, dr.fine_amount, dr.fine_status, dr.reported_date,
           e.name AS equipment_name,
           m.first_name, m.last_name, m.email, m.phone_number
    FROM damage_reports dr
    JOIN equipment e ON e.equipment_id = dr.equipment_id
    JOIN members m ON m.member_id = dr.member_id
    WHERE dr.fine_status = 'Unpaid'
      AND dr.fine_amount > 0
      AND dr.reported_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)
")->fetch_all(MYSQLI_ASSOC);

$log[] = "  [Unpaid fines] Found " . count($fines);

foreach ($fines as $row) {
    $name = trim($row['first_name'] . ' ' . $row['last_name']);

    if ($row['phone_number']) {
        $wa_msg = "💳 Hi {$row['first_name']}, you have an outstanding fine of KES " . number_format($row['fine_amount'], 2) . " for damaged equipment ({$row['equipment_name']}) at Apex Sports Club. Please pay at the club office or contact admin.";
        if (wa_notify($row['phone_number'], $wa_msg)) {
            $total_wa++;
            $log[] = "    ✓ Fine WhatsApp sent to {$row['phone_number']} ({$name})";
        }
    }
}

$conn->close();

$log[] = '[' . date('Y-m-d H:i:s') . "] Done. WhatsApp sent: {$total_wa}, Emails sent: {$total_email}";

// Write log
$log_dir = __DIR__ . '/logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);
file_put_contents($log_dir . '/late_payment_reminders.log', implode("\n", $log) . "\n", FILE_APPEND);

echo implode("\n", $log) . "\n";


