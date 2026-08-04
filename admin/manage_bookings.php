<?php
// ============================================================
//  admin/manage_bookings.php
//  Booking ledger workspace with AI review
// ============================================================

// ── Boot critical helpers BEFORE any output (required for redirect handlers) ─
require_once "../includes/session_config.php";
asc_session_start();
require_once "../includes/csrf.php";
csrf_ensure('admin_csrf');
require_once "../config/db_connect.php";
require_once "../includes/send_email.php";
require_once "../includes/gemini_client.php";
require_once "../includes/activity_log.php";
require_once __DIR__ . '/../includes/cache.php';

// Invalidate the bookings list cache on any POST that mutates data.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cache_delete('mg_bookings');
}

$message = "";
$ai_results = [];
$ai_error = '';
$ai_diag = '';

// ── AI STRICTNESS SETTING (per-admin, stored in DB) ────────────────────
$strictness_levels = ['Conservative', 'Balanced', 'Liberal', 'Custom'];
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$current_strictness = 'Balanced';
$custom_prompt = '';
$custom_temperature = 0.20;

// Fetch from DB
if ($admin_id > 0) {
    $stmt = $conn->prepare("SELECT ai_strictness, ai_custom_prompt, ai_custom_temperature FROM admins WHERE admin_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        $stmt->bind_result($db_strictness, $db_custom_prompt, $db_custom_temp);
        if ($stmt->fetch()) {
            if (in_array($db_strictness, $strictness_levels, true)) {
                $current_strictness = $db_strictness;
            }
            $custom_prompt = $db_custom_prompt ?? '';
            $custom_temperature = $db_custom_temp !== null ? (float)$db_custom_temp : 0.20;
        }
        $stmt->close();
    }
}

// Handle strictness change — persist to DB (MUST run before any HTML output)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "set_strictness") {
    $level = $_POST['strictness'] ?? 'Balanced';
    if (in_array($level, $strictness_levels, true) && $admin_id > 0) {
        $stmt = $conn->prepare("UPDATE admins SET ai_strictness = ? WHERE admin_id = ?");
        if ($stmt) {
            $stmt->bind_param('si', $level, $admin_id);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'Changed AI strictness to ' . $level, 'Bookings', null, 'Strictness changed from ' . $current_strictness . ' to ' . $level);
        }
    }
    header('Location: manage_bookings.php');
    exit;
}

// Handle saving custom prompt + temperature
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "save_custom_prompt") {
    if ($admin_id > 0) {
        $new_prompt = $_POST['custom_prompt_text'] ?? '';
        $new_temp = max(0.0, min(1.0, (float)($_POST['custom_temperature'] ?? 0.20)));
        $stmt = $conn->prepare("UPDATE admins SET ai_custom_prompt = ?, ai_custom_temperature = ? WHERE admin_id = ?");
        if ($stmt) {
            $stmt->bind_param('sdi', $new_prompt, $new_temp, $admin_id);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'Saved custom AI prompt', 'Bookings', null, 'Temperature set to ' . $new_temp . ', prompt length: ' . strlen($new_prompt) . ' chars');
        }
    }
    header('Location: manage_bookings.php');
    exit;
}

// Handle resetting custom prompt to default Balanced
$default_balanced_prompt = "You are an AI booking manager. Review each booking fairly.\n"
    . "Guidelines:\n"
    . "- Time should be between 06:00 and 22:00\n"
    . "- Facility type should match the sport\n"
    . "- Coach specialization should match the sport where possible\n"
    . "- Member details look valid\n"
    . "- Duration up to 4 hours is fine.";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "reset_custom_prompt") {
    if ($admin_id > 0) {
        $stmt = $conn->prepare("UPDATE admins SET ai_custom_prompt = ?, ai_custom_temperature = 0.20 WHERE admin_id = ?");
        if ($stmt) {
            $default = $default_balanced_prompt;
            $stmt->bind_param('si', $default, $admin_id);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'Reset custom AI prompt to default Balanced', 'Bookings', null, 'Temperature reset to 0.20');
        }
    }
    header('Location: manage_bookings.php');
    exit;
}

// ── Include admin layout (start of HTML output) ────────────────────────────
include_once("../includes/admin_header.php");

// ── HANDLE AI REVIEW ────────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "ai_review_pending") {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $ai_error = 'Security check failed. Please refresh and try again.';
    } else {
        $key_status = asc_gemini_api_key_status();
        if (empty($key_status['ready'])) {
            $ai_error = $key_status['message'] ?? 'AI API key is not configured.';
            $ai_diag = asc_ai_diagnostics_panel();
        } else {
            // Fetch all pending bookings with context
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
                    ORDER BY b.booking_date ASC, b.start_time ASC";
            if ($result = $conn->query($sql)) {
                while ($row = $result->fetch_assoc()) {
                    $pending[] = $row;
                }
                $result->free();
            }

            if (empty($pending)) {
                $ai_error = 'No pending bookings to review.';
            } else {
                // Build a structured summary for Gemini with conflict detection
                $booking_summary = '';
                foreach ($pending as $i => $b) {
                    $coach = trim(($b['coach_first'] ?? '') . ' ' . ($b['coach_last'] ?? '')) ?: 'None assigned';

                    // Check for overlapping approved bookings on same facility
                    $conflict_info = '';
                    if (!empty($b['facility_name'])) {
                        $conflict_sql = "SELECT COUNT(*) AS overlap_count FROM bookings b2
                            WHERE b2.facility_id = ? AND b2.booking_id != ?
                            AND b2.booking_date = ?
                            AND b2.status IN ('Approved', 'Confirmed')
                            AND b2.start_time < ? AND b2.end_time > ?";
                        $cf_stmt = $conn->prepare($conflict_sql);
                        if ($cf_stmt) {
                            $fid = $b['facility_id'];
                            $bid = $b['booking_id'];
                            $bdate = $b['booking_date'];
                            $stime = $b['end_time'];
                            $etime = $b['start_time'];
                            $cf_stmt->bind_param('iisss', $fid, $bid, $bdate, $stime, $etime);
                            $cf_stmt->execute();
                            $cf_result = $cf_stmt->get_result();
                            if ($cf_row = $cf_result->fetch_assoc()) {
                                if ((int)$cf_row['overlap_count'] > 0) {
                                    $conflict_info = "  [CONFLICT] {$cf_row['overlap_count']} existing approved booking(s) overlap this time slot!\n";
                                }
                            }
                            $cf_stmt->close();
                        }
                    }

                    $booking_summary .= sprintf(
                        "Booking #%d:\n  Member: %s %s (%s, Tel: %s)\n  Sport: %s\n  Facility: %s (%s, capacity %s)\n  Coach: %s\n  Date: %s\n  Time: %s - %s\n%s",
                        $b['booking_id'],
                        $b['first_name'], $b['last_name'], $b['email'], $b['phone_number'] ?? 'N/A',
                        $b['sport_name'] ?? 'N/A',
                        $b['facility_name'] ?? 'N/A', $b['facility_type'] ?? 'N/A', $b['capacity'] ?? 'N/A',
                        $coach,
                        $b['booking_date'],
                        $b['start_time'], $b['end_time'],
                        $conflict_info
                    );
                    $booking_summary .= "\n";
                }

                // Apply strictness level to prompt
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

                if ($current_strictness === 'Custom' && !empty($custom_prompt)) {
                    $guidelines = $custom_prompt;
                    $temperature = $custom_temperature;
                } else {
                    $guidelines = $strictness_prompts[$current_strictness] ?? $strictness_prompts['Balanced'];
                    $temperature = $current_strictness === 'Conservative' ? 0.1 : ($current_strictness === 'Liberal' ? 0.4 : 0.2);
                }

                $prompt = "You are an AI booking manager for Apex Sports Club. Review each pending booking below and decide whether to APPROVE or REJECT each one.\n\n"
                    . $guidelines
                    . "Also check for CONFLICT warnings next to each booking — overlapping bookings for the same facility are a strong reason to REJECT.\n\n"
                    . "Respond with ONLY a valid JSON array. Each object must have: booking_id, decision (\"APPROVE\" or \"REJECT\"), reason (short explanation like \"Appropriate facility and time\" or \"No coach assigned for this sport\").\n\n"
                    . "Pending bookings to review:\n\n" . $booking_summary;

                $result = asc_gemini_generate_text($prompt, [
                    'temperature' => $temperature,
                    'maxOutputTokens' => 2000,
                    'timeout' => 30,
                ]);

                if (!empty($result['success'])) {
                    $text = trim($result['text']);
                    // Extract JSON from the response (handle markdown code fences)
                    if (preg_match('/```json\s*([\s\S]*?)\s*```/', $text, $m)) {
                        $json_str = trim($m[1]);
                    } elseif (preg_match('/\[\s*\{[\s\S]*\}\]/', $text, $m)) {
                        $json_str = $m[0];
                    } else {
                        $json_str = $text;
                    }

                    $parsed = json_decode($json_str, true);
                    if (is_array($parsed) && count($parsed) > 0) {
                        // Validate each entry has required fields
                        foreach ($parsed as $item) {
                            if (isset($item['booking_id'], $item['decision']) && in_array($item['decision'], ['APPROVE', 'REJECT'])) {
                                $ai_results[] = $item;
                            }
                        }
                    }

                    // Log all AI review decisions to the audit log
                    if (!empty($ai_results)) {
                        $admin_id = (int)($_SESSION['admin_id'] ?? 0);
                        $log_stmt = $conn->prepare(
                            "INSERT INTO ai_review_log (booking_id, admin_id, decision, reason, applied) VALUES (?, ?, ?, ?, 0)"
                        );
                        if ($log_stmt) {
                            foreach ($ai_results as $item) {
                                $bid = (int)$item['booking_id'];
                                $dec = $item['decision'];
                                $rea = substr($item['reason'] ?? '', 0, 500);
                                $log_stmt->bind_param('iiss', $bid, $admin_id, $dec, $rea);
                                $log_stmt->execute();
                            }
                            $log_stmt->close();
                        }
                    }

                    if (empty($ai_results)) {
                        $ai_error = 'Gemini responded but the format was not understood. Check the AI response details below for debugging.';
                        $_SESSION['ai_raw_response'] = substr($text, 0, 2000);
                    }
                } else {
                    $ai_error = $result['error'] ?? 'Gemini did not return a response.';
                }
            }
        }
    }
}

// ── HANDLE MANUAL CRON AI REVIEW ──────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "ai_cron_run_manual") {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = '<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i>Security check failed.</div>';
    } else {
        // Read cron settings from DB
        $cron_settings = [];
        $sr = $conn->query("SELECT setting_key, setting_value FROM cron_ai_settings");
        if ($sr) {
            while ($srow = $sr->fetch_assoc()) {
                $cron_settings[$srow['setting_key']] = $srow['setting_value'];
            }
            $sr->free();
        }
        $cron_strictness = $cron_settings['strictness'] ?? 'Balanced';
        $cron_batch_limit = (int)($cron_settings['batch_limit'] ?? 50);

        $key_status = asc_gemini_api_key_status();
        if (empty($key_status['ready'])) {
            $message = '<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i>Gemini API key not configured.</div>';
        } else {
            ob_start();
            $cron_log = [];
            $cron_log[] = 'Manual AI cron review started...';

            // Fetch pending bookings (limited by batch setting)
            $pending = [];
            $stmt = $conn->prepare("SELECT b.booking_id, b.facility_id, b.booking_date, b.start_time, b.end_time,
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
                ORDER BY b.booking_date ASC, b.start_time ASC LIMIT ?");
            if ($stmt) {
                $stmt->bind_param('i', $cron_batch_limit);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $pending[] = $row;
                }
                $stmt->close();
            }

            $count = count($pending);
            $cron_log[] = "Found {$count} pending booking(s) to review.";                $cron_detailed_bookings = [];

            if ($count > 0) {
                // Build booking summary with conflict detection
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
                                    $conflict_info = "  [CONFLICT] {$cf_row['overlap_count']} existing approved booking(s) overlap!\n";
                                }
                            }
                            $cf_stmt->close();
                        }
                    }

                    $booking_summary .= sprintf(
                        "Booking #%d:\n  Member: %s %s (%s, Tel: %s)\n  Sport: %s\n  Facility: %s (%s, capacity %s)\n  Coach: %s\n  Date: %s\n  Time: %s - %s\n%s\n",
                        $b['booking_id'], $b['first_name'], $b['last_name'], $b['email'], $b['phone_number'] ?? 'N/A',
                        $b['sport_name'] ?? 'N/A', $b['facility_name'] ?? 'N/A', $b['facility_type'] ?? 'N/A', $b['capacity'] ?? 'N/A',
                        $coach, $b['booking_date'], $b['start_time'], $b['end_time'],
                        $conflict_info
                    );

                    $cron_detailed_bookings[] = [
                        'booking_id' => $b['booking_id'],
                        'member' => trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')),
                        'sport' => $b['sport_name'] ?? 'N/A',
                        'decision' => 'PENDING',
                        'reason' => '',
                    ];
                }

                // Build prompt with strictness
                $strictness_prompts = [
                    'Conservative' => "STRICT. Only APPROVE perfect bookings. Time 07:00-20:00, facility matches sport, coach matches sport, contact must be present, max 2 hours. When in doubt REJECT.",
                    'Balanced' => "Fair. Time 06:00-22:00, facility should match sport, coach should match where possible, valid contact, up to 4 hours.",
                    'Liberal' => "Lenient. Only REJECT for clear issues: time outside 05:00-23:00, facility mismatch, missing contact, extreme duration over 6 hours.",
                ];
                // For manual cron, if 'Custom' is selected in cron settings, use the current admin's custom prompt
                if ($cron_strictness === 'Custom') {
                    $guideline = !empty($custom_prompt) ? $custom_prompt : $strictness_prompts['Balanced'];
                    $temperature = $custom_temperature;
                } else {
                    $guideline = $strictness_prompts[$cron_strictness] ?? $strictness_prompts['Balanced'];
                    $temperature = $cron_strictness === 'Conservative' ? 0.1 : ($cron_strictness === 'Liberal' ? 0.4 : 0.2);
                }

                $prompt = "You are an AI booking manager for Apex Sports Club. Review each pending booking below and decide to APPROVE or REJECT each one.\n\n"
                    . "Guidelines ({$cron_strictness}): {$guideline}\n\n"
                    . "Also check for CONFLICT warnings — overlapping bookings are a strong reason to REJECT.\n\n"
                    . "Respond with ONLY a valid JSON array. Each object must have: booking_id (int), decision (\"APPROVE\" or \"REJECT\"), reason (short explanation).\n\n"
                    . "Pending bookings:\n\n" . $booking_summary;

                $cron_log[] = "Calling Gemini (strictness: {$cron_strictness})...";
                $gemini_result = asc_gemini_generate_text($prompt, [
                    'temperature' => $temperature, 'maxOutputTokens' => 2000, 'timeout' => 30,
                ]);

                if (empty($gemini_result['success'])) {
                    $cron_log[] = 'Gemini error: ' . ($gemini_result['error'] ?? 'Unknown');
                } else {
                    $text = trim($gemini_result['text']);
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
                        $cron_log[] = 'Could not parse Gemini response as JSON.';
                    } else {
                        $approved = 0; $rejected = 0; $failed = 0;
                        foreach ($parsed as $item) {
                            if (!isset($item['booking_id'], $item['decision']) || !in_array($item['decision'], ['APPROVE', 'REJECT'], true)) continue;
                            $booking_id = (int)$item['booking_id'];
                            $db_decision = $item['decision'] === 'APPROVE' ? 'Approved' : 'Rejected';
                            $reason = substr($item['reason'] ?? '', 0, 500);

                            // Log to ai_review_log
                            $log_stmt = $conn->prepare("INSERT INTO ai_review_log (booking_id, admin_id, decision, reason, applied) VALUES (?, 0, ?, ?, 1)");
                            if ($log_stmt) {
                                $dec_label = $item['decision'];
                                $log_stmt->bind_param('iss', $booking_id, $dec_label, $reason);
                                $log_stmt->execute();
                                $log_stmt->close();
                            }

                            // Apply decision
                            $upd = $conn->prepare("UPDATE bookings SET status = ? WHERE booking_id = ? AND status = 'Pending'");
                            if ($upd) {
                                $upd->bind_param('si', $db_decision, $booking_id);
                                if ($upd->execute() && $upd->affected_rows > 0) {
                                    if ($db_decision === 'Approved') $approved++; else $rejected++;
                                    // Send email (use prepared statement)
                                    $info_stmt = $conn->prepare("SELECT m.first_name, m.last_name, m.email, s.name AS sport_name, b.booking_date FROM bookings b LEFT JOIN members m ON b.member_id = m.member_id LEFT JOIN sports s ON b.sport_id = s.sport_id WHERE b.booking_id = ? LIMIT 1");
                                    if ($info_stmt) {
                                        $info_stmt->bind_param('i', $booking_id);
                                        $info_stmt->execute();
                                        $info_result = $info_stmt->get_result();
                                        if ($row = $info_result->fetch_assoc()) {
                                            if (!empty($row['email'])) {
                                                sendEmail($row['email'], trim($row['first_name'] . ' ' . $row['last_name']) ?: 'Member',
                                                    'Booking ' . $db_decision . ' - Apex Sports Club',
                                                    emailBookingStatusUpdate($row['first_name'] ?: 'Member', $row['sport_name'] ?: 'your booking', $row['booking_date'] ?: '', $db_decision));
                                            }
                                        }
                                        $info_stmt->close();
                                    }
                                } else {
                                    $failed++;
                                }
                                $upd->close();
                            }
                        }
                        $cron_log[] = "Results: {$approved} approved, {$rejected} rejected, {$failed} failed.";

                        // Populate detailed bookings with actual decisions
                        foreach ($parsed as $item) {
                            foreach ($cron_detailed_bookings as &$db_entry) {
                                if ((int)$db_entry['booking_id'] === (int)$item['booking_id']) {
                                    $db_entry['decision'] = $item['decision'];
                                    $db_entry['reason'] = $item['reason'] ?? '';
                                    break;
                                }
                            }
                            unset($db_entry);
                        }

                        // Send detailed summary to all admins
                        $summary_sent = 0;
                        $admins = $conn->query("SELECT email FROM admins");
                        if ($admins) {
                            while ($admin_row = $admins->fetch_assoc()) {
                                if (!empty($admin_row['email'])) {
                                    sendEmail($admin_row['email'], 'Admin',
                                        'Detailed AI Cron Report - ' . ($approved + $rejected) . ' bookings processed',
                                        emailCronDetailedSummary($approved + $rejected, $approved, $rejected, $cron_strictness, $cron_detailed_bookings));
                                    $summary_sent++;
                                }
                            }
                            $admins->free();
                        }
                        $cron_log[] = "Summary sent to {$summary_sent} admin(s).";
                    }
                }
            } else {
                $cron_log[] = 'No pending bookings to review.';
            }

            $cron_log[] = 'Manual AI cron review completed.';
            $captured = implode("\n", $cron_log);
            echo $captured;
            $cron_output = ob_get_clean();

            $message = '<div class="alert alert-success border-0 shadow-sm">
                <i class="fas fa-check-circle me-2"></i>Manual AI cron review completed. Check the <a href="ai_review_log.php" class="fw-semibold">AI Review Log</a> for details.
                <details class="mt-2"><summary class="small fw-semibold">View output</summary>
                <pre class="mt-1 p-2 bg-light rounded small" style="max-height:300px;overflow:auto;">' . htmlspecialchars($cron_output) . '</pre></details></div>';
        }
    }
}

// ── HANDLE APPLY AI RECOMMENDATIONS ─────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "apply_ai_results") {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = '<div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>Security check failed.</div>';
    } else {
        $decisions = $_POST['decision'] ?? [];
        $reasons   = $_POST['reason'] ?? [];
        $applied_count = 0;
        $error_count = 0;


        foreach ($decisions as $booking_id => $decision) {
            if (!in_array($decision, ['Approved', 'Rejected'], true)) continue;
            $reason = htmlspecialchars(substr($reasons[$booking_id] ?? '', 0, 255));

            // Fetch booking info for email
            $notice_sql = "SELECT b.status AS current_status, b.booking_date,
                                  m.first_name, m.last_name, m.email,
                                  s.name AS sport_name
                           FROM bookings b
                           LEFT JOIN members m ON b.member_id = m.member_id
                           LEFT JOIN sports s ON b.sport_id = s.sport_id
                           WHERE b.booking_id = ? LIMIT 1";
            $notice_stmt = $conn->prepare($notice_sql);
            $booking_info = null;
            if ($notice_stmt) {
                $notice_stmt->bind_param("i", $booking_id);
                $notice_stmt->execute();
                $booking_info = $notice_stmt->get_result()->fetch_assoc();
                $notice_stmt->close();
            }

            $stmt = $conn->prepare("UPDATE bookings SET status = ? WHERE booking_id = ?");
            if ($stmt) {
                $stmt->bind_param("si", $decision, $booking_id);
                if ($stmt->execute()) {
                    $applied_count++;
                    log_activity($conn, 'AI ' . $decision . ' booking', 'Bookings', (int)$booking_id, $reason);

                    // Mark in AI review log as applied
                    $update_log = $conn->prepare(
                        "UPDATE ai_review_log SET applied = 1 WHERE booking_id = ? AND decision = ? AND applied = 0 ORDER BY log_id DESC LIMIT 1"
                    );
                    if ($update_log) {
                        $log_decision = $decision === 'Approved' ? 'APPROVE' : 'REJECT';
                        $update_log->bind_param('is', $booking_id, $log_decision);
                        $update_log->execute();
                        $update_log->close();
                    }

                    // Send email notification
                    if ($booking_info && !empty($booking_info['email'])) {
                        $member_name = trim(($booking_info['first_name'] ?? '') . ' ' . ($booking_info['last_name'] ?? ''));
                        sendEmail(
                            $booking_info['email'],
                            $member_name ?: 'Member',
                            'Booking ' . $decision . ' - Apex Sports Club',
                            emailBookingStatusUpdate(
                                $booking_info['first_name'] ?: 'Member',
                                $booking_info['sport_name'] ?: 'your booking',
                                $booking_info['booking_date'] ?: '',
                                $decision
                            )
                        );
                    }
                } else {
                    $error_count++;
                }
                $stmt->close();
            }
        }

        // Send summary email to admin
        $admin_email = $_SESSION['admin_email'] ?? '';
        if ($admin_email !== '' && $applied_count > 0) {
            $approved = 0;
            $rejected = 0;
            foreach ($decisions as $bid => $dec) {
                if ($dec === 'Approved') $approved++;
                if ($dec === 'Rejected') $rejected++;
            }
            sendEmail(
                $admin_email,
                'Admin',
                'AI Review Summary - ' . $applied_count . ' bookings processed',
                emailAIReviewSummary($applied_count, $approved, $rejected, $current_strictness)
            );
        }

        $message = '<div class="alert alert-success border-0 shadow-sm d-flex align-items-center mb-4">
            <i class="fas fa-robot me-2"></i>AI review applied: ' . $applied_count . ' booking(s) processed.';
        if ($error_count > 0) {
            $message .= ' ' . $error_count . ' error(s) encountered.';
        }
        if (!empty($admin_email)) {
            $message .= ' Summary email sent to ' . htmlspecialchars($admin_email) . '.';
        }
        $message .= '</div>';
    }
}

// ── HANDLE BOOKING STATUS UPDATE ─────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "update_status" && isset($_POST["booking_id"]) && isset($_POST["status"])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = '<div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>Security check failed.</div>';
    } else {
    $booking_id = intval($_POST["booking_id"]);
    $status = trim($_POST["status"]);
    $allowed_statuses = ['Pending', 'Approved', 'Rejected', 'Completed'];
    $booking_notice = null;

    if (!in_array($status, $allowed_statuses, true)) {
        $message = '<div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>Invalid booking status selected.</div>';
    } else {
        $notice_sql = "SELECT b.status AS current_status, b.booking_date,
                              m.first_name, m.last_name, m.email,
                              s.name AS sport_name
                       FROM bookings b
                       LEFT JOIN members m ON b.member_id = m.member_id
                       LEFT JOIN sports s ON b.sport_id = s.sport_id
                       WHERE b.booking_id = ?
                       LIMIT 1";
        if ($notice_stmt = $conn->prepare($notice_sql)) {
            $notice_stmt->bind_param("i", $booking_id);
            $notice_stmt->execute();
            $booking_notice = $notice_stmt->get_result()->fetch_assoc();
            $notice_stmt->close();
        }

        $sql = "UPDATE bookings SET status = ? WHERE booking_id = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("si", $status, $booking_id);
            if ($stmt->execute()) {
                $email_sent = false;
                $should_email_approval = $status === 'Approved'
                    && $booking_notice
                    && ($booking_notice['current_status'] ?? '') !== 'Approved'
                    && !empty($booking_notice['email']);

                if ($should_email_approval) {
                    $member_name = trim(($booking_notice['first_name'] ?? '') . ' ' . ($booking_notice['last_name'] ?? ''));
                    $email_sent = sendEmail(
                        $booking_notice['email'],
                        $member_name ?: ($booking_notice['first_name'] ?? 'Member'),
                        'Booking Approved - Apex Sports Club',
                        emailBookingStatusUpdate(
                            $booking_notice['first_name'] ?: 'Member',
                            $booking_notice['sport_name'] ?: 'your booking',
                            $booking_notice['booking_date'] ?: '',
                            'Approved'
                        )
                    );
                }

                $message = '<div class="alert alert-success border-0 shadow-sm d-flex align-items-center mb-4"><i class="fas fa-check-circle me-2"></i>Booking status updated successfully.';
                if ($should_email_approval) {
                    $message .= $email_sent
                        ? ' Approval email sent to the member.'
                        : ' Approval email could not be sent; check the PHP error log for the Brevo response.';
                }
                $message .= '</div>';
            } else {
                $message = '<div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>Error updating booking status: ' . htmlspecialchars($stmt->error) . '</div>';
            }
            $stmt->close();
        }
    }
    }
}

// ── HANDLE SEQUENTIAL DELETION & RESET AUTO_INCREMENT ───────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST["action"]) && $_POST["action"] == "delete_booking" && isset($_POST["booking_id"])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = '<div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>Security check failed.</div>';
    } else {
    $booking_id = intval($_POST["booking_id"]);

    // Step 1: Remove the target row
    $delete_sql = "DELETE FROM bookings WHERE booking_id = ?";
    if ($stmt = $conn->prepare($delete_sql)) {
        $stmt->bind_param("i", $booking_id);
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success border-0 shadow-sm d-flex align-items-center mb-4"><i class="fas fa-trash-alt me-2"></i>Record deleted successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>Error executing query: ' . htmlspecialchars($stmt->error) . '</div>';
        }
        $stmt->close();
    }
    }
}

// ── FETCH DATABASE BOOKING RECORDS MATRIX (PAGINATED) — cached 60s per page ──
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 15;
$offset = ($page - 1) * $per_page;

$booking_rows = cache_remember('mg_bookings:p' . $page, 60, function () use ($conn, $per_page, $offset) {
    $total_bookings = 0;
    $count_result = $conn->query("SELECT COUNT(*) AS total FROM bookings");
    if ($count_result) {
        $total_bookings = (int)$count_result->fetch_assoc()['total'];
        $count_result->free();
    }
    $total_pages = max(1, ceil($total_bookings / $per_page));

    $bookings = [];
    $sql = "SELECT b.booking_id, m.first_name, m.last_name, f.name AS facility_name, 
                   c.first_name AS coach_first_name, c.last_name AS coach_last_name, 
                   s.name AS sport_name, b.booking_date, b.start_time, b.end_time, b.status 
            FROM bookings b
            LEFT JOIN members m ON b.member_id = m.member_id
            LEFT JOIN facilities f ON b.facility_id = f.facility_id
            LEFT JOIN coaches c ON b.coach_id = c.coach_id
            LEFT JOIN sports s ON b.sport_id = s.sport_id
            ORDER BY b.booking_date DESC, b.start_time DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("ii", $per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }
        $stmt->close();
    }
    return ['total' => $total_bookings, 'pages' => $total_pages, 'rows' => $bookings];
});

$total_bookings = $booking_rows['total'] ?? 0;
$total_pages    = $booking_rows['pages'] ?? 1;
$bookings       = $booking_rows['rows'] ?? [];
$conn->close();
?>

<style>
    body {
        background-color: #f8fafc !important;
        color: #334155;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    .page-title {
        color: #0f172a;
        font-weight: 700;
        letter-spacing: -0.5px;
    }
    .ledger-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }
    .ledger-header {
        background-color: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.5rem;
    }
    .table-ledger th {
        background-color: #f8fafc;
        color: #64748b;
        font-family: monospace;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #e2e8f0;
    }
    .table-ledger td {
        padding: 1rem 1.25rem;
        color: #334155;
        font-size: 0.9rem;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .table-ledger tr:last-child td {
        border-bottom: none;
    }
    .status-badge {
        font-size: 0.75rem;
        font-weight: 600;
        padding: 0.25rem 0.625rem;
        border-radius: 50px;
        display: inline-block;
    }
    .badge-state-pending { background-color: #fef3c7; color: #92400e; }
    .badge-state-approved { background-color: #d1fae5; color: #065f46; }
    .badge-state-completed { background-color: #e0f2fe; color: #0369a1; }
    .badge-state-rejected { background-color: #fef2f2; color: #991b1b; }

    .select-interactive {
        background-color: #f8fafc;
        border: 1px solid #cbd5e1;
        color: #475569;
        font-size: 0.85rem;
        font-weight: 550;
        padding: 0.35rem 2rem 0.35rem 0.75rem;
        border-radius: 6px;
        cursor: pointer;
        transition: all 0.15s ease-in-out;
    }
    .select-interactive:focus {
        border-color: #2563eb;
        background-color: #ffffff;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        outline: none;
    }
    .btn-action-view {
        border: 1px solid #cbd5e1;
        background-color: #ffffff;
        color: #64748b;
        font-weight: 600;
        font-size: 0.8rem;
        padding: 0.35rem 0.75rem;
        border-radius: 6px;
        transition: all 0.15s ease;
    }
    .btn-action-view:hover {
        background-color: #f1f5f9;
        color: #0f172a;
        border-color: #cbd5e1;
    }
    .btn-action-delete {
        border: 1px solid #fee2e2;
        background-color: #fff5f5;
        color: #ef4444;
        font-size: 0.8rem;
        padding: 0.35rem 0.65rem;
        border-radius: 6px;
        transition: all 0.15s ease;
    }
    .btn-action-delete:hover {
        background-color: #ef4444;
        color: #ffffff;
        border-color: #dc2626;
    }
    .text-cell-dark {
        color: #0f172a;
        font-weight: 600;
    }
</style>

<div class="container-fluid my-4 px-md-4">
    
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom border-light">
        <div>
            <h2 class="page-title mb-1">Reservation Journals</h2>
            <p class="text-muted small mb-0 d-flex align-items-center gap-2">
                Review, approve, and monitor facility bookings across the club.
                <span class="badge rounded-pill px-3 py-1" style="font-size:0.65rem;font-weight:600;
                    <?php
                    $mode_colors = ['Conservative' => '#dc2626', 'Balanced' => '#7c3aed', 'Liberal' => '#16a34a', 'Custom' => '#2563eb'];
                    $mode_effective_temp = $current_strictness === 'Conservative' ? 0.10 : ($current_strictness === 'Liberal' ? 0.40 : ($current_strictness === 'Custom' ? $custom_temperature : 0.20));
                    $mode_color = $mode_colors[$current_strictness] ?? '#7c3aed';
                    ?>
                    background:<?php echo $mode_color; ?>;color:#fff;">
                    <?php echo $current_strictness; ?>
                    <span class="font-monospace ms-1" style="opacity:0.8;font-size:0.6rem;">
                        Temp: <?php echo number_format($mode_effective_temp, 2); ?>
                    </span>
                </span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <div class="d-flex align-items-center gap-1">
                <form method="post" class="m-0">
                    <?php echo csrf_field('admin_csrf'); ?>
                    <input type="hidden" name="action" value="set_strictness">
                    <select name="strictness" class="form-select form-select-sm select-interactive"
                            style="font-size:0.75rem;padding:0.35rem 1.5rem 0.35rem 0.6rem;"
                            onchange="this.form.submit()">
                        <option value="Conservative" <?php echo $current_strictness === 'Conservative' ? 'selected' : ''; ?>>Conservative</option>
                        <option value="Balanced" <?php echo $current_strictness === 'Balanced' ? 'selected' : ''; ?>>Balanced</option>
                        <option value="Liberal" <?php echo $current_strictness === 'Liberal' ? 'selected' : ''; ?>>Liberal</option>
                        <option value="Custom" <?php echo $current_strictness === 'Custom' ? 'selected' : ''; ?>>Custom</option>
                    </select>
                </form>
                <button type="button" class="btn btn-sm p-1 border-0" style="color:#7c3aed;font-size:0.8rem;"
                        onclick="document.getElementById('aiPromptInfo').classList.toggle('d-none')"
                        title="View strictness prompts and temperature settings">
                    <i class="fas fa-info-circle"></i>
                </button>
            </div>
            <form method="post" class="m-0">
                <?php echo csrf_field('admin_csrf'); ?>
                <input type="hidden" name="action" value="ai_review_pending">
                <button type="submit" class="btn text-decoration-none text-dark"
                        style="border:1px solid #cbd5e1;background:#ffffff;color:#475569;font-weight:600;font-size:0.8rem;padding:0.35rem 0.75rem;border-radius:6px;transition:all 0.15s ease;display:inline-flex;align-items:center;gap:0.4rem;">
                    <i class="fas fa-robot me-1" style="color:#7c3aed;"></i> AI Review
                </button>
            </form>
            <form method="post" class="m-0">
                <?php echo csrf_field('admin_csrf'); ?>
                <input type="hidden" name="action" value="ai_cron_run_manual">
                <button type="submit" class="btn text-decoration-none text-dark"
                        style="border:1px solid #e2e8f0;background:#faf5ff;color:#7c3aed;font-weight:600;font-size:0.8rem;padding:0.35rem 0.75rem;border-radius:6px;transition:all 0.15s ease;display:inline-flex;align-items:center;gap:0.4rem;"
                        onclick="return confirm('Run automated AI review now? This will process ALL pending bookings using the configured strictness and send notifications.');">
                    <i class="fas fa-play me-1"></i> Run Cron AI
                </button>
            </form>
            <a href="cron_ai_settings.php" class="btn" style="border:1px solid #e2e8f0;background:#ffffff;color:#475569;font-weight:600;font-size:0.8rem;padding:0.35rem 0.75rem;border-radius:6px;">
                <i class="fas fa-clock me-1" style="color:#7c3aed;"></i>
            </a>
            <a href="admin_dashboard.php" class="btn btn-action-view text-decoration-none text-dark">
                <i class="fas fa-arrow-left me-2 small"></i>Console Workspace
            </a>
        </div>
    </div>

    <!-- ── AI STRICTNESS PROMPT INFO PANEL (collapsible) ────────────────── -->
    <div id="aiPromptInfo" class="card border-0 shadow-sm mb-4 d-none" style="border:1px solid #e9d5ff !important;border-radius:10px;">
        <div class="card-body p-3">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <h6 class="mb-0 fw-bold" style="color:#7c3aed;"><i class="fas fa-robot me-2"></i>AI Strictness &amp; Temperature Guide</h6>
                <button type="button" class="btn-close btn-sm" onclick="document.getElementById('aiPromptInfo').classList.add('d-none')"></button>
            </div>
            <div class="row g-3">
                <!-- Conservative -->
                <div class="col-md-4">
                    <div class="p-3 rounded-3 h-100" style="background:#fef2f2;border:1px solid #fecaca;">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge" style="background:#dc2626;font-size:0.7rem;">Conservative</span>
                            <span class="badge bg-dark font-monospace" style="font-size:0.65rem;">Temp: 0.10</span>
                        </div>
                        <p class="mb-1 small text-muted" style="font-size:0.8rem;"><strong>Prompt:</strong> You are a STRICT AI booking manager. Only APPROVE bookings that meet ALL criteria perfectly. REJECT if anything seems off. Time MUST be between 07:00–20:00, facility type MUST match the sport, coach specialization SHOULD match, all member details MUST be present, duration MUST be ≤2 hours. When in doubt, REJECT.</p>
                        <p class="mb-0 small" style="font-size:0.75rem;color:#dc2626;"><i class="fas fa-thermometer-half me-1"></i>Low temperature = deterministic, strict decisions</p>
                    </div>
                </div>
                <!-- Balanced -->
                <div class="col-md-4">
                    <div class="p-3 rounded-3 h-100" style="background:#faf5ff;border:1px solid #e9d5ff;">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge" style="background:#7c3aed;font-size:0.7rem;">Balanced</span>
                            <span class="badge bg-dark font-monospace" style="font-size:0.65rem;">Temp: 0.20</span>
                        </div>
                        <p class="mb-1 small text-muted" style="font-size:0.8rem;"><strong>Prompt:</strong> Review each booking fairly. Time 06:00–22:00, facility type should match sport, coach should match where possible, valid contact details, duration up to 4 hours is fine.</p>
                        <p class="mb-0 small" style="font-size:0.75rem;color:#7c3aed;"><i class="fas fa-thermometer-half me-1"></i>Moderate temperature = balanced, fair decisions</p>
                    </div>
                </div>
                <!-- Liberal -->
                <div class="col-md-4">
                    <div class="p-3 rounded-3 h-100" style="background:#f0fdf4;border:1px solid #bbf7d0;">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge" style="background:#16a34a;font-size:0.7rem;">Liberal</span>
                            <span class="badge bg-dark font-monospace" style="font-size:0.65rem;">Temp: 0.40</span>
                        </div>
                        <p class="mb-1 small text-muted" style="font-size:0.8rem;"><strong>Prompt:</strong> Approve bookings whenever reasonable. Only REJECT for clear problems: time outside 05:00–23:00, facility type mismatch, missing critical contact info, extreme duration over 6 hours. Give members the benefit of the doubt. APPROVE more often.</p>
                        <p class="mb-0 small" style="font-size:0.75rem;color:#16a34a;"><i class="fas fa-thermometer-half me-1"></i>Higher temperature = more creative, lenient decisions</p>
                    </div>
                </div>
            </div>
            <p class="small text-muted mt-3 mb-0 text-center" style="font-size:0.75rem;">
                <i class="fas fa-info-circle me-1"></i>
                <strong>Temperature</strong> controls how strictly the AI follows rules. Lower = more predictable &amp; strict, Higher = more creative &amp; lenient.
                Choose <strong>Conservative</strong> (strict), <strong>Balanced</strong> (fair), or <strong>Liberal</strong> (lenient).
                <strong>Custom</strong> lets you write your own prompt + set a custom temperature.
            </p>
        </div>
    </div>

    <!-- ── CUSTOM PROMPT EDITOR (shown only when Custom is selected) ────── -->
    <?php if ($current_strictness === 'Custom'): ?>
    <div class="card border-0 shadow-sm mb-4" style="border:1px solid #e9d5ff !important;border-radius:10px;background:#faf5ff;">
        <div class="card-body p-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <i class="fas fa-pen-fancy" style="color:#7c3aed;"></i>
                <h6 class="mb-0 fw-bold" style="color:#7c3aed;">Custom AI Prompt Editor</h6>
            </div>
            <p class="small text-muted mb-3">
                Write your own AI review guidelines. The AI will follow these instructions instead of the predefined levels.
                Include rules for time windows, facility matching, duration limits, and when to approve/reject.
            </p>
            <form method="post">
                <?php echo csrf_field('admin_csrf'); ?>
                <input type="hidden" name="action" value="save_custom_prompt">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Custom Prompt / Guidelines</label>
                    <textarea name="custom_prompt_text" class="form-control form-control-sm" rows="6"
                              style="font-size:0.85rem;font-family:monospace;"><?php echo htmlspecialchars($custom_prompt); ?></textarea>
                    <div class="form-text small">The AI will receive this as its core guidelines when reviewing bookings.</div>
                </div>
                <div class="row g-2 align-items-end mb-3">
                    <div class="col-auto">
                        <label class="form-label fw-semibold small mb-1">Temperature</label>
                        <input type="number" name="custom_temperature" class="form-control form-control-sm"
                               style="width:100px;font-size:0.85rem;"
                               step="0.05" min="0.0" max="1.0" value="<?php echo number_format($custom_temperature, 2); ?>">
                        <div class="form-text small">0.0 (strict) – 1.0 (creative). Default: 0.20</div>
                    </div>
                    <div class="col">
                        <div class="small text-muted" style="font-size:0.75rem;">
                            <i class="fas fa-lightbulb me-1"></i>
                            <strong>Tip:</strong> Lower temp (0.0–0.2) = predictable, rule-following.
                            Higher temp (0.3–0.5) = more flexible.
                            Above 0.5 may produce inconsistent results for this task.
                        </div>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <button type="submit" class="btn btn-sm" style="background:#7c3aed;color:#fff;font-weight:600;border:none;"
                            onclick="return confirm('Save this custom prompt? This will replace your current AI review guidelines with the text above.');">
                        <i class="fas fa-save me-1"></i> Save Custom Prompt
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger" style="font-weight:600;"
                            onclick="if(confirm('Reset custom prompt to the default Balanced guidelines?')){document.getElementById('resetPromptForm').submit();}">
                        <i class="fas fa-undo me-1"></i> Reset to Default
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-info" style="font-weight:600;"
                            onclick="document.getElementById('promptPreviewBlock').classList.toggle('d-none')">
                        <i class="fas fa-eye me-1"></i> Preview Prompt
                    </button>
                    <a href="?page=<?php echo $page; ?>" class="btn btn-sm btn-outline-secondary ms-auto">Cancel</a>
                </div>
            </form>

            <!-- Reset form (hidden, triggered by button above) -->
            <form method="post" id="resetPromptForm" class="d-none">
                <?php echo csrf_field('admin_csrf'); ?>
                <input type="hidden" name="action" value="reset_custom_prompt">
            </form>

            <!-- Prompt Preview (collapsible) -->
            <div id="promptPreviewBlock" class="d-none mt-3 p-3 rounded-3" style="background:#1e1e2e;border:1px solid #313244;">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="small fw-semibold" style="color:#a6e3a1;">
                        <i class="fas fa-terminal me-1"></i> Full AI Prompt Preview
                    </span>
                    <span class="badge font-monospace" style="background:#585b70;color:#cdd6f4;font-size:0.65rem;">
                        Temp: <?php echo number_format($custom_temperature, 2); ?>
                    </span>
                </div>
                <pre style="font-size:0.75rem;color:#cdd6f4;background:transparent;border:none;padding:0;margin:0;white-space:pre-wrap;word-break:break-word;max-height:400px;overflow-y:auto;"><?php
                    // Build a sample prompt to show what the AI receives
                    $preview_guidelines = !empty($custom_prompt) ? $custom_prompt : $default_balanced_prompt;
                    echo htmlspecialchars(
                        "You are an AI booking manager for Apex Sports Club. Review each pending booking below and decide whether to APPROVE or REJECT each one.\n\n"
                        . $preview_guidelines . "\n\n"
                        . "Also check for CONFLICT warnings next to each booking — overlapping bookings for the same facility are a strong reason to REJECT.\n\n"
                        . "Respond with ONLY a valid JSON array. Each object must have: booking_id, decision (\"APPROVE\" or \"REJECT\"), reason (short explanation).\n\n"
                        . "Pending bookings to review:\n\n"
                        . "Booking #1:\n  Member: John Doe (john@example.com, Tel: +254700000000)\n  Sport: Football\n  Facility: Main Pitch (Outdoor, capacity 100)\n  Coach: Coach Bob\n  Date: 2026-06-20\n  Time: 14:00 - 16:00\n"
                    );
                ?></pre>
                <p class="small text-muted mt-2 mb-0" style="font-size:0.7rem;color:#6c7086 !important;">
                    <i class="fas fa-info-circle me-1"></i>
                    Preview uses a sample booking. Actual prompt includes all real pending bookings with member details, facility info, coach, and conflict warnings.
                </p>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">                <div class="col-md-12">
            
                            <?php echo $message; ?>

                            <?php if (!empty($ai_error)): ?>
                                <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-4">
                                    <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($ai_error); ?>
                                    <?php if (!empty($_SESSION['ai_raw_response'])): ?>
                                        <details class="ms-3" style="cursor:pointer;font-size:0.85rem;">
                                            <summary class="fw-semibold">View raw Gemini response</summary>
                                            <pre class="mt-2 p-2 bg-light rounded" style="font-size:0.75rem;max-height:200px;overflow:auto;white-space:pre-wrap;"><?php echo htmlspecialchars($_SESSION['ai_raw_response']); ?></pre>
                                        </details>
                                        <?php unset($_SESSION['ai_raw_response']); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($ai_diag)): ?>
                                    <?php echo $ai_diag; ?>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if (!empty($ai_results)): ?>
                                <div class="card border-0 shadow-sm mb-4" style="border: 1px solid #e2e8f0 !important; border-radius: 12px; overflow: hidden;">
                                    <div class="px-4 py-3" style="background: linear-gradient(135deg, #7c3aed, #6366f1); color: #ffffff;">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-robot fa-lg"></i>
                                            <strong>Gemini AI Review Results</strong>
                                            <span class="badge bg-white text-dark ms-auto font-monospace px-3 py-1" style="font-size:0.75rem;">
                                                <?php echo count($ai_results); ?> recommendation(s)
                                            </span>
                                        </div>
                                        <p class="mb-0 mt-1 small" style="opacity: 0.85;">
                                            Review the AI suggestions below, then click "Apply Selected" to process them.
                                        </p>
                                    </div>
                                    <div style="background: #faf5ff; border-bottom: 1px solid #e9d5ff;">
                                        <form method="post" class="m-0">
                                            <?php echo csrf_field('admin_csrf'); ?>
                                            <input type="hidden" name="action" value="apply_ai_results">
                                            <div class="table-responsive">
                                                <table class="table table-ledger mb-0 align-middle">
                                                    <thead>
                                                        <tr>
                                                            <th style="width: 40px;">✓</th>
                                                            <th>Booking ID</th>
                                                            <th>Decision</th>
                                                            <th>AI Reasoning</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($ai_results as $item):
                                                            $is_approve = $item['decision'] === 'APPROVE';
                                                            $badge_class = $is_approve ? 'badge-state-approved' : 'badge-state-rejected';
                                                            $badge_text = $is_approve ? 'Approved' : 'Rejected';
                                                        ?>
                                                            <tr>
                                                                <td>
                                                                    <input type="checkbox" name="decision[<?php echo (int)$item['booking_id']; ?>]"
                                                                           value="<?php echo $is_approve ? 'Approved' : 'Rejected'; ?>"
                                                                           checked class="form-check-input">
                                                                    <input type="hidden" name="reason[<?php echo (int)$item['booking_id']; ?>]"
                                                                           value="<?php echo htmlspecialchars($item['reason'] ?? ''); ?>">
                                                                </td>
                                                                <td class="font-monospace text-muted small">#<?php echo (int)$item['booking_id']; ?></td>
                                                                <td>
                                                                    <span class="status-badge <?php echo $badge_class; ?>">
                                                                        <i class="fas fa-<?php echo $is_approve ? 'check' : 'times'; ?> me-1"></i>
                                                                        <?php echo $badge_text; ?>
                                                                    </span>
                                                                </td>
                                                                <td class="text-muted small" style="max-width: 300px;">
                                                                    <?php echo htmlspecialchars($item['reason'] ?? ''); ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                            <div class="p-3 d-flex justify-content-end gap-2" style="background: #ffffff; border-top: 1px solid #f1f5f9;">
                                                <button type="submit" class="btn" style="background:#7c3aed;color:#ffffff;font-weight:600;font-size:0.85rem;padding:0.45rem 1.2rem;border-radius:6px;border:none;">
                                                    <i class="fas fa-check-double me-1"></i> Apply Selected (<?php echo count($ai_results); ?>)
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="card ledger-card">
                <div class="ledger-header">
                    <h5 class="mb-0 text-dark fw-bold fs-6">Active Resource Matrix Logs</h5>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-ledger mb-0 align-middle">
                        <thead>
                            <tr>
                                <th style="width: 70px;" class="text-center">ID</th>
                                <th>Account Member</th>
                                <th>Club Facility</th>
                                <th>Assigned Coach</th>
                                <th>Category</th>
                                <th>Reserved Date</th>
                                <th>Timeline Block</th>
                                <th>Current State</th>
                                <th style="width: 160px;">State Override</th>
                                <th style="width: 120px;" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($bookings) > 0): ?>
                                <?php foreach ($bookings as $booking): 
                                    $currentState = strtolower($booking["status"]);
                                    $badgeClass = "badge-state-pending";
                                    if ($currentState === 'approved')  $badgeClass = "badge-state-approved";
                                    if ($currentState === 'completed') $badgeClass = "badge-state-completed";
                                    if ($currentState === 'rejected')  $badgeClass = "badge-state-rejected";
                                    
                                    $coachName = trim($booking["coach_first_name"] . " " . $booking["coach_last_name"]);
                                    if (empty($coachName)) $coachName = "—";
                                ?>
                                    <tr>
                                        <td class="text-center font-monospace text-muted small"><?php echo htmlspecialchars($booking["booking_id"]); ?></td>
                                        <td class="text-cell-dark"><?php echo htmlspecialchars($booking["first_name"] . " " . $booking["last_name"]); ?></td>
                                        <td><?php echo htmlspecialchars($booking["facility_name"]); ?></td>
                                        <td class="text-muted"><?php echo htmlspecialchars($coachName); ?></td>
                                        <td><span class="badge bg-light text-secondary border px-2 py-1 rounded"><?php echo htmlspecialchars($booking["sport_name"]); ?></span></td>
                                        <td class="font-monospace small"><?php echo htmlspecialchars(date("d M Y", strtotime($booking["booking_date"]))); ?></td>
                                        <td class="font-monospace text-muted small"><?php echo htmlspecialchars(date("H:i", strtotime($booking["start_time"])) . " - " . date("H:i", strtotime($booking["end_time"]))); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $badgeClass; ?>">
                                                <?php echo htmlspecialchars($booking["status"]); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="m-0">
                                                <?php echo csrf_field('admin_csrf'); ?>
                                                <input type="hidden" name="booking_id" value="<?php echo intval($booking["booking_id"]); ?>">
                                                <input type="hidden" name="action" value="update_status">
                                                <select name="status" class="form-select form-select-sm select-interactive w-100" onchange="this.form.submit()">
                                                    <option value="Pending" <?php echo ($booking["status"] == "Pending") ? "selected" : ""; ?>>Pending</option>
                                                    <option value="Approved" <?php echo ($booking["status"] == "Approved") ? "selected" : ""; ?>>Approved</option>
                                                    <option value="Rejected" <?php echo ($booking["status"] == "Rejected") ? "selected" : ""; ?>>Rejected</option>
                                                    <option value="Completed" <?php echo ($booking["status"] == "Completed") ? "selected" : ""; ?>>Completed</option>
                                                </select>
                                            </form>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a href="javascript:void(0)" class="btn btn-action-view btn-sm disabled">
                                                    <i class="far fa-file-alt"></i>
                                                </a>
                                                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="m-0" onsubmit="return confirm('Confirm Deletion? Reordering system sequences can alter active tracking histories.');">
                                                    <?php echo csrf_field('admin_csrf'); ?>
                                                    <input type="hidden" name="booking_id" value="<?php echo intval($booking["booking_id"]); ?>">
                                                    <input type="hidden" name="action" value="delete_booking">
                                                    <button type="submit" class="btn btn-action-delete btn-sm">
                                                        <i class="far fa-trash-can"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="10" class="text-center py-5 text-muted">
                                        <i class="far fa-folder-open fa-2x mb-2 d-block text-black-50"></i>
                                        No reservation system records tracking inside this datastore.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                        <div class="card-footer bg-white border-top d-flex justify-content-between align-items-center py-3">
                            <div class="text-muted small">
                                Showing <?php echo $offset + 1; ?> to <?php echo min($total_bookings, $offset + $per_page); ?> of <?php echo $total_bookings; ?> bookings
                            </div>
                            <nav aria-label="Page navigation">
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>">Previous</a>
                                    </li>
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                        <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <li class="page-item <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>">Next</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
