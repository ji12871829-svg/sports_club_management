<?php
/**
 * Attendance Alert Cron
 * Sends alerts when a member misses 3+ consecutive training sessions.
 * Run daily: php cron/cron_attendance_alert.php
 * Or via HTTP (with CRON_SECRET): GET /cron/cron_attendance_alert.php?secret=YOUR_CRON_SECRET
 */

$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    // HTTP auth via secret
    require_once __DIR__ . '/../config/api_config.php';
    $secret = defined('CRON_SECRET') ? (string)CRON_SECRET : '';
    if ($secret === '' || ($_GET['secret'] ?? '') !== $secret) {
        http_response_code(403);
        exit('Forbidden');
    }
}

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../includes/send_email.php';

$CONSECUTIVE_MISS_THRESHOLD = 3;  // flag after this many consecutive absences
$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/attendance_alert_' . date('Y-m-d') . '.log';

if (!is_dir($logDir)) mkdir($logDir, 0775, true);

function cron_log(string $msg): void {
    global $logFile;
    $line = '[' . date('H:i:s') . '] ' . $msg . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
    if (php_sapi_name() === 'cli') echo $line;
}

cron_log('=== Attendance Alert Cron Started ===');

// ── Check if required tables exist ───────────────────────────────────────────
$chk = $conn->query("SHOW TABLES LIKE 'attendance'");
if (!$chk || $chk->num_rows === 0) {
    cron_log('ERROR: attendance table not found. Skipping.');
    exit;
}

// ── Find members with 3+ consecutive absences using SQL window functions ─────
// Uses a ranked approach instead of loading 60 days of records into PHP
$sql = "
WITH ranked AS (
    SELECT a.member_id, a.session_date, a.status,
           ROW_NUMBER() OVER (PARTITION BY a.member_id ORDER BY a.session_date DESC) AS rn
    FROM attendance a
    WHERE a.session_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
),
consecutive AS (
    SELECT member_id, session_date, status, rn,
           rn - ROW_NUMBER() OVER (PARTITION BY member_id, status ORDER BY session_date DESC) AS grp
    FROM ranked
    WHERE LOWER(TRIM(status)) IN ('absent', 'no-show', 'missed', '0')
),
counts AS (
    SELECT member_id, MIN(session_date) AS first_miss, MAX(session_date) AS last_miss,
           COUNT(*) AS consecutive_misses
    FROM consecutive
    GROUP BY member_id, grp
    HAVING COUNT(*) >= ?
)
SELECT c.member_id, c.consecutive_misses,
       m.first_name, m.last_name, m.email,
       CONCAT(co.first_name, ' ', co.last_name) AS coach_name,
       co.email AS coach_email
FROM counts c
JOIN members m ON m.member_id = c.member_id
LEFT JOIN attendance a2 ON a2.member_id = c.member_id
    AND a2.session_date = c.last_miss
LEFT JOIN coaches co ON co.coach_id = a2.coach_id
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    // Fallback for MySQL < 8.0 (no CTE support) — use simpler query
    cron_log('CTE not supported, falling back to PHP-based detection');
    $sql_fallback = "
    SELECT a.member_id, a.session_date, a.status,
           CONCAT(m.first_name, ' ', m.last_name) AS member_name,
           m.first_name, m.last_name, m.email
    FROM attendance a
    JOIN members m ON m.member_id = a.member_id
    WHERE a.session_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
    ORDER BY a.member_id, a.session_date DESC
    ";
    $res = $conn->query($sql_fallback);
    if (!$res) {
        cron_log('ERROR: Could not fetch attendance records: ' . $conn->error);
        exit;
    }

    $byMember = [];
    while ($row = $res->fetch_assoc()) {
        $byMember[$row['member_id']][] = $row;
    }

    $alertsSent = 0;
    foreach ($byMember as $memberId => $records) {
        $consecutiveMisses = 0;
        $memberInfo = $records[0];
        foreach ($records as $rec) {
            $status = strtolower(trim($rec['status']));
            if (in_array($status, ['absent', 'no-show', 'missed', '0'], true)) {
                $consecutiveMisses++;
            } else {
                break;
            }
        }
        if ($consecutiveMisses < $CONSECUTIVE_MISS_THRESHOLD) continue;
        // Delegate to shared alert sender
        send_attendance_alert($conn, $memberId, $consecutiveMisses, $memberInfo, $alertsSent);
    }
} else {
    $stmt->bind_param('i', $CONSECUTIVE_MISS_THRESHOLD);
    $stmt->execute();
    $result = $stmt->get_result();
    $alertsSent = 0;

    while ($row = $result->fetch_assoc()) {
        cron_log("⚠️  Member #{$row['member_id']} ({$row['first_name']} {$row['last_name']}) — {$row['consecutive_misses']} consecutive absences");
        send_attendance_alert($conn, (int)$row['member_id'], (int)$row['consecutive_misses'], $row, $alertsSent);
    }
    $stmt->close();
}

$conn->close();
cron_log("=== Done. {$alertsSent} alerts sent. ===");

if (!$isCli) {
    header('Content-Type: text/plain');
    echo "Done. {$alertsSent} attendance alerts processed.";
}

// ── Shared alert sender ────────────────────────────────────────────────────
function send_attendance_alert(mysqli $conn, int $memberId, int $consecutiveMisses, array $memberInfo, int &$alertsSent): void
{
    global $CONSECUTIVE_MISS_THRESHOLD;

    // ── Email to member ───────────────────────────────────────────────────────
    $memberSubject = "We miss you, {$memberInfo['first_name']}! Come back to training";
    $memberBody    = "
<p>Hi <strong>{$memberInfo['first_name']}</strong>,</p>
<p>We've noticed you've missed your last <strong>{$consecutiveMisses} training sessions</strong> at Apex Sports Club.</p>
<p>We hope everything is okay! Regular training is key to reaching your goals, and the team misses having you around.</p>
<p>If there's anything stopping you from attending — an injury, scheduling conflict, or something else — please reach out and we'll do our best to help.</p>
<p><a href='#' style='background:#4f46e5;color:#fff;padding:.6rem 1.2rem;border-radius:8px;text-decoration:none;font-weight:700;'>Book Your Next Session</a></p>
<p style='color:#64748b;font-size:.9rem;'>Your attendance streak matters. Let's get back on track together!</p>
<p>— The Apex Sports Club Team</p>
";

    if (function_exists('send_club_email')) {
        $sent = send_club_email($memberInfo['email'], $memberInfo['first_name'] . ' ' . $memberInfo['last_name'], $memberSubject, $memberBody);
        cron_log($sent ? "  ✅ Member alert email sent to {$memberInfo['email']}" : "  ❌ Failed to send member email");
    }

    // ── Email to coach ────────────────────────────────────────────────────────
    if (!empty($memberInfo['coach_email'])) {
        $coachSubject = "Attendance Alert: {$memberInfo['first_name']} {$memberInfo['last_name']} — {$consecutiveMisses} missed sessions";
        $coachBody    = "
<p>Hi <strong>{$memberInfo['coach_name']}</strong>,</p>
<p>This is an automated alert. <strong>{$memberInfo['first_name']} {$memberInfo['last_name']}</strong> has missed their last <strong>{$consecutiveMisses} consecutive training sessions</strong>.</p>
<p>Please consider reaching out to check on them. Early contact can help retain members and address any issues.</p>
<p style='color:#64748b;font-size:.9rem;'>This alert was triggered automatically by the Apex Sports Club management system.</p>
";
        if (function_exists('send_club_email')) {
            $sent2 = send_club_email($memberInfo['coach_email'], $memberInfo['coach_name'], $coachSubject, $coachBody);
            cron_log($sent2 ? "  ✅ Coach alert email sent to {$memberInfo['coach_email']}" : "  ❌ Failed to send coach email");
        }
    }

    $alertsSent++;
}


