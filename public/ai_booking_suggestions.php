<?php
/**
 * public/ai_booking_suggestions.php
 * AI-powered booking recommendations based on member's history
 */
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
asc_session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/gemini_client.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

$member_id = (int) $_SESSION["member_id"];
$message = '';
$ai_error = '';
$suggestions = [];
$member_name = htmlspecialchars($_SESSION["first_name"]);

// ── Saved Favorites (session) ───────────────────────────────────
if (!isset($_SESSION['saved_suggestions'])) {
    $_SESSION['saved_suggestions'] = [];
}

// Handle save/remove favorite
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_suggestion' && isset($_POST['data'])) {
        $data = json_decode($_POST['data'], true);
        if (is_array($data)) {
            $key = md5(json_encode($data));
            $_SESSION['saved_suggestions'][$key] = $data;
            $message = '<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-bookmark me-2"></i>Suggestion saved to favorites!</div>';
        }
    }
    if ($_POST['action'] === 'remove_saved' && isset($_POST['key'])) {
        $key = $_POST['key'];
        unset($_SESSION['saved_suggestions'][$key]);
        $message = '<div class="alert alert-info border-0 shadow-sm"><i class="fas fa-trash-alt me-2"></i>Favorite removed.</div>';
    }
}

// ── Fetch member's booking history (last 20) ──────────────────────
$bookings = [];
$stmt = $conn->prepare("
    SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.status,
           COALESCE(s.name, 'Sport') AS sport_name,
           COALESCE(f.name, 'Facility') AS facility_name,
           COALESCE(f.location, '') AS facility_location,
           COALESCE(CONCAT(c.first_name, ' ', c.last_name), 'None') AS coach_name
    FROM bookings b
    LEFT JOIN sports s ON b.sport_id = s.sport_id
    LEFT JOIN facilities f ON b.facility_id = f.facility_id
    LEFT JOIN coaches c ON b.coach_id = c.coach_id
    WHERE b.member_id = ?
    ORDER BY b.booking_date DESC, b.start_time DESC
    LIMIT 20
");
if ($stmt) {
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $bookings[] = $row;
    }
    $stmt->close();
}

// Count by sport
$sport_counts = [];
foreach ($bookings as $b) {
    $s = $b['sport_name'];
    $sport_counts[$s] = ($sport_counts[$s] ?? 0) + 1;
}
arsort($sport_counts);
$fav_sport = array_key_first($sport_counts) ?? 'None';

// Most common time slots
$time_slots = [];
foreach ($bookings as $b) {
    $h = (int)substr($b['start_time'], 0, 2);
    $slot = $h < 12 ? 'Morning' : ($h < 17 ? 'Afternoon' : 'Evening');
    $time_slots[$slot] = ($time_slots[$slot] ?? 0) + 1;
}
arsort($time_slots);
$fav_time = array_key_first($time_slots) ?? 'Anytime';

// ── Fetch available sports, facilities, coaches for context ──────
$sports_list = [];
$r = $conn->query("SELECT sport_id, name FROM sports ORDER BY name");
if ($r) while ($row = $r->fetch_assoc()) $sports_list[] = $row;

$facilities_list = [];
$r = $conn->query("SELECT facility_id, name, location, type FROM facilities ORDER BY name");
if ($r) while ($row = $r->fetch_assoc()) $facilities_list[] = $row;

$coaches_list = [];
$r = $conn->query("SELECT coach_id, first_name, last_name, specialization FROM coaches ORDER BY first_name");
if ($r) while ($row = $r->fetch_assoc()) $coaches_list[] = $row;

// ── Handle AI Suggestion Request ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_suggestions') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'ai_booking_csrf')) {
        $ai_error = 'Security check failed. Please refresh and try again.';
    } elseif (!rate_limit_check(client_rate_key('ai_booking'), 10, 60)) {
        $ai_error = 'Too many requests. Please wait a minute and try again.';
    } else {
        $key_status = asc_gemini_api_key_status();
        if (empty($key_status['ready'])) {
            $ai_error = $key_status['message'];
        } else {
            // Build booking history summary for the AI
            $history_lines = [];
            foreach ($bookings as $b) {
                $history_lines[] = "- {$b['booking_date']} | {$b['start_time']}-{$b['end_time']} | {$b['sport_name']} @ {$b['facility_name']} | Coach: {$b['coach_name']} | Status: {$b['status']}";
            }
            $history_text = empty($history_lines) ? 'No past bookings yet (new member).' : implode("\n", $history_lines);

            // Build available options
            $sport_options = implode(', ', array_map(fn($s) => $s['name'], $sports_list));
            $facility_options = implode(', ', array_map(fn($f) => "{$f['name']} ({$f['type']})", $facilities_list));
            $coach_options = implode(', ', array_map(fn($c) => "{$c['first_name']} {$c['last_name']} - {$c['specialization']}", $coaches_list));

            $prompt = "You are an AI sports club booking assistant for Apex Sports Club. A member wants booking recommendations based on their history and preferences.\n\n"
                . "MEMBER PROFILE:\n"
                . "Name: {$member_name}\n"
                . "Favorite sport: {$fav_sport}\n"
                . "Preferred time: {$fav_time}\n"
                . "Total past bookings: " . count($bookings) . "\n\n"
                . "BOOKING HISTORY:\n{$history_text}\n\n"
                . "AVAILABLE OPTIONS:\n"
                . "Sports: {$sport_options}\n"
                . "Facilities: {$facility_options}\n"
                . "Coaches: {$coach_options}\n\n"
                . "TODAY'S DATE: " . date('Y-m-d') . "\n\n"
                . "Suggest 3 specific booking recommendations for this member. Each recommendation should include a sport, facility, coach (or 'None'), preferred time of day (Morning/Afternoon/Evening), and a 1-sentence reason why this suits them.\n\n"
                . "Respond with ONLY a JSON array:\n"
                . "[\n"
                . "  {\n"
                . "    \"sport\": \"Sport Name\",\n"
                . "    \"facility\": \"Facility Name\",\n"
                . "    \"coach\": \"Coach Name or None\",\n"
                . "    \"time_of_day\": \"Morning/Afternoon/Evening\",\n"
                . "    \"reason\": \"Why this suits the member\"\n"
                . "  },\n"
                . "  ...\n"
                . "]";

            $result = asc_gemini_generate_text($prompt, [
                'temperature' => 0.5,
                'maxOutputTokens' => 1000,
                'timeout' => 25,
            ]);

            if (!empty($result['success'])) {
                $text = trim($result['text']);

                // Extract JSON array
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
                    $suggestions = $parsed;
                    $message = '<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-magic me-2"></i>AI has generated personalized recommendations based on your history!</div>';
                } else {
                    $ai_error = 'Could not understand AI response. Please try again.';
                    $_SESSION['ai_raw_response'] = substr($text, 0, 2000);
                }
            } else {
                $ai_error = $result['error'] ?? 'AI did not respond.';
            }
        }
    }
}

$conn->close();

include_once("../includes/header.php");
?>

<style>
    body {
        background: #f8fafc !important;
        color: #334155;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    .suggest-hero {
        background: linear-gradient(135deg, #0f172a, #1e293b);
        border-radius: 16px;
        padding: 2.5rem;
        color: #fff;
    }
    .suggest-hero h1 {
        font-size: 1.75rem;
        font-weight: 800;
        letter-spacing: -0.5px;
    }
    .accent-glow {
        width: 48px;
        height: 4px;
        background: linear-gradient(90deg, #1a5a8c, #7da8cf);
        border-radius: 2px;
        margin-bottom: 1rem;
    }
    .stat-chip {
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 8px;
        padding: 0.5rem 1rem;
        font-size: 0.8rem;
    }
    .card-suggest {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        transition: all 0.2s ease;
    }
    .card-suggest:hover {
        border-color: #cbd5e1;
        box-shadow: 0 8px 25px -6px rgba(0,0,0,0.08);
        transform: translateY(-2px);
    }
    .sport-badge {
        display: inline-block;
        padding: 0.2rem 0.7rem;
        border-radius: 6px;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .reason-text {
        color: #64748b;
        font-size: 0.88rem;
        line-height: 1.6;
    }
    .btn-book {
        background: #1a5a8c;
        color: #fff;
        font-weight: 600;
        font-size: 0.8rem;
        border: none;
        padding: 0.4rem 0.9rem;
        border-radius: 8px;
        transition: all 0.15s ease;
    }
    .btn-book:hover {
        background: #14497a;
        color: #fff;
        transform: scale(1.02);
    }
    .btn-generate {
        background: #1a5a8c;
        color: #fff;
        font-weight: 700;
        border: none;
        padding: 0.65rem 1.5rem;
        border-radius: 10px;
        font-size: 0.9rem;
        transition: all 0.15s ease;
    }
    .btn-generate:hover {
        background: #14497a;
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 6px 20px rgba(26,90,140,0.3);
    }
    .profile-stat {
        font-size: 0.75rem;
        color: #64748b;
    }
    .profile-stat strong {
        color: #0f172a;
    }
</style>

<div class="container py-4">
    
    <!-- Hero Header -->
    <div class="suggest-hero mb-4">
        <div class="row align-items-center">
            <div class="col-lg-8">
                <div class="accent-glow"></div>
                <h1>AI Booking Suggestions</h1>
                <p class="mb-0 mt-2" style="color:#94a3b8;font-size:0.95rem;">
                    Personalized recommendations based on your booking history and club preferences.
                    Let AI find the perfect sport, facility, and time for you.
                </p>
            </div>
            <div class="col-lg-4 text-lg-end mt-3 mt-lg-0">
                <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                    <span class="stat-chip">⭐ <?php echo htmlspecialchars($fav_sport); ?></span>
                    <span class="stat-chip">🕐 <?php echo $fav_time; ?></span>
                    <span class="stat-chip">📊 <?php echo count($bookings); ?> bookings</span>
                </div>
            </div>
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

    <!-- Profile Summary -->
    <div class="card card-suggest p-4 mb-4">
        <div class="row g-3 align-items-center">
            <div class="col-md-3">
                <h6 class="fw-bold mb-1" style="font-size:0.8rem;color:#0f172a;">Your Booking Profile</h6>
                <p class="profile-stat mb-0">Based on your last <?php echo count($bookings); ?> bookings</p>
            </div>
            <div class="col-md-3">
                <small class="profile-stat d-block">Favorite Sport</small>
                <strong style="font-size:0.95rem;"><?php echo htmlspecialchars($fav_sport); ?></strong>
            </div>
            <div class="col-md-3">
                <small class="profile-stat d-block">Preferred Time</small>
                <strong style="font-size:0.95rem;"><?php echo $fav_time; ?></strong>
            </div>
            <div class="col-md-3 text-md-end">
                <form method="post" class="m-0">
                    <?php echo csrf_field('ai_booking_csrf'); ?>
                    <input type="hidden" name="action" value="get_suggestions">
                    <button type="submit" class="btn-generate">
                        <i class="fas fa-magic me-2"></i> Generate Suggestions
                    </button>
                </form>
            </div>
        </div>
    </div>

    <?php
    // Build name-to-ID lookup maps for booking links
    $sport_map = [];
    foreach ($sports_list as $s) $sport_map[strtolower(trim($s['name']))] = $s['sport_id'];
    $facility_map = [];
    foreach ($facilities_list as $f) $facility_map[strtolower(trim($f['name']))] = $f['facility_id'];
    $coach_map = [];
    foreach ($coaches_list as $c) $coach_map[strtolower(trim($c['first_name'] . ' ' . $c['last_name']))] = $c['coach_id'];
    ?>

    <!-- Saved Favorites -->
    <?php if (!empty($_SESSION['saved_suggestions'])): ?>
    <div class="mb-4">
        <h6 class="fw-bold mb-3" style="color:#0f172a;">
            <i class="fas fa-bookmark me-2" style="color:#f59e0b;"></i>Saved Favorites
        </h6>
        <div class="row g-3">
            <?php foreach ($_SESSION['saved_suggestions'] as $key => $saved): 
                $sid = $sport_map[strtolower(trim($saved['sport'] ?? ''))] ?? 0;
                $fid = $facility_map[strtolower(trim($saved['facility'] ?? ''))] ?? 0;
                $cid = $coach_map[strtolower(trim($saved['coach'] ?? ''))] ?? 0;
            ?>
            <div class="col-lg-4 col-md-6">
                <div class="card card-suggest h-100" style="border-color:#fde68a;">
                    <div class="card-body p-3 d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <span class="sport-badge" style="background:#fef3c7;color:#92400e;">⭐ Saved</span>
                            <form method="post" class="m-0">
                                <input type="hidden" name="action" value="remove_saved">
                                <input type="hidden" name="key" value="<?php echo htmlspecialchars($key); ?>">
                                <button type="submit" class="btn btn-sm p-0 border-0 bg-transparent" style="color:#94a3b8;" title="Remove">
                                    <i class="fas fa-times"></i>
                                </button>
                            </form>
                        </div>
                        <div class="fw-semibold small"><?php echo htmlspecialchars($saved['sport'] ?? ''); ?> @ <?php echo htmlspecialchars($saved['facility'] ?? ''); ?></div>
                        <div class="small text-muted mt-1 flex-grow-1"><?php echo htmlspecialchars($saved['reason'] ?? ''); ?></div>
                        <a href="booking.php?sport_id=<?php echo $sid; ?>&amp;facility_id=<?php echo $fid; ?>&amp;coach_id=<?php echo $cid; ?>" 
                           class="btn-book text-decoration-none text-center d-block mt-2" style="font-size:0.7rem;padding:0.3rem 0.7rem;">
                            <i class="fas fa-calendar-plus me-1"></i> Book
                        </a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Suggestions Grid -->
    <?php if (!empty($suggestions)): ?>
        <h5 class="fw-bold mb-3" style="color:#0f172a;">
            <i class="fas fa-lightbulb me-2" style="color:#f59e0b;"></i>Recommended for You
        </h5>
        <div class="row g-4 mb-4">
            <?php foreach ($suggestions as $i => $s): 
                $colors = ['#1a5a8c', '#1d5c8f', '#16a34a'];
                $color = $colors[$i % count($colors)];
                // Map AI text responses to numeric IDs
                $sid = $sport_map[strtolower(trim($s['sport'] ?? ''))] ?? 0;
                $fid = $facility_map[strtolower(trim($s['facility'] ?? ''))] ?? 0;
                $cid = $coach_map[strtolower(trim($s['coach'] ?? ''))] ?? 0;
            ?>
            <div class="col-lg-4 col-md-6">
                <div class="card card-suggest h-100">
                    <div class="card-body p-4 d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <span class="sport-badge" style="background:<?php echo $color; ?>15;color:<?php echo $color; ?>;">
                                <?php echo htmlspecialchars($s['sport'] ?? 'Sport'); ?>
                            </span>
                            <span class="text-muted" style="font-size:0.7rem;font-weight:600;">Recommendation #<?php echo $i + 1; ?></span>
                        </div>
                        
                        <div class="mb-3">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span style="font-size:0.85rem;">🏟️</span>
                                <span class="fw-semibold" style="font-size:0.9rem;"><?php echo htmlspecialchars($s['facility'] ?? ''); ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span style="font-size:0.85rem;">👟</span>
                                <span style="font-size:0.85rem;">Coach: <?php echo htmlspecialchars($s['coach'] ?? 'None'); ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <span style="font-size:0.85rem;">🕐</span>
                                <span style="font-size:0.85rem;"><?php echo htmlspecialchars($s['time_of_day'] ?? ''); ?></span>
                            </div>
                        </div>

                        <p class="reason-text flex-grow-1"><?php echo htmlspecialchars($s['reason'] ?? ''); ?></p>

                        <div class="d-flex gap-2 mt-auto">
                            <form method="post" class="m-0 flex-grow-1">
                                <input type="hidden" name="action" value="save_suggestion">
                                <input type="hidden" name="data" value="<?php echo htmlspecialchars(json_encode($s)); ?>">
                                <button type="submit" class="btn-book text-decoration-none text-center d-block w-100" style="background:transparent;color:#1a5a8c;border:1px solid #1a5a8c;">
                                    <i class="far fa-bookmark me-1"></i> Save
                                </button>
                            </form>
                            <a href="booking.php?sport_id=<?php echo $sid; ?>&amp;facility_id=<?php echo $fid; ?>&amp;coach_id=<?php echo $cid; ?>" 
                               class="btn-book text-decoration-none text-center d-block">
                                <i class="fas fa-calendar-plus me-1"></i> Book
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php elseif (empty($ai_error)): ?>
        <!-- Empty State -->
        <div class="text-center py-5">
            <div style="width:80px;height:80px;border-radius:50%;background:#f0fdf4;display:flex;align-items:center;justify-content:center;margin:0 auto 1.5rem;font-size:2rem;">
                🤖
            </div>
            <h5 class="fw-bold" style="color:#0f172a;">Ready for Recommendations</h5>
            <p class="text-muted small" style="max-width:400px;margin:0 auto;">
                Click "Generate Suggestions" to get AI-powered booking recommendations tailored to your preferences and history.
            </p>
        </div>
    <?php endif; ?>

    <!-- Booking History Summary -->
    <?php if (!empty($bookings)): ?>
    <div class="card card-suggest mt-4">
        <div class="card-header bg-white px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="fw-bold mb-0" style="font-size:0.9rem;"><i class="far fa-clock me-2" style="color:#64748b;"></i>Your Recent Bookings</h6>
            <a href="view_bookings.php" class="text-decoration-none small fw-semibold" style="color:#1a5a8c;">View All →</a>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0" style="font-size:0.8rem;">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Sport</th>
                        <th>Facility</th>
                        <th>Coach</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($bookings, 0, 8) as $b): 
                        $sc = strtolower($b['status']);
                        $sc_color = in_array($sc, ['approved','confirmed']) ? '#16a34a' : ($sc === 'pending' ? '#d97706' : '#dc2626');
                    ?>
                    <tr>
                        <td class="font-monospace"><?php echo htmlspecialchars($b['booking_date']); ?></td>
                        <td class="font-monospace"><?php echo htmlspecialchars($b['start_time']); ?>-<?php echo htmlspecialchars($b['end_time']); ?></td>
                        <td class="fw-semibold"><?php echo htmlspecialchars($b['sport_name']); ?></td>
                        <td><?php echo htmlspecialchars($b['facility_name']); ?></td>
                        <td class="text-muted"><?php echo htmlspecialchars($b['coach_name']); ?></td>
                        <td><span class="badge" style="background:<?php echo $sc_color; ?>15;color:<?php echo $sc_color; ?>;font-size:0.65rem;font-weight:700;"><?php echo htmlspecialchars($b['status']); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include_once("../includes/footer.php"); ?>
