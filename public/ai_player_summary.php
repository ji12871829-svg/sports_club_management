<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php'); exit;
}

require_once '../config/db_connect.php';
require_once '../includes/gemini_client.php';
require_once '../includes/url.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/rate_limiter.php';

$member_id = (int)($_SESSION['member_id'] ?? 0);

// ── AJAX handler ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token. Please reload the page.']);
        exit;
    }
    // Gemini calls cost money — cap generation per client.
    if (!rate_limit_check(client_rate_key('player_summary'), 10, 60)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many summary requests. Please wait a minute and try again.']);
        exit;
    }
    $target_id = (int)($_POST['member_id'] ?? $member_id);

    // Wrap in try/catch so errors always return valid JSON
    try {
        // Fetch stats
        $stats = [];

        // Basic member info
        $r = $conn->prepare("SELECT first_name, last_name, sport_id FROM members WHERE member_id=? LIMIT 1");
        $r->bind_param('i', $target_id);
        $r->execute();
        $memberRow = $r->get_result()->fetch_assoc();
        $r->close();

        if (!$memberRow) { echo json_encode(['error' => 'Member not found.']); exit; }

        $memberName = $memberRow['first_name'] . ' ' . $memberRow['last_name'];

        // Goals & assists from match_events
        $goals = 0; $assists = 0; $yellowCards = 0; $redCards = 0;
        $q = $conn->query("SELECT event_type, COUNT(*) as cnt FROM match_events WHERE member_id=$target_id GROUP BY event_type");
        if ($q) {
            while ($row = $q->fetch_assoc()) {
                $type = strtolower($row['event_type']);
                if (str_contains($type,'goal'))   $goals   += $row['cnt'];
                if (str_contains($type,'assist')) $assists  += $row['cnt'];
                if (str_contains($type,'yellow')) $yellowCards += $row['cnt'];
                if (str_contains($type,'red'))    $redCards    += $row['cnt'];
            }
        }

        // Matches played
        $matchesPlayed = 0;
        $q2 = $conn->query("SELECT COUNT(DISTINCT fixture_id) as cnt FROM lineups WHERE member_id=$target_id AND played=1");
        if ($q2) $matchesPlayed = (int)($q2->fetch_assoc()['cnt'] ?? 0);

        // Team
        $teamName = 'N/A';
        $q3 = $conn->query("SELECT t.name FROM team_memberships tp JOIN teams t ON t.team_id=tp.team_id WHERE tp.member_id=$target_id LIMIT 1");
        if ($q3 && $row3 = $q3->fetch_assoc()) $teamName = $row3['name'];

        $stats = compact('memberName','goals','assists','yellowCards','redCards','matchesPlayed','teamName');

        // Build prompt for Gemini
        $prompt = "You are a sports analyst writing a performance summary for a club member.

Player: {$memberName}
Team: {$teamName}
Matches Played: {$matchesPlayed}
Goals: {$goals}
Assists: {$assists}
Yellow Cards: {$yellowCards}
Red Cards: {$redCards}

Write a detailed but concise player performance summary (3–4 paragraphs) covering:
1. Overall performance overview
2. Key strengths based on the stats
3. Areas to improve
4. A motivational closing note

Use a professional but encouraging tone. Address the player by first name.";

        $result = asc_gemini_generate_text($prompt, ['temperature' => 0.65, 'maxOutputTokens' => 600, 'timeout' => 25]);
        $summary = $result['success'] ? $result['text'] : null;
        $error = $result['success'] ? null : ($result['error'] ?? 'Unable to generate AI summary.');

        echo json_encode(['success' => $result['success'], 'stats' => $stats, 'summary' => $summary, 'error' => $error]);
        exit;
    } catch (Throwable $ajax_e) {
        $ajax_error = defined('APP_DEBUG') && APP_DEBUG
            ? $ajax_e->getMessage()
            : 'An internal server error occurred. Please try again or check your API key configuration.';
        echo json_encode(['success' => false, 'error' => $ajax_error]);
        exit;
    }
}

// Load self member stats for initial display
$selfRow = null;
$r = $conn->prepare("SELECT first_name, last_name FROM members WHERE member_id=? LIMIT 1");
$r->bind_param('i', $member_id);
$r->execute();
$selfRow = $r->get_result()->fetch_assoc();
$r->close();
$conn->close();

include_once '../includes/header.php';
?>
<style>
.summary-hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%);
    border-radius: 16px; color: #fff; padding: 2rem; margin-bottom: 1.5rem;
}
.stat-pill {
    background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.15);
    border-radius: 12px; padding: .85rem 1.25rem; text-align: center;
    flex: 1; min-width: 110px;
}
.stat-pill .num { font-size: 2rem; font-weight: 800; }
.stat-pill .lbl { font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; color: rgba(255,255,255,.6); }
.summary-card { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:2rem; box-shadow:0 4px 20px rgba(0,0,0,.05); }
.summary-text { line-height: 1.75; font-size: .95rem; color: #334155; white-space: pre-wrap; }
.ai-badge { background: linear-gradient(135deg,#14497a,#1a5a8c); color:#fff; font-size:.72rem; font-weight:700; padding:.2rem .6rem; border-radius:20px; }
.btn-generate { background: linear-gradient(135deg, #059669, #10b981); color:#fff; border:none; border-radius:10px; padding:.7rem 1.5rem; font-weight:700; transition: all .15s; }
.btn-generate:hover { opacity:.9; transform:translateY(-1px); color:#fff; }
</style>

<div class="container py-4" style="max-width:860px;">
    <div class="summary-hero">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="font-size:2.5rem;">📊</div>
            <div>
                <h1 style="font-size:1.6rem;font-weight:800;margin:0;">AI Player Performance Summary</h1>
                <p style="color:rgba(255,255,255,.7);margin:.25rem 0 0;font-size:.9rem;">Your career stats analysed by Gemini AI</p>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2" id="statsRow">
            <div class="stat-pill"><div class="num" id="statMatches">–</div><div class="lbl">Matches</div></div>
            <div class="stat-pill"><div class="num" id="statGoals">–</div><div class="lbl">Goals</div></div>
            <div class="stat-pill"><div class="num" id="statAssists">–</div><div class="lbl">Assists</div></div>
            <div class="stat-pill"><div class="num" id="statYellow">–</div><div class="lbl">Yellow</div></div>
            <div class="stat-pill"><div class="num" id="statRed">–</div><div class="lbl">Red</div></div>
        </div>
    </div>

    <div class="summary-card">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <h5 class="mb-1 fw-bold">Your Performance Analysis</h5>
                <p class="text-muted small mb-0">Click Generate to get your personalised AI report</p>
            </div>
            <span class="ai-badge">⚡ Gemini AI</span>
        </div>

        <div id="summaryArea" class="mb-4">
            <div class="text-center py-5 text-muted" id="emptyPrompt">
                <div style="font-size:3rem;">🏆</div>
                <p class="mt-2">Click <strong>Generate Summary</strong> to load your AI-powered performance analysis.</p>
            </div>
        </div>

        <button class="btn-generate w-100" onclick="generateSummary()" id="genBtn">
            <i class="fas fa-wand-magic-sparkles me-2"></i>Generate My Performance Summary
        </button>
        <p class="text-muted text-center small mt-2 mb-0">Powered by Google Gemini — takes ~5 seconds</p>
    </div>
</div>

<script>
const CSRF_TOKEN = "<?php echo htmlspecialchars(csrf_ensure('member_csrf'), ENT_QUOTES, 'UTF-8'); ?>";
const SELF = '<?php echo e(app_url('public/ai_player_summary.php')); ?>';
const MEMBER_ID = <?php echo $member_id; ?>;

async function generateSummary() {
    const btn = document.getElementById('genBtn');
    const area = document.getElementById('summaryArea');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Analysing your stats…';
    area.innerHTML = `<div class="text-center py-4 text-muted">
        <div class="spinner-border text-primary mb-3" style="width:3rem;height:3rem;"></div>
        <p>Gemini is writing your personalised analysis…</p></div>`;

    try {
        const fd = new FormData();
        fd.append('action', 'generate');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('member_id', MEMBER_ID);
        const res = await fetch(SELF, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            const s = data.stats;
            document.getElementById('statMatches').textContent = s.matchesPlayed;
            document.getElementById('statGoals').textContent   = s.goals;
            document.getElementById('statAssists').textContent = s.assists;
            document.getElementById('statYellow').textContent  = s.yellowCards;
            document.getElementById('statRed').textContent     = s.redCards;

            if (data.summary) {
                area.innerHTML = `<div class="summary-text">${esc(data.summary)}</div>`;
            } else {
                area.innerHTML = `<div class="alert alert-info">Stats loaded but AI summary unavailable. ${esc(data.error || 'Check your Gemini API key.')}</div>
                    <div class="row g-3 text-center">
                        <div class="col"><strong>${s.matchesPlayed}</strong><br><small class="text-muted">Matches</small></div>
                        <div class="col"><strong>${s.goals}</strong><br><small class="text-muted">Goals</small></div>
                        <div class="col"><strong>${s.assists}</strong><br><small class="text-muted">Assists</small></div>
                    </div>`;
            }
        } else {
            area.innerHTML = `<div class="alert alert-danger">${esc(data.error || 'An error occurred.')}</div>`;
        }
    } catch(e) {
        area.innerHTML = `<div class="alert alert-danger">Network error. Please try again.</div>`;
    }

    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-wand-magic-sparkles me-2"></i>Regenerate Summary';
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
}
</script>

<?php include_once '../includes/footer.php'; ?>
