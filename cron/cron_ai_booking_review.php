<?php
/**
 * Automated AI Booking Review Cron
 *
 * Finds all pending bookings and runs them through Gemini AI for automated
 * approve/reject decisions, then applies the recommendations.
 *
 * Configure strictness in the admin Bookings page (stored per-admin in DB).
 * Default strictness for cron: Balanced (configurable below).
 *
 * Schedule: 0 /6 * * * (every 6 hours)
 * Manual:   php cron/cron_ai_booking_review.php
 */

require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../includes/gemini_client.php';
require_once __DIR__ . '/../includes/send_email.php';
require_once __DIR__ . '/../includes/activity_log.php';

// -----------------------------------------------------------------------
// Configuration (loaded from DB cron_ai_settings table; falls back to defaults)
// -----------------------------------------------------------------------
$cron_settings = [];
$settings_result = $conn->query("SELECT setting_key, setting_value FROM cron_ai_settings");
if ($settings_result) {
    while ($srow = $settings_result->fetch_assoc()) {
        $cron_settings[$srow['setting_key']] = $srow['setting_value'];
    }
    $settings_result->free();
}

define('CRON_STRICTNESS', $cron_settings['strictness'] ?? 'Balanced');
define('CRON_BATCH_LIMIT', (int)($cron_settings['batch_limit'] ?? 50));

// -----------------------------------------------------------------------
// Access control (bypassed for manual trigger via CRON_MANUAL_RUN constant)
// -----------------------------------------------------------------------
if (!defined('CRON_MANUAL_RUN')) {
    if (($cron_settings['enabled'] ?? '1') !== '1') {
        echo '[AI Review] Cron is disabled in settings. Exiting.' . "\n";
        exit(0);
    }

    if (php_sapi_name() !== 'cli' && empty($_SERVER['HTTP_X_CRON_SECRET'])) {
        http_response_code(403);
        echo 'Access denied.' . "\n";
        exit(1);
    }

    if (!empty($_SERVER['HTTP_X_CRON_SECRET'])) {
        $cronSecret = getenv('CRON_SECRET');
        if (!$cronSecret || $_SERVER['HTTP_X_CRON_SECRET'] !== $cronSecret) {
            http_response_code(403);
            echo 'Invalid cron secret.' . "\n";
            exit(1);
        }
    }
}

// -----------------------------------------------------------------------
// Logging
// -----------------------------------------------------------------------
$logDir = __DIR__ . '/../logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
$logFile = $logDir . '/ai_review_' . date('Y-m-d') . '.log';

function log_ai_cron(string $message): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    echo $line;
    file_put_contents($logFile, $line, FILE_APPEND);
}

// -----------------------------------------------------------------------
// Main process
// -----------------------------------------------------------------------
log_ai_cron('=== AI Booking Review Cron Started ===');

$key_status = asc_gemini_api_key_status();
if (empty($key_status['ready'])) {
    log_ai_cron('✗ Gemini API key not configured. Aborting.');
    exit(1);
}

try {
    // Fetch pending bookings
    $pending = [];
    $sql = "SELECT b.booking_id, b.facility_id, b.booking_date, b.start_time, b.end_time,
                   m.first_name, m.last_name, m.email, m.phone_number,
                   f.name AS facility_name, f.type AS facility_type, f.capacity,
                   c.first_name AS coach_first, c.last_name AS coach_last,
                   s.name AS sport_name
            FROM bookings b
            LEFT JOIN members m ON b.member_id = m.member_id
            LEFT JOIN facilities f ON b.facility_id = f.facility_id
            LEFT JOIN coaches c ON b.coach_id = c.coach_id
            LEFT JOIN sports s ON b.sport_id = s.sport_id
            WHERE b.status = 'Pending'
            ORDER BY b.booking_date ASC, b.start_time ASC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $batch_limit = CRON_BATCH_LIMIT;
        $stmt->bind_param('i', $batch_limit);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pending[] = $row;
        }
        $stmt->close();
    }

    $count = count($pending);
    log_ai_cron("Found {$count} pending booking(s) to review.");

    if ($count === 0) {
        log_ai_cron('No pending bookings. Exiting.');
        exit(0);
    }

    // Build booking summary for Gemini with conflict detection
    $booking_summary = '';
    foreach ($pending as $b) {
        $coach = trim(($b['coach_first'] ?? '') . ' ' . ($b['coach_last'] ?? '')) ?: 'None assigned';

        // Check for overlapping approved bookings
        $conflict_info = '';
        if (!empty($b['facility_name'])) {
            $cf_stmt = $conn->prepare("SELECT COUNT(*) AS overlap_count FROM bookings b2
                WHERE b2.facility_id = ? AND b2.booking_id != ?
                AND b2.booking_date = ?
                AND b2.status IN ('Approved','Confirmed')
                AND b2.start_time < ? AND b2.end_time > ?");
            if ($cf_stmt) {
                $cf_fid = $b['facility_id'];
                $cf_bid = $b['booking_id'];
                $cf_bdate = $b['booking_date'];
                $cf_stime = $b['end_time'];
                $cf_etime = $b['start_time'];
                $cf_stmt->bind_param('iisss', $cf_fid, $cf_bid, $cf_bdate, $cf_stime, $cf_etime);
                $cf_stmt->execute();
                $cf_r = $cf_stmt->get_result();
                if ($cf_row = $cf_r->fetch_assoc()) {
                    if ((int)$cf_row['overlap_count'] > 0) {
                        $conflict_info = "  ⚠️ CONFLICT: {$cf_row['overlap_count']} existing approved booking(s) overlap!\n";
                    }
                }
                $cf_stmt->close();
            }
        }

        $booking_summary .= sprintf(
            "Booking #%d:\n  Member: %s %s (%s, Tel: %s)\n  Sport: %s\n  Facility: %s (%s, capacity %s)\n  Coach: %s\n  Date: %s\n  Time: %s - %s\n%s\n",
            $b['booking_id'],
            $b['first_name'], $b['last_name'], $b['email'], $b['phone_number'] ?? 'N/A',
            $b['sport_name'] ?? 'N/A',
            $b['facility_name'] ?? 'N/A', $b['facility_type'] ?? 'N/A', $b['capacity'] ?? 'N/A',
            $coach,
            $b['booking_date'],
            $b['start_time'], $b['end_time'],
            $conflict_info
        );
    }

    // Build prompt with strictness
    $strictness_prompts = [
        'Conservative' => "You are a STRICT AI booking manager. Only APPROVE bookings that meet ALL criteria perfectly.\n"
            . "REJECT if anything seems off. Guidelines:\n"
            . "- Time MUST be between 07:00 and 20:00\n"
            . "- Facility type MUST match the sport\n"
            . "- Coach specialization SHOULD match the sport\n"
            . "- All member contact details MUST be present\n"
            . "- Duration MUST be 2 hours or less\n"
            . "When in doubt, REJECT.\n\n",
        'Balanced' => "You are an AI booking manager. Review each booking fairly.\n"
            . "Guidelines:\n"
            . "- Time should be between 06:00 and 22:00\n"
            . "- Facility type should match the sport\n"
            . "- Coach specialization should match the sport where possible\n"
            . "- Member details look valid\n"
            . "- Duration up to 4 hours is fine.\n\n",
        'Liberal' => "You are a LIBERAL AI booking manager. Approve bookings whenever reasonable.\n"
            . "Only REJECT for clear problems:\n"
            . "- Time must be between 05:00 and 23:00\n"
            . "- Facility type mismatch (e.g. football on a chess table) - but still flag it\n"
            . "- Missing critical contact info\n"
            . "- Extreme duration (over 6 hours)\n"
            . "Give members the benefit of the doubt. APPROVE more often.\n\n",
    ];

    // Use custom prompt if Custom mode is selected
    if (CRON_STRICTNESS === 'Custom') {
        $custom_text = $cron_settings['custom_prompt_text'] ?? '';
        $guidelines = !empty($custom_text) ? $custom_text : $strictness_prompts['Balanced'];
        $temperature = (float)($cron_settings['custom_prompt_temperature'] ?? 0.20);
    } else {
        $guidelines = $strictness_prompts[CRON_STRICTNESS] ?? $strictness_prompts['Balanced'];
        $temperature = CRON_STRICTNESS === 'Conservative' ? 0.1 : (CRON_STRICTNESS === 'Liberal' ? 0.4 : 0.2);
    }

    $prompt = "You are an AI booking manager for Apex Sports Club. Review each pending booking below and decide whether to APPROVE or REJECT each one.\n\n"
        . $guidelines
        . "Respond with ONLY a valid JSON array. Each object must have: booking_id, decision (\"APPROVE\" or \"REJECT\"), reason (short explanation).\n\n"
        . "Pending bookings to review:\n\n" . $booking_summary;

    log_ai_cron('Sending request to Gemini (strictness: ' . CRON_STRICTNESS . ')...');

    $result = asc_gemini_generate_text($prompt, [
        'temperature' => $temperature,
        'maxOutputTokens' => 2000,
        'timeout' => 30,
    ]);

    if (empty($result['success'])) {
        log_ai_cron('✗ Gemini API error: ' . ($result['error'] ?? 'Unknown error'));
        exit(1);
    }

    $text = trim($result['text']);

    // Parse JSON from response
    $json_str = '';
    if (preg_match('/```json\s*([\s\S]*?)\s*```/', $text, $m)) {
        $json_str = trim($m[1]);
    } elseif (preg_match('/\[\s*\{[\s\S]*\}\]/', $text, $m)) {
        $json_str = $m[0];
    } else {
        $json_str = $text;
    }

    $parsed = json_decode($json_str, true);
    if (!is_array($parsed)) {
        log_ai_cron('✗ Could not parse Gemini response as JSON.');
        exit(1);
    }

    // Validate and log all decisions
    $decisions = [];
    foreach ($parsed as $item) {
        if (isset($item['booking_id'], $item['decision']) && in_array($item['decision'], ['APPROVE', 'REJECT'])) {
            $decisions[] = $item;
        }
    }

    log_ai_cron('Received ' . count($decisions) . ' valid decision(s) from Gemini.');

    // Log to ai_review_log and apply decisions
    $approved = 0;
    $rejected = 0;
    $failed = 0;

    foreach ($decisions as $item) {
        $booking_id = (int)$item['booking_id'];
        $db_decision = $item['decision'] === 'APPROVE' ? 'Approved' : 'Rejected';
        $reason = substr($item['reason'] ?? '', 0, 500);

        // Log to ai_review_log (admin_id = 0 means cron)
        $log_stmt = $conn->prepare(
            "INSERT INTO ai_review_log (booking_id, admin_id, decision, reason, applied) VALUES (?, 0, ?, ?, 1)"
        );
        if ($log_stmt) {
            $dec = $item['decision'];
            $log_stmt->bind_param('iss', $booking_id, $dec, $reason);
            $log_stmt->execute();
            $log_stmt->close();
        }

        // Apply the decision
        $stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE booking_id = ? AND status = 'Pending'");
        if ($stmt) {
            $stmt->bind_param('si', $db_decision, $booking_id);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                if ($db_decision === 'Approved') $approved++;
                else $rejected++;

                log_activity($conn, 'AI ' . $db_decision . ' booking (cron)', 'Bookings', $booking_id, $reason);

                // Send email notification
                $notice_stmt = $conn->prepare(
                    "SELECT m.first_name, m.last_name, m.email, s.name AS sport_name, b.booking_date
                     FROM bookings b
                     LEFT JOIN members m ON b.member_id = m.member_id
                     LEFT JOIN sports s ON b.sport_id = s.sport_id
                     WHERE b.booking_id = ? LIMIT 1"
                );
                if ($notice_stmt) {
                    $notice_stmt->bind_param('i', $booking_id);
                    $notice_stmt->execute();
                    $info = $notice_stmt->get_result()->fetch_assoc();
                    $notice_stmt->close();

                    if ($info && !empty($info['email'])) {
                        $member_name = trim(($info['first_name'] ?? '') . ' ' . ($info['last_name'] ?? ''));
                        sendEmail(
                            $info['email'],
                            $member_name ?: 'Member',
                            'Booking ' . $db_decision . ' - Apex Sports Club',
                            emailBookingStatusUpdate(
                                $info['first_name'] ?: 'Member',
                                $info['sport_name'] ?: 'your booking',
                                $info['booking_date'] ?: '',
                                $db_decision
                            )
                        );
                    }
                }
            } else {
                $failed++;
            }
            $stmt->close();
        }
    }

    log_ai_cron("Results: {$approved} approved, {$rejected} rejected, {$failed} failed/skipped.");

    // Send summary email to all admins
    $summary_sent = 0;
    $admins_result = $conn->query("SELECT email FROM admins");
    if ($admins_result) {
        while ($admin = $admins_result->fetch_assoc()) {
            if (!empty($admin['email'])) {
                sendEmail(
                    $admin['email'],
                    'Admin',
                    'AI Review Summary (Cron) - ' . ($approved + $rejected) . ' bookings processed',
                    emailAIReviewSummary($approved + $rejected, $approved, $rejected, CRON_STRICTNESS)
                );
                $summary_sent++;
            }
        }
        $admins_result->free();
    }

    log_ai_cron("Summary email sent to {$summary_sent} admin(s).");
    log_ai_cron('=== AI Booking Review Cron Completed ===');

} catch (Exception $e) {
    log_ai_cron('ERROR: ' . $e->getMessage());
    exit(1);
}
