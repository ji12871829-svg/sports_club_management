<?php
/**
 * admin/churn_prediction.php
 * AI-powered member churn prediction dashboard
 */
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once __DIR__ . '/../includes/module_loader.php'; // lazy-loads ai/churn modules
require_once "../includes/csrf.php";
require_once "../includes/activity_log.php";
require_once __DIR__ . '/../includes/cache.php';

$message = '';
$ai_error = '';
$ai_diag = '';
$ai_results = [];
$bulk_results = [];

// Invalidate the cached risk list whenever a POST action modifies data.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cache_delete('churn_high_risk');
}

// ── Fetch members at risk (cached 60s — the list changes only via cron/analysis) ──
$high_risk = cache_remember('churn_high_risk', 60, function () use ($conn) {
    $rows = [];
    $stmt = $conn->prepare("
        SELECT m.member_id, m.first_name, m.last_name, m.email, m.phone_number,
               COALESCE(mcr.risk_score, 0) AS risk_score,
               COALESCE(mcr.risk_level, 'unknown') AS risk_level,
               COALESCE(mcr.engagement_score, 0) AS engagement_score,
               COALESCE(mcr.last_login_days_ago, DATEDIFF(CURDATE(), m.date_joined)) AS days_since_login,
               COALESCE(mcr.last_booking_days_ago, 999) AS days_since_booking,
               COALESCE(mcr.booking_frequency_trend, 'unknown') AS booking_trend,
               mcr.retention_actions_taken,
               DATEDIFF(CURDATE(), m.date_joined) AS member_days
        FROM members m
        LEFT JOIN member_churn_risk mcr ON m.member_id = mcr.member_id
        ORDER BY
            CASE mcr.risk_level
                WHEN 'critical' THEN 0
                WHEN 'high' THEN 1
                WHEN 'medium' THEN 2
                WHEN 'low' THEN 3
                ELSE 4
            END ASC,
            mcr.risk_score DESC,
            m.date_joined DESC
        LIMIT 100
    ");
    if ($stmt) {
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
        $stmt->close();
    }
    return $rows;
});

// ── Handle AI Analysis ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze_churn') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $ai_error = 'Security check failed.';
    } else {
        $member_id = (int)($_POST['member_id'] ?? 0);
        if ($member_id <= 0) {
            $ai_error = 'Invalid member.';
        } else {
            asc_require_module('ai'); // load AI client only when analyzing
            $key_status = asc_gemini_api_key_status();
            $use_deterministic = empty($key_status['ready']);
            if ($use_deterministic) {
                $ai_diag = asc_ai_diagnostics_panel();
            }
            {
                // Fetch member details
                $member = null;
                $stmt = $conn->prepare("SELECT m.first_name, m.last_name, m.email, m.phone_number,
                    COALESCE(mcr.risk_score, 0) AS risk_score,
                    COALESCE(mcr.risk_level, 'unknown') AS risk_level,
                    COALESCE(mcr.engagement_score, 0) AS engagement_score,
                    COALESCE(mcr.last_login_days_ago, DATEDIFF(CURDATE(), m.date_joined)) AS days_since_login,
                    COALESCE(mcr.last_booking_days_ago, 999) AS days_since_booking,
                    COALESCE(mcr.booking_frequency_trend, 'unknown') AS booking_trend,
                    DATEDIFF(CURDATE(), m.date_joined) AS member_days
                    FROM members m
                    LEFT JOIN member_churn_risk mcr ON m.member_id = mcr.member_id
                    WHERE m.member_id = ? LIMIT 1");
                if ($stmt) {
                    $stmt->bind_param('i', $member_id);
                    $stmt->execute();
                    $member = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                }

                if (!$member) {
                    $ai_error = 'Member not found.';
                } else {
                    // Count recent activity
                    $recent_bookings = 0;
                    $stmt2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE member_id = ? AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
                    if ($stmt2) { $stmt2->bind_param('i', $member_id); $stmt2->execute(); $recent_bookings = $stmt2->get_result()->fetch_assoc()['cnt']; $stmt2->close(); }

                    $recent_sports = [];
                    $stmt3 = $conn->prepare("SELECT DISTINCT s.name FROM bookings b JOIN sports s ON b.sport_id = s.sport_id WHERE b.member_id = ? AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) LIMIT 5");
                    if ($stmt3) { $stmt3->bind_param('i', $member_id); $stmt3->execute(); $res3 = $stmt3->get_result(); while ($r = $res3->fetch_assoc()) $recent_sports[] = $r['name']; $res3->free(); $stmt3->close(); }

                    $total_payments = 0;
                    $stmt4 = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE member_id = ?");
                    if ($stmt4) { $stmt4->bind_param('i', $member_id); $stmt4->execute(); $total_payments = $stmt4->get_result()->fetch_assoc()['total']; $stmt4->close(); }

                    $risk_label = strtoupper($member['risk_level']);
                    $risk_icon = $member['risk_level'] === 'critical' ? '<i class="fas fa-circle" style="color:#dc2626;"></i>' : ($member['risk_level'] === 'high' ? '<i class="fas fa-circle" style="color:#f97316;"></i>' : ($member['risk_level'] === 'medium' ? '<i class="fas fa-circle" style="color:#eab308;"></i>' : '<i class="fas fa-circle" style="color:#16a34a;"></i>'));

                    $prompt = "You are a sports club retention analyst. Analyze this member's data and suggest 3-5 specific, actionable retention actions. Be practical and specific.\n\n"
                        . "Member: {$member['first_name']} {$member['last_name']}\n"
                        . "Email: {$member['email']}, Phone: {$member['phone_number']}\n"
                        . "Member for: {$member['member_days']} days\n"
                        . "Churn Risk: {$risk_label} (Score: {$member['risk_score']}/100)\n"
                        . "Engagement Score: {$member['engagement_score']}/100\n"
                        . "Days since last login: {$member['days_since_login']}\n"
                        . "Days since last booking: {$member['days_since_booking']}\n"
                        . "Booking trend: {$member['booking_trend']}\n"
                        . "Recent bookings (90 days): {$recent_bookings}\n"
                        . "Recent sports: " . (empty($recent_sports) ? 'None' : implode(', ', $recent_sports)) . "\n"
                        . "Total payments: KES " . number_format($total_payments, 0) . "\n\n"
                        . "Respond with ONLY a JSON object:\n"
                        . "{\n"
                        . "  \"risk_summary\": \"1-2 sentence assessment\",\n"
                        . "  \"retention_actions\": [\"action 1\", \"action 2\", \"action 3\", ...],\n"
                        . "  \"estimated_churn_probability\": \"Low/Medium/High/Very High\",\n"
                        . "  \"recommended_contact\": \"Email/Phone/In-person/WhatsApp\",\n"
                        . "  \"urgency\": \"Low/Medium/High/Immediate\"\n"
                        . "}";

                    if ($use_deterministic) {
                        // ── Deterministic fallback (no AI key required) ──
                        asc_require_module('churn');
                        $analytics = new ChurnWellnessAnalytics($conn);
                        $det = $analytics->analyzeMemberChurnRisk($member_id);
                        if (is_array($det)) {
                            $det_actions = $analytics->recommendRetentionActions($member_id) ?: ['Contact member for a wellness check-in'];
                            $prob = $det['risk_level'] === 'critical' ? 'Very High' : ($det['risk_level'] === 'high' ? 'High' : ($det['risk_level'] === 'medium' ? 'Medium' : 'Low'));
                            $ai_results = [
                                'member_id' => $member_id,
                                'member_name' => $member['first_name'] . ' ' . $member['last_name'],
                                'analysis' => [
                                    'risk_summary' => 'Rule-based assessment (AI key not configured): risk level ' . strtoupper($det['risk_level']) . ' (score ' . $det['risk_score'] . '/100). ' . (implode('; ', $det['risk_factors']) ?: 'No dominant risk factors.'),
                                    'retention_actions' => $det_actions,
                                    'estimated_churn_probability' => $prob,
                                    'recommended_contact' => 'Email',
                                    'urgency' => ($det['risk_level'] === 'critical' || $det['risk_level'] === 'high') ? 'High' : 'Medium',
                                ],
                            ];
                            log_activity($conn, 'Rule-based churn analysis for #' . $member_id, 'Members', $member_id, 'Risk: ' . $det['risk_level'] . ' | deterministic (no AI key)');
                            $message = '<div class="alert alert-info border-0 shadow-sm"><i class="fas fa-chart-line me-2"></i>Deterministic analysis complete for ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) . ' (rule-based scoring — AI key not configured).</div>';
                        } else {
                            $ai_error = 'Could not analyze member churn risk.';
                        }
                    } else {
                        $result = asc_gemini_generate_text($prompt, [
                        'temperature' => 0.3,
                        'maxOutputTokens' => 800,
                        'timeout' => 25,
                        ]);

                        if (!empty($result['success'])) {
                        $text = trim($result['text']);
                        $json_str = '';
                        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $text, $m)) {
                            $json_str = trim($m[1]);
                        } elseif (preg_match('/\{[\s\S]*\}/', $text, $m)) {
                            $json_str = $m[0];
                        } else {
                            $json_str = $text;
                        }

                        $parsed = json_decode($json_str, true);
                        if (is_array($parsed) && !empty($parsed['retention_actions'])) {
                            $ai_results = [
                                'member_id' => $member_id,
                                'member_name' => $member['first_name'] . ' ' . $member['last_name'],
                                'analysis' => $parsed,
                            ];

                            // Store actions taken
                            $actions_text = implode('; ', $parsed['retention_actions']);
                            $upd = $conn->prepare("UPDATE member_churn_risk SET retention_actions_taken = ? WHERE member_id = ?");
                            if ($upd) {
                                $upd->bind_param('si', $actions_text, $member_id);
                                $upd->execute();
                                $upd->close();
                            }

                            log_activity($conn, 'AI churn analysis for #' . $member_id, 'Members', $member_id, 'Risk: ' . $risk_label . ' | ' . count($parsed['retention_actions']) . ' actions suggested');
                            $message = '<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i>AI analysis complete for ' . htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) . '.</div>';
                        } else {
                            $ai_error = 'Could not parse AI response. Try again.';
                            $_SESSION['ai_raw_response'] = substr($text, 0, 2000);
                        }
                        } else {
                            $ai_error = $result['error'] ?? 'AI did not respond.';
                        }
                    }
                }
            }
        }
    }
}

// ── Handle Bulk AI Analysis ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_analyze_churn') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $ai_error = 'Security check failed.';
    } else {
        asc_require_module('ai'); // load AI client only when analyzing
        $key_status = asc_gemini_api_key_status();
        $use_deterministic = empty($key_status['ready']);
        if ($use_deterministic) {
            $ai_diag = asc_ai_diagnostics_panel();
        }
        {
            $batch_size = min(10, max(1, (int)($_POST['batch_size'] ?? 5)));
            
            // Get top N highest-risk members (prefer those without existing AI analysis)
            $stmt = $conn->prepare("
                SELECT m.member_id, m.first_name, m.last_name
                FROM members m
                LEFT JOIN member_churn_risk mcr ON m.member_id = mcr.member_id
                WHERE mcr.retention_actions_taken IS NULL
                ORDER BY mcr.risk_score DESC, m.date_joined DESC
                LIMIT ?
            ");
            if (!$stmt) {
                $stmt = $conn->prepare("
                    SELECT m.member_id, m.first_name, m.last_name
                    FROM members m
                    LEFT JOIN member_churn_risk mcr ON m.member_id = mcr.member_id
                    ORDER BY mcr.risk_score DESC, m.date_joined DESC
                    LIMIT ?
                ");
            }
            
            if ($stmt) {
                $stmt->bind_param('i', $batch_size);
                $stmt->execute();
                $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $stmt->close();

                $succeeded = 0;
                $failed = 0;
                $errors_detail = [];

                foreach ($candidates as $candidate) {
                    $member_id = (int)$candidate['member_id'];
                    
                    // Fetch member details with churn data
                    $member = null;
                    $st = $conn->prepare("SELECT m.first_name, m.last_name, m.email, m.phone_number,
                        COALESCE(mcr.risk_score, 0) AS risk_score,
                        COALESCE(mcr.risk_level, 'unknown') AS risk_level,
                        COALESCE(mcr.engagement_score, 0) AS engagement_score,
                        COALESCE(mcr.last_login_days_ago, DATEDIFF(CURDATE(), m.date_joined)) AS days_since_login,
                        COALESCE(mcr.last_booking_days_ago, 999) AS days_since_booking,
                        COALESCE(mcr.booking_frequency_trend, 'unknown') AS booking_trend,
                        DATEDIFF(CURDATE(), m.date_joined) AS member_days
                        FROM members m
                        LEFT JOIN member_churn_risk mcr ON m.member_id = mcr.member_id
                        WHERE m.member_id = ? LIMIT 1");
                    if ($st) {
                        $st->bind_param('i', $member_id);
                        $st->execute();
                        $member = $st->get_result()->fetch_assoc();
                        $st->close();
                    }

                    if (!$member) {
                        $failed++;
                        $errors_detail[] = "#{$member_id}: Member not found";
                        continue;
                    }

                    // Gather activity data
                    $recent_bookings = 0;
                    $st2 = $conn->prepare("SELECT COUNT(*) AS cnt FROM bookings WHERE member_id = ? AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
                    if ($st2) { $st2->bind_param('i', $member_id); $st2->execute(); $recent_bookings = $st2->get_result()->fetch_assoc()['cnt']; $st2->close(); }

                    $recent_sports = [];
                    $st3 = $conn->prepare("SELECT DISTINCT s.name FROM bookings b JOIN sports s ON b.sport_id = s.sport_id WHERE b.member_id = ? AND b.booking_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) LIMIT 5");
                    if ($st3) { $st3->bind_param('i', $member_id); $st3->execute(); $res3 = $st3->get_result(); while ($r = $res3->fetch_assoc()) $recent_sports[] = $r['name']; $res3->free(); $st3->close(); }

                    $total_payments = 0;
                    $st4 = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE member_id = ?");
                    if ($st4) { $st4->bind_param('i', $member_id); $st4->execute(); $total_payments = $st4->get_result()->fetch_assoc()['total']; $st4->close(); }

                    $risk_label = strtoupper($member['risk_level']);

                    $prompt = "You are a sports club retention analyst. Analyze this member's data and suggest 3-5 specific, actionable retention actions. Be practical and specific.\n\n"
                        . "Member: {$member['first_name']} {$member['last_name']}\n"
                        . "Email: {$member['email']}, Phone: {$member['phone_number']}\n"
                        . "Member for: {$member['member_days']} days\n"
                        . "Churn Risk: {$risk_label} (Score: {$member['risk_score']}/100)\n"
                        . "Engagement Score: {$member['engagement_score']}/100\n"
                        . "Days since last login: {$member['days_since_login']}\n"
                        . "Days since last booking: {$member['days_since_booking']}\n"
                        . "Booking trend: {$member['booking_trend']}\n"
                        . "Recent bookings (90 days): {$recent_bookings}\n"
                        . "Recent sports: " . (empty($recent_sports) ? 'None' : implode(', ', $recent_sports)) . "\n"
                        . "Total payments: KES " . number_format($total_payments, 0) . "\n\n"
                        . "Respond with ONLY a JSON object:\n"
                        . "{\n"
                        . "  \"retention_actions\": [\"action 1\", \"action 2\", \"action 3\", ...],\n"
                        . "  \"estimated_churn_probability\": \"Low/Medium/High/Very High\",\n"
                        . "  \"urgency\": \"Low/Medium/High/Immediate\"\n"
                        . "}";

                    if ($use_deterministic) {
                        // ── Deterministic fallback per member (no AI key) ──
                        asc_require_module('churn');
                        $analytics = new ChurnWellnessAnalytics($conn);
                        $det = $analytics->analyzeMemberChurnRisk($member_id);
                        if (is_array($det)) {
                            $det_actions = $analytics->recommendRetentionActions($member_id) ?: ['Contact member for a wellness check-in'];
                            $actions_text = implode('; ', $det_actions);
                            $upd = $conn->prepare("UPDATE member_churn_risk SET retention_actions_taken = ? WHERE member_id = ?");
                            if ($upd) {
                                $upd->bind_param('si', $actions_text, $member_id);
                                $upd->execute();
                                $upd->close();
                            }
                            log_activity($conn, 'Bulk rule-based churn analysis for #' . $member_id, 'Members', $member_id, 'Risk: ' . $det['risk_level']);
                            $succeeded++;
                            $bulk_results[] = [
                                'name' => $member['first_name'] . ' ' . $member['last_name'],
                                'status' => 'success',
                                'actions_count' => count($det_actions),
                                'probability' => $det['risk_level'] === 'critical' ? 'Very High' : ($det['risk_level'] === 'high' ? 'High' : ($det['risk_level'] === 'medium' ? 'Medium' : 'Low')),
                            ];
                        } else {
                            $failed++;
                            $errors_detail[] = "#{$member_id} {$candidate['first_name']}: Could not analyze";
                        }
                    } else {
                        $resp = asc_gemini_generate_text($prompt, [
                        'temperature' => 0.3,
                        'maxOutputTokens' => 600,
                        'timeout' => 25,
                        ]);

                        if (!empty($resp['success'])) {
                        $text = trim($resp['text']);
                        $json_str = '';
                        if (preg_match('/```json\s*([\s\S]*?)\s*```/', $text, $m)) {
                            $json_str = trim($m[1]);
                        } elseif (preg_match('/\{[\s\S]*\}/', $text, $m)) {
                            $json_str = $m[0];
                        } else {
                            $json_str = $text;
                        }

                        $parsed = json_decode($json_str, true);
                        if (is_array($parsed) && !empty($parsed['retention_actions'])) {
                            $actions_text = implode('; ', $parsed['retention_actions']);
                            $upd = $conn->prepare("UPDATE member_churn_risk SET retention_actions_taken = ? WHERE member_id = ?");
                            if ($upd) {
                                $upd->bind_param('si', $actions_text, $member_id);
                                $upd->execute();
                                $upd->close();
                            }
                            log_activity($conn, 'Bulk AI churn analysis for #' . $member_id, 'Members', $member_id, 'Risk: ' . $risk_label);
                            $succeeded++;
                            $bulk_results[] = [
                                'name' => $member['first_name'] . ' ' . $member['last_name'],
                                'status' => 'success',
                                'actions_count' => count($parsed['retention_actions']),
                                'probability' => $parsed['estimated_churn_probability'] ?? 'N/A',
                            ];
                        } else {
                            $failed++;
                            $errors_detail[] = "#{$member_id} {$candidate['first_name']}: Could not parse AI response";
                        }
                        } else {
                            $failed++;
                            $errors_detail[] = "#{$member_id} {$candidate['first_name']}: " . ($resp['error'] ?? 'No response');
                        }
                    }
                }

                if ($succeeded > 0) {
                    $message = '<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i>Bulk analysis complete: <strong>' . $succeeded . '</strong> members analyzed successfully' . ($failed > 0 ? ', <strong>' . $failed . '</strong> failed.' : '.') . '</div>';
                } else {
                    $ai_error = 'Bulk analysis completed but no members were analyzed successfully. ' . ($failed > 0 ? $failed . ' failed.' : 'No candidates found.');
                }

                if (!empty($errors_detail)) {
                    $_SESSION['bulk_errors'] = $errors_detail;
                }
            }
        }
    }
}

$conn->close();
?>

<div class="container-fluid my-4 px-md-4">
    <!-- Page header -->
    <div class="asc-page-head">
        <div>
            <h2 class="asc-page-title"><i class="fas fa-heartbeat me-2" style="color:#ef4444;"></i>Churn Prediction</h2>
            <p class="asc-page-sub">AI-powered member retention analysis — identify at-risk members and get retention recommendations.</p>
        </div>
        <div class="asc-page-actions">
            <span class="asc-badge asc-badge-neutral">
                <i class="fas fa-users" style="font-size:.55rem;"></i>
                <?php echo count($high_risk); ?> tracked
            </span>
            <form method="post" class="m-0 d-flex align-items-center gap-2">
                <?php echo csrf_field('admin_csrf'); ?>
                <input type="hidden" name="action" value="bulk_analyze_churn">
                <select name="batch_size" class="form-select form-select-sm" style="width:auto;font-size:.78rem;border-radius:var(--asc-radius-sm);padding:.3rem .6rem;">
                    <option value="5">Top 5</option>
                    <option value="10" selected>Top 10</option>
                </select>
                <button type="submit" class="asc-btn asc-btn-primary">
                    <i class="fas fa-bolt"></i> Bulk Analyze
                </button>
            </form>
            <a href="manage_members.php" class="asc-btn asc-btn-ghost">
                <i class="fas fa-users"></i> All Members
            </a>
        </div>
    </div>

    <?php if ($message) echo $message; ?>
    <?php if (!empty($ai_error)): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($ai_error); ?>
            <?php if (!empty($_SESSION['ai_raw_response'])): ?>
                <details class="mt-2"><summary class="fw-semibold small">View raw AI response</summary>
                    <pre class="mt-1 p-2 bg-light rounded small" style="max-height:200px;overflow:auto;"><?php echo htmlspecialchars($_SESSION['ai_raw_response']); unset($_SESSION['ai_raw_response']); ?></pre>
                </details>
            <?php endif; ?>
        </div>
        <?php if (!empty($ai_diag)): ?>
            <?php echo $ai_diag; ?>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Bulk Analysis Summary -->
    <?php if (!empty($bulk_results)): ?>
    <div class="asc-card mb-4">
        <div class="asc-card-head">
            <h6 class="asc-card-title"><i class="fas fa-bolt me-1" style="color:var(--asc-success);"></i> Bulk Analysis Results</h6>
            <span class="asc-badge asc-badge-success"><?php echo count($bulk_results); ?> processed</span>
        </div>
        <div class="asc-card-body">
            <div class="asc-table-wrap">
                <table class="asc-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Member</th>
                            <th>Actions</th>
                            <th>Churn Probability</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bulk_results as $i => $br): ?>
                        <tr>
                            <td class="mono text-muted"><?php echo $i + 1; ?></td>
                            <td class="fw-semibold"><?php echo htmlspecialchars($br['name']); ?></td>
                            <td><span class="asc-badge asc-badge-success"><?php echo (int)$br['actions_count']; ?> actions</span></td>
                            <td><?php echo htmlspecialchars($br['probability']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($_SESSION['bulk_errors'])): ?>
                <details class="px-4 py-3"><summary class="small fw-semibold" style="color:var(--asc-danger);">View <?php echo count($_SESSION['bulk_errors']); ?> errors</summary>
                    <ul class="small mb-0 mt-1 px-4" style="color:var(--asc-danger-ink);">
                        <?php foreach ($_SESSION['bulk_errors'] as $e): ?>
                            <li><?php echo htmlspecialchars($e); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </details>
                <?php unset($_SESSION['bulk_errors']); ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- AI / Rule-based Analysis Result -->
    <?php if (!empty($ai_results)): ?>
    <div class="asc-dark-panel mb-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h5 class="mb-0 fw-bold" style="color:#fff;"><i class="fas fa-robot me-2" style="color:#7da8cf;"></i><?php echo !empty($ai_results['analysis']['risk_summary']) && str_contains($ai_results['analysis']['risk_summary'], 'Rule-based') ? 'Rule-based Retention Analysis' : 'AI Retention Analysis'; ?></h5>
            <span class="badge font-monospace" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.8);font-size:.72rem;">
                <?php echo htmlspecialchars($ai_results['member_name']); ?>
            </span>
        </div>
        <p class="mb-3" style="color:rgba(226,232,240,.75);font-size:.88rem;line-height:1.5;max-width:70ch;"><?php echo htmlspecialchars($ai_results['analysis']['risk_summary'] ?? ''); ?></p>
        <?php if (!empty($ai_results['analysis']['retention_actions'])): ?>
            <h6 class="fw-semibold mb-2" style="color:#7da8cf;font-size:.82rem;">Recommended Actions</h6>
            <div class="asc-analysis-actions mb-3">
                <?php foreach ($ai_results['analysis']['retention_actions'] as $action): ?>
                    <div class="asc-analysis-action">
                        <i class="fas fa-arrow-right"></i>
                        <span><?php echo htmlspecialchars($action); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-3 pt-3 border-top" style="border-color:rgba(255,255,255,.1) !important;">
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-chart-pie" style="color:rgba(226,232,240,.4);font-size:.72rem;"></i>
                <small style="color:rgba(226,232,240,.55);"><strong style="color:rgba(226,232,240,.75);">Churn:</strong> <?php echo htmlspecialchars($ai_results['analysis']['estimated_churn_probability'] ?? 'N/A'); ?></small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-phone" style="color:rgba(226,232,240,.4);font-size:.72rem;"></i>
                <small style="color:rgba(226,232,240,.55);"><strong style="color:rgba(226,232,240,.75);">Contact:</strong> <?php echo htmlspecialchars($ai_results['analysis']['recommended_contact'] ?? 'N/A'); ?></small>
            </div>
            <div class="d-flex align-items-center gap-2">
                <i class="fas fa-bolt" style="color:rgba(226,232,240,.4);font-size:.72rem;"></i>
                <small style="color:rgba(226,232,240,.55);"><strong style="color:rgba(226,232,240,.75);">Urgency:</strong> <?php echo htmlspecialchars($ai_results['analysis']['urgency'] ?? 'N/A'); ?></small>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Member Risk Table -->
    <div class="asc-card">
        <div class="asc-card-head">
            <h6 class="asc-card-title"><i class="fas fa-table me-1"></i> Member Churn Risk Matrix</h6>
            <span class="asc-badge asc-badge-neutral"><?php echo count($high_risk); ?> members</span>
        </div>
        <div class="asc-card-body">
            <?php if (empty($high_risk)): ?>
                <div class="asc-empty">
                    <i class="fas fa-chart-line"></i>
                    <p>No member churn data yet. Members will be analyzed as they interact with the system.</p>
                </div>
            <?php else: ?>
                <div class="asc-table-wrap">
                    <table class="asc-churn-table">
                        <thead>
                            <tr>
                                <th style="width:36px">#</th>
                                <th>Member</th>
                                <th>Risk</th>
                                <th>Score</th>
                                <th>Engagement</th>
                                <th>Inactive</th>
                                <th>Trend</th>
                                <th style="width:100px"></th>
                                <th>Retention Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($high_risk as $i => $m):
                                $risk_class = 'asc-badge-' . ($m['risk_level'] === 'critical' ? 'danger' : ($m['risk_level'] === 'high' ? 'warning' : ($m['risk_level'] === 'medium' ? 'info' : ($m['risk_level'] === 'low' ? 'success' : 'neutral'))));
                                $score_pct = min(100, (int)$m['risk_score']);
                                $score_level = $score_pct >= 75 ? 'critical' : ($score_pct >= 50 ? 'high' : ($score_pct >= 25 ? 'medium' : 'low'));
                            ?>
                            <tr>
                                <td class="mono text-muted"><?php echo $i + 1; ?></td>
                                <td>
                                    <div class="fw-semibold" style="font-size:.85rem;"><?php echo htmlspecialchars($m['first_name'] . ' ' . $m['last_name']); ?></div>
                                    <div style="font-size:.72rem;color:var(--asc-faint);"><?php echo htmlspecialchars($m['email'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <span class="asc-badge <?php echo $risk_class; ?>">
                                        <?php echo ucfirst($m['risk_level']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="mono fw-bold"><?php echo (int)$m['risk_score']; ?></span>
                                        <div class="asc-risk-score-track">
                                            <div class="asc-risk-score-fill <?php echo $score_level; ?>" style="width:<?php echo $score_pct; ?>%;"></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="mono" style="font-size:.82rem;"><?php echo number_format((float)$m['engagement_score'], 1); ?></td>
                                <td>
                                    <span class="mono" style="font-size:.82rem;"><?php echo (int)$m['days_since_login']; ?>d</span>
                                    <span class="mono" style="font-size:.82rem;color:var(--asc-faint);"> / <?php echo (int)$m['days_since_booking']; ?>d</span>
                                </td>
                                <td>
                                    <?php if ($m['booking_trend'] === 'declining'): ?>
                                        <span class="asc-badge asc-badge-danger" style="font-size:.62rem;"><i class="fas fa-arrow-down" style="font-size:.5rem;"></i> Declining</span>
                                    <?php elseif ($m['booking_trend'] === 'improving'): ?>
                                        <span class="asc-badge asc-badge-success" style="font-size:.62rem;"><i class="fas fa-arrow-up" style="font-size:.5rem;"></i> Improving</span>
                                    <?php else: ?>
                                        <span class="asc-badge asc-badge-neutral" style="font-size:.62rem;"><i class="fas fa-minus" style="font-size:.5rem;"></i> Flat</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <form method="post" class="m-0">
                                        <?php echo csrf_field('admin_csrf'); ?>
                                        <input type="hidden" name="action" value="analyze_churn">
                                        <input type="hidden" name="member_id" value="<?php echo (int)$m['member_id']; ?>">
                                        <button type="submit" class="asc-btn asc-btn-primary" style="font-size:.75rem;padding:.3rem .6rem;">
                                            <i class="fas fa-robot"></i> Analyze
                                        </button>
                                    </form>
                                </td>
                                <td class="asc-retention-note">
                                    <?php echo !empty($m['retention_actions_taken']) ? htmlspecialchars(substr($m['retention_actions_taken'], 0, 80)) . (strlen($m['retention_actions_taken'] ?? '') > 80 ? '...' : '') : '<span style="color:var(--asc-faint);">—</span>'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once("../includes/footer.php"); ?>
