<?php
/**
 * cron_profiler_alert.php
 * Daily slow-page digest. Summarizes page_timings entries slower than
 * ASC_PROFILER_ALERT_MS (default 500 ms) from the last 24 hours and emails
 * the report via the Brevo helper (includes/send_email.php).
 *
 * DISABLED unless .env sets:  ASC_PROFILER_EMAIL_TO=you@example.com
 * Threshold override:          ASC_PROFILER_ALERT_MS=500
 *
 * Schedule (Windows Task Scheduler or cron):
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Apex Sports Club\cron_profiler_alert.php
 *   /c/xampp/php/php.exe /c/xampp/htdocs/Apex Sports Club/cron_profiler_alert.php
 *
 * Run manually:  php cron_profiler_alert.php
 */

require_once __DIR__ . '/config/db_connect.php';
require_once __DIR__ . '/includes/send_email.php';

$to = getenv('ASC_PROFILER_EMAIL_TO');
if ($to === false || trim($to) === '') {
    fwrite(STDERR, "ASC_PROFILER_EMAIL_TO not set in .env — alert disabled. Nothing to do.\n");
    exit(0);
}

$alertMs = getenv('ASC_PROFILER_ALERT_MS');
$alertMs = ($alertMs !== false && is_numeric($alertMs)) ? (int) $alertMs : 500;

$rows = [];
$totalMs = 0;
$r = $conn->query(
    "SELECT page, duration_ms, query_count, memory_mb, created_at
     FROM page_timings
     WHERE duration_ms >= " . (int) $alertMs . "
       AND created_at >= NOW() - INTERVAL 24 HOUR
     ORDER BY duration_ms DESC
     LIMIT 50"
);
if ($r) {
    while ($row = $r->fetch_assoc()) {
        $rows[] = $row;
        $totalMs += (float) $row['duration_ms'];
    }
    $r->free();
}

if (empty($rows)) {
    echo "No pages slower than {$alertMs} ms in the last 24 hours. No email sent.\n";
    $conn->close();
    exit(0);
}

$avg = round($totalMs / count($rows), 1);
$trs = '';
foreach ($rows as $row) {
    $badge = ($row['duration_ms'] >= 2000) ? '#dc2626' : (($row['duration_ms'] >= 800) ? '#d97706' : '#64748b');
    $trs .= '<tr>'
        . '<td style="padding:8px;border:1px solid #e5e7eb;font-family:monospace;font-size:12px;">' . htmlspecialchars($row['page']) . '</td>'
        . '<td style="padding:8px;border:1px solid #e5e7eb;color:' . $badge . ';font-weight:700;">' . number_format((float)$row['duration_ms'], 1) . ' ms</td>'
        . '<td style="padding:8px;border:1px solid #e5e7eb;">' . (int)$row['query_count'] . '</td>'
        . '<td style="padding:8px;border:1px solid #e5e7eb;">' . number_format((float)$row['memory_mb'], 1) . ' MB</td>'
        . '<td style="padding:8px;border:1px solid #e5e7eb;">' . date('d M H:i', strtotime($row['created_at'])) . '</td>'
        . '</tr>';
}

$body = '
<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">
  <div style="background:#0f172a;padding:20px 24px;">
    <h1 style="color:#fff;margin:0;font-size:18px;">⚡ Apex Sports Club — Slow Page Digest</h1>
  </div>
  <div style="padding:24px;">
    <p style="font-size:14px;color:#334155;">' . count($rows) . ' page(s) slower than <strong>' . (int)$alertMs . ' ms</strong> in the last 24 hours (avg ' . $avg . ' ms).</p>
    <table style="width:100%;border-collapse:collapse;margin-top:12px;">
      <thead>
        <tr>
          <th style="padding:8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">Page</th>
          <th style="padding:8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">Duration</th>
          <th style="padding:8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">Queries</th>
          <th style="padding:8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">Memory</th>
          <th style="padding:8px;border:1px solid #e5e7eb;background:#f8fafc;text-align:left;">Recorded</th>
        </tr>
      </thead>
      <tbody>' . $trs . '</tbody>
    </table>
    <p style="font-size:12px;color:#94a3b8;margin-top:16px;">View details in the admin panel: Slow Pages. Auto-email is controlled by ASC_PROFILER_EMAIL_TO in .env.</p>
  </div>
</div>';

$ok = sendEmail($to, 'Apex Admin', 'Apex Sports Club — ' . count($rows) . ' slow page(s) in the last 24h', $body);
$conn->close();

echo $ok ? "Alert email sent to {$to} (" . count($rows) . " pages).\n" : "Email send FAILED.\n";
exit($ok ? 0 : 1);
