<?php
/**
 * admin/ai_smart_scheduling.php
 * AI-powered smart scheduling — recommends optimal coach & facility assignments for pending bookings
 */
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once "../includes/gemini_client.php";
require_once "../includes/csrf.php";
require_once "../includes/activity_log.php";

$message = '';
$ai_error = '';
$ai_suggestions = [];
$applied_count = 0;

// ── Fetch pending bookings ────────────────────────────────────────
$pending_bookings = [];
$stmt = $conn->prepare("
    SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.sport_id, b.facility_id, b.coach_id,
           COALESCE(m.first_name, '?') AS member_first,
           COALESCE(m.last_name, '?') AS member_last,
           COALESCE(s.name, 'Sport') AS sport_name,
           COALESCE(f.name, 'Facility') AS facility_name,
           COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'None') AS current_coach
    FROM bookings b
    LEFT JOIN members m ON b.member_id = m.member_id
    LEFT JOIN sports s ON b.sport_id = s.sport_id
    LEFT JOIN facilities f ON b.facility_id = f.facility_id
    LEFT JOIN coaches c ON b.coach_id = c.coach_id
    WHERE b.status = 'Pending'
    ORDER BY b.booking_date ASC, b.start_time ASC
");
if ($stmt) {
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $pending_bookings[] = $row;
    }
    $stmt->close();
}

// ── Fetch available coaches & facilities for AI context ──────────
$coaches_list = [];
$r = $conn->query("SELECT coach_id, first_name, last_name, specialization FROM coaches ORDER BY first_name");
if ($r) while ($row = $r->fetch_assoc()) $coaches_list[] = $row;

$facilities_list = [];
$r = $conn->query("SELECT facility_id, name, type, location FROM facilities ORDER BY name");
if ($r) while ($row = $r->fetch_assoc()) $facilities_list[] = $row;

// ── Fetch active maintenance blocks ─────────────────────────────
$maintenance_blocks = [];
$mr = $conn->query("SELECT fm.facility_id, f.name AS facility_name, fm.start_date, fm.end_date, fm.start_time, fm.end_time, fm.reason 
    FROM facility_maintenance fm 
    JOIN facilities f ON fm.facility_id = f.facility_id 
    WHERE fm.status IN ('Scheduled','In Progress') AND fm.blocks_bookings = 1 
    ORDER BY fm.start_date");
if ($mr) while ($row = $mr->fetch_assoc()) $maintenance_blocks[] = $row;

// ── Fetch coach availability schedules ────────────────────────
$coach_avail_records = [];
$ar = $conn->query("SELECT ca.*, c.first_name, c.last_name, c.specialization 
    FROM coach_availability ca 
    JOIN coaches c ON ca.coach_id = c.coach_id 
    WHERE ca.is_available = 1 
    ORDER BY c.first_name, ca.day_of_week");
if ($ar) while ($row = $ar->fetch_assoc()) $coach_avail_records[] = $row;

// ── Handle Apply AI Suggestion ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $ai_error = 'Security check failed.';
    } else {
        if ($_POST['action'] === 'apply_suggestion' && isset($_POST['booking_id'])) {
            $booking_id = (int) $_POST['booking_id'];
            $suggested_coach = (int) ($_POST['suggested_coach'] ?? 0);
            $upd = $conn->prepare("UPDATE bookings SET coach_id = ?, status = 'Approved' WHERE booking_id = ? AND status = 'Pending'");
            if ($upd) {
                $upd->bind_param('ii', $suggested_coach, $booking_id);
                if ($upd->execute() && $upd->affected_rows > 0) {
                    $applied_count++;
                    $coach_name = 'Auto-assigned';
                    foreach ($coaches_list as $c) {
                        if ($c['coach_id'] === $suggested_coach) {
                            $coach_name = $c['first_name'] . ' ' . $c['last_name'];
                            break;
                        }
                    }
                    log_activity($conn, 'AI scheduled booking #' . $booking_id, 'Bookings', $booking_id, 'Assigned coach: ' . $coach_name);
                }
                $upd->close();
            }
        } elseif ($_POST['action'] === 'apply_all' && isset($_POST['suggestions_json'])) {
            $all_suggestions = json_decode($_POST['suggestions_json'], true);
            if (is_array($all_suggestions)) {
                foreach ($all_suggestions as $sug) {
                    $bid = (int) ($sug['booking_id'] ?? 0);
                    $cid = (int) ($sug['recommended_coach_id'] ?? 0);
                    if ($bid > 0 && $cid > 0) {
                        $upd = $conn->prepare("UPDATE bookings SET coach_id = ?, status = 'Approved' WHERE booking_id = ? AND status = 'Pending'");
                        if ($upd) {
                            $upd->bind_param('ii', $cid, $bid);
                            if ($upd->execute() && $upd->affected_rows > 0) $applied_count++;
                            $upd->close();
                        }
                    }
                }
                if ($applied_count > 0) {
                    log_activity($conn, 'AI bulk scheduled ' . $applied_count . ' bookings', 'Bookings', 0, 'Auto-assigned coaches');
                }
            }
        } elseif ($_POST['action'] === 'get_schedule') {
            $key_status = asc_gemini_api_key_status();
            if (empty($key_status['ready'])) {
                $ai_error = $key_status['message'];
            } elseif (empty($pending_bookings)) {
                $ai_error = 'No pending bookings to schedule.';
            } else {
                // Build context for AI
                $booking_lines = [];
                foreach ($pending_bookings as $b) {
                    $booking_lines[] = "- Booking #{$b['booking_id']}: {$b['booking_date']} {$b['start_time']}-{$b['end_time']} | {$b['sport_name']} @ {$b['facility_name']} | Member: {$b['member_first']} {$b['member_last']} | Current coach: {$b['current_coach']}";
                }
                $booking_text = implode("\n", $booking_lines);

                $coach_options = implode(', ', array_map(fn($c) => "#{$c['coach_id']} {$c['first_name']} {$c['last_name']} ({$c['specialization']})", $coaches_list));
                $facility_options = implode(', ', array_map(fn($f) => "#{$f['facility_id']} {$f['name']} ({$f['type']})", $facilities_list));

                // Add maintenance info to AI context
                $maintenance_text = '';
                if (!empty($maintenance_blocks)) {
                    $mlines = array_map(fn($m) => "- {$m['facility_name']}: {$m['start_date']} to {$m['end_date']} (reason: {$m['reason']})", $maintenance_blocks);
                    $maintenance_text = "\n\nACTIVE MAINTENANCE BLOCKS (these facilities cannot be booked):\n" . implode("\n", $mlines);
                }

                // Add coach availability data
                $availability_text = '';
                if (!empty($coach_avail_records)) {
                    $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                    $alines = [];
                    foreach ($coach_avail_records as $a) {
                        $day_name = $days[$a['day_of_week']] ?? "Day {$a['day_of_week']}";
                        $alines[] = "- {$a['first_name']} {$a['last_name']} ({$a['specialization']}): {$day_name} {$a['start_time']}-{$a['end_time']}";
                    }
                    $availability_text = "\n\nCOACH AVAILABILITY SCHEDULE:\n" . implode("\n", $alines);
                }

                $prompt = "You are an AI sports club scheduling manager for Apex Sports Club. Your job is to assign the best coach to each pending booking.\n\n"
                    . "AVAILABLE COACHES:\n{$coach_options}\n\n"
                    . "AVAILABLE FACILITIES:\n{$facility_options}\n{$maintenance_text}\n{$availability_text}\n\n"
                    . "PENDING BOOKINGS:\n{$booking_text}\n\n"
                    . "TODAY'S DATE: " . date('Y-m-d') . "\n"
                    . "TIME NOW: " . date('H:i') . "\n\n"
                    . "For each pending booking, suggest the best coach to assign based on:\n"
                    . "- Coach specialization matching the sport\n"
                    . "- Coach availability schedule (which days/times they work)\n"
                    . "- Avoid assigning the same coach to overlapping bookings\n"
                    . "- Avoid maintenance-blocked facilities\n\n"
                    . "Respond with ONLY a JSON array. Each object must include the booking_id, recommended_coach_id (numeric ID), and a brief reason:\n"
                    . "[\n"
                    . "  {\n"
                    . "    \"booking_id\": 5,\n"
                    . "    \"recommended_coach_id\": 3,\n"
                    . "    \"reason\": \"Coach specializes in this sport and has availability\"\n"
                    . "  },\n"
                    . "  ...\n"
                    . "]";

                $result = asc_gemini_generate_text($prompt, [
                    'temperature' => 0.3,
                    'maxOutputTokens' => 1500,
                    'timeout' => 30,
                ]);

                if (!empty($result['success'])) {
                    $text = trim($result['text']);
                    $json_str = '';
                    if (preg_match('/```json\s*([\s\S]*?)\s*```/', $text, $m)) {
                        $json_str = trim($m[1]);
                    } elseif (preg_match('/\[[\s\S]*\]/', $text, $m)) {
                        $json_str = $m[0];
                    } else {
                        $json_str = $text;
                    }

                    $parsed = json_decode($json_str, true);
                    if (is_array($parsed) && count($parsed) > 0) {
                        $ai_suggestions = $parsed;
                        $message = '<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-robot me-2"></i>AI scheduling recommendations generated for <strong>' . count($parsed) . '</strong> bookings.</div>';
                    } else {
                        $ai_error = 'Could not parse AI response. Try again.';
                        $_SESSION['ai_raw_response'] = substr($text, 0, 2000);
                    }
                } else {
                    $ai_error = $result['error'] ?? 'AI did not respond.';
                }
            }
        }

        if ($applied_count > 0) {
            $message = '<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><strong>' . $applied_count . '</strong> booking(s) scheduled successfully!</div>';
            // Refresh pending bookings after apply
            $pending_bookings = [];
            $stmt = $conn->prepare("SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.sport_id, b.facility_id, b.coach_id, COALESCE(m.first_name,'?') AS member_first, COALESCE(m.last_name,'?') AS member_last, COALESCE(s.name,'Sport') AS sport_name, COALESCE(f.name,'Facility') AS facility_name, COALESCE(CONCAT(c.first_name,' ',c.last_name),'None') AS current_coach FROM bookings b LEFT JOIN members m ON b.member_id=m.member_id LEFT JOIN sports s ON b.sport_id=s.sport_id LEFT JOIN facilities f ON b.facility_id=f.facility_id LEFT JOIN coaches c ON b.coach_id=c.coach_id WHERE b.status='Pending' ORDER BY b.booking_date ASC, b.start_time ASC");
            if ($stmt) { $stmt->execute(); $res = $stmt->get_result(); while ($row = $res->fetch_assoc()) $pending_bookings[] = $row; $stmt->close(); }
        }
    }
}

$conn->close();
?>

<style>
body { background-color: #f8fafc !important; color: #334155; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.page-title { color: #0f172a; font-weight: 700; letter-spacing: -0.5px; }
.card-smart { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.04); transition: all .15s ease; }
.card-smart:hover { border-color: #cbd5e1; }
.ai-badge { font-weight: 700; font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.5px; padding: 0.15rem 0.5rem; border-radius: 4px; }
.suggestion-row { border-left: 4px solid #1a5a8c; background: #eef3f8; padding: 0.75rem 1rem; border-radius: 8px; }
.booking-row { border-left: 4px solid #e2e8f0; padding: 0.75rem 1rem; }
</style>

<div class="container-fluid my-4 px-md-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom border-light">
        <div>
            <h2 class="page-title mb-1"><i class="fas fa-robot me-2" style="color:#1a5a8c;"></i>AI Smart Scheduling</h2>
            <p class="text-muted small mb-0">Intelligent coach & facility assignment for pending bookings using AI optimization.</p>
        </div>
        <div class="d-flex gap-2">
            <span class="badge bg-dark px-3 py-2" style="font-size:0.75rem;">
                <?php echo count($pending_bookings); ?> pending
            </span>
            <span class="badge bg-light text-dark px-3 py-2" style="font-size:0.75rem;">
                <?php echo count($coaches_list); ?> coaches · <?php echo count($facilities_list); ?> facilities
            </span>
        </div>
    </div>

    <?php if ($message) echo $message; ?>
    <?php if ($ai_error): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <i class="fas fa-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($ai_error); ?>
            <?php if (!empty($_SESSION['ai_raw_response'])): ?>
                <details class="mt-2"><summary class="fw-semibold small">View raw AI response</summary>
                    <pre class="mt-1 p-2 bg-light rounded small" style="max-height:200px;overflow:auto;"><?php echo htmlspecialchars($_SESSION['ai_raw_response']); unset($_SESSION['ai_raw_response']); ?></pre>
                </details>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Pending Bookings List -->
    <div class="card card-smart mb-4">
        <div class="card-header bg-white px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h6 class="fw-bold mb-0"><i class="far fa-clock me-2 text-muted"></i>Pending Bookings</h6>
            <?php if (!empty($pending_bookings)): ?>
                <form method="post" class="m-0">
                    <?php echo csrf_field('admin_csrf'); ?>
                    <input type="hidden" name="action" value="get_schedule">
                    <button type="submit" class="btn btn-sm" style="background:#1a5a8c;color:#fff;font-weight:600;border:none;font-size:0.75rem;padding:0.35rem 0.9rem;border-radius:6px;">
                        <i class="fas fa-wand-magic-sparkles me-1"></i> Generate AI Schedule
                    </button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <?php if (empty($pending_bookings)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-check-circle fa-2x mb-2 d-block" style="color:#16a34a;"></i>
                    No pending bookings. All bookings have been processed.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle mb-0" style="font-size:0.82rem;">
                        <thead class="table-light">
                            <tr>
                                <th>#</th>
                                <th>Member</th>
                                <th>Date / Time</th>
                                <th>Sport</th>
                                <th>Facility</th>
                                <th>Current Coach</th>
                                <th>AI Suggestion</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_bookings as $b):
                                // Find AI suggestion for this booking
                                $suggestion = null;
                                foreach ($ai_suggestions as $s) {
                                    if (($s['booking_id'] ?? 0) == $b['booking_id']) {
                                        $suggestion = $s;
                                        break;
                                    }
                                }
                                // Map coach name
                                $sug_coach_name = '—';
                                if ($suggestion) {
                                    $sug_cid = (int)($suggestion['recommended_coach_id'] ?? 0);
                                    foreach ($coaches_list as $c) {
                                        if ($c['coach_id'] === $sug_cid) {
                                            $sug_coach_name = $c['first_name'] . ' ' . $c['last_name'];
                                            break;
                                        }
                                    }
                                }
                            ?>
                            <tr class="<?php echo $suggestion ? 'suggestion-row' : 'booking-row'; ?>">
                                <td class="fw-bold font-monospace">#<?php echo $b['booking_id']; ?></td>
                                <td><?php echo htmlspecialchars($b['member_first'] . ' ' . $b['member_last']); ?></td>
                                <td class="font-monospace"><?php echo $b['booking_date']; ?><br><small class="text-muted"><?php echo $b['start_time']; ?> - <?php echo $b['end_time']; ?></small></td>
                                <td><span class="badge bg-light text-dark" style="font-size:0.7rem;"><?php echo htmlspecialchars($b['sport_name']); ?></span></td>
                                <td><?php echo htmlspecialchars($b['facility_name']); ?></td>
                                <td><?php echo htmlspecialchars($b['current_coach']); ?></td>
                                <td>
                                    <?php if ($suggestion): ?>
                                        <div class="d-flex align-items-center gap-2 flex-wrap">
                                            <span class="ai-badge" style="background:#e2eaf3;color:#1a5a8c;">
                                                <i class="fas fa-user-check me-1"></i><?php echo htmlspecialchars($sug_coach_name); ?>
                                            </span>
                                            <form method="post" class="m-0">
                                                <?php echo csrf_field('admin_csrf'); ?>
                                                <input type="hidden" name="action" value="apply_suggestion">
                                                <input type="hidden" name="booking_id" value="<?php echo $b['booking_id']; ?>">
                                                <input type="hidden" name="suggested_coach" value="<?php echo (int)($suggestion['recommended_coach_id'] ?? 0); ?>">
                                                <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent" style="color:#16a34a;font-size:0.75rem;" title="Apply">
                                                    <i class="fas fa-check-circle"></i>
                                                </button>
                                            </form>
                                            <small class="text-muted" style="font-size:0.65rem;max-width:140px;white-space:normal;">
                                                <?php echo htmlspecialchars($suggestion['reason'] ?? ''); ?>
                                            </small>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted" style="font-size:0.75rem;">Awaiting AI</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Apply All Button -->
                <?php if (!empty($ai_suggestions)): ?>
                <div class="p-3 border-top bg-light d-flex justify-content-end">
                    <form method="post" class="m-0">
                        <?php echo csrf_field('admin_csrf'); ?>
                        <input type="hidden" name="action" value="apply_all">
                        <input type="hidden" name="suggestions_json" value="<?php echo htmlspecialchars(json_encode($ai_suggestions)); ?>">
                        <button type="submit" class="btn btn-sm" style="background:#16a34a;color:#fff;font-weight:600;border:none;font-size:0.75rem;padding:0.4rem 1rem;border-radius:6px;" onclick="return confirm('Apply all AI suggestions? This will approve and assign coaches to all shown bookings.');">
                            <i class="fas fa-check-double me-1"></i> Apply All Suggestions
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Coach Availability Overview -->
    <div class="card card-smart">
        <div class="card-header bg-white px-4 py-3 border-bottom">
            <h6 class="fw-bold mb-0"><i class="fas fa-whistle me-2 text-muted"></i>Available Coaches</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table align-middle mb-0" style="font-size:0.8rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Coach</th>
                            <th>Specialization</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coaches_list as $c): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo htmlspecialchars($c['first_name'] . ' ' . $c['last_name']); ?></td>
                            <td><span class="badge bg-light text-dark" style="font-size:0.7rem;"><?php echo htmlspecialchars($c['specialization']); ?></span></td>
                            <td><span class="badge bg-success-subtle text-success-emphasis" style="font-size:0.65rem;">Available</span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include_once("../includes/footer.php"); ?>
