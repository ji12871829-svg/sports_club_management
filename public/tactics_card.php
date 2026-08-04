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

// Fetch all members for player selection
$players = [];
$q = $conn->query("SELECT member_id, first_name, last_name FROM members ORDER BY first_name LIMIT 200");
if ($q) while ($r = $q->fetch_assoc()) $players[] = $r;
$conn->close();

// ── AJAX: Generate Tactics ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tc_action'])) {
    header('Content-Type: application/json');
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid or missing CSRF token. Please reload the page.']);
        exit;
    }
    // Gemini calls cost money — cap generation per client.
    if (!rate_limit_check(client_rate_key('tactics'), 10, 60)) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many tactics cards. Please wait a minute and try again.']);
        exit;
    }
    try {
    $formation  = trim((string)($_POST['formation'] ?? '4-4-2'));
    $style      = trim((string)($_POST['style'] ?? 'Balanced'));
    $opposition = trim((string)($_POST['opposition'] ?? ''));
    $notes      = trim((string)($_POST['notes'] ?? ''));

    $prompt = "You are an elite football tactics coach. Generate a detailed tactics brief for:

Formation: $formation
Playing Style: $style
" . ($opposition ? "Opposition: $opposition\n" : "") . ($notes ? "Coach Notes: $notes\n" : "") . "

Provide a comprehensive tactics card with:
1. **Formation Overview** – Why this formation suits the style
2. **Pressing & Defensive Shape** – How to press, defensive line height
3. **Build-up Play** – How to progress from the back
4. **Attacking Patterns** – 3 key attacking movements/combinations
5. **Set Pieces** – Corner routine (attacking) and free-kick setup
6. **Key Instructions** – 5 specific player role instructions (by position)
7. **In-Game Adjustments** – What to do if losing/drawing after 60 min

Be specific and tactical. Use football terminology. Format with clear sections.";

    $result = asc_gemini_generate_text($prompt, ['temperature' => 0.6, 'maxOutputTokens' => 800, 'timeout' => 30]);

    if (!empty($result['success'])) {
        echo json_encode(['success' => true, 'tactics' => $result['text'], 'formation' => $formation]);
    } else {
        echo json_encode(['error' => $result['error'] ?? 'Gemini unavailable.']);
    }
    exit;
    } catch (Throwable $e) {
        $ajax_err = defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'An internal error occurred while generating tactics.';
        echo json_encode(['error' => $ajax_err]);
        exit;
    }
}

include_once '../includes/header.php';

$formations = [
    '4-4-2'   => ['label' => '4-4-2 Classic',   'desc' => 'Balanced width and solidity'],
    '4-3-3'   => ['label' => '4-3-3 Attack',     'desc' => 'High press, wide forwards'],
    '3-5-2'   => ['label' => '3-5-2 Wing-backs', 'desc' => 'Dominant midfield'],
    '4-2-3-1' => ['label' => '4-2-3-1',          'desc' => 'Double pivot, creative #10'],
    '5-3-2'   => ['label' => '5-3-2 Defensive',  'desc' => 'Low block, counter-attack'],
    '4-1-4-1' => ['label' => '4-1-4-1',          'desc' => 'Deep anchor, compact shape'],
    '3-4-3'   => ['label' => '3-4-3 Total Ftbl', 'desc' => 'Possession, high line'],
];

$formationPositions = [
    '4-4-2'   => ['GK','CB','CB','CB','CB','LM','CM','CM','RM','ST','ST'],
    '4-3-3'   => ['GK','CB','CB','CB','CB','CM','CM','CM','LW','CF','RW'],
    '3-5-2'   => ['GK','CB','CB','CB','LWB','CM','CM','CM','RWB','ST','ST'],
    '4-2-3-1' => ['GK','CB','CB','CB','CB','CDM','CDM','LAM','CAM','RAM','ST'],
    '5-3-2'   => ['GK','CB','CB','CB','LWB','RWB','CM','CM','CM','ST','ST'],
    '4-1-4-1' => ['GK','CB','CB','CB','CB','CDM','LM','CM','CM','RM','ST'],
    '3-4-3'   => ['GK','CB','CB','CB','LM','CM','CM','RM','LW','CF','RW'],
];
?>
<style>
.tactics-hero { background: linear-gradient(135deg,#064e3b 0%,#065f46 100%); border-radius:16px; color:#fff; padding:2rem; margin-bottom:1.5rem; }
.formation-btn { border:2px solid #e2e8f0; border-radius:10px; padding:.75rem 1rem; text-align:center; cursor:pointer; transition:all .15s; background:#fff; }
.formation-btn:hover,.formation-btn.active { border-color:#059669; background:#f0fdf4; }
.formation-btn.active { box-shadow:0 0 0 3px rgba(5,150,105,.2); }
.formation-label { font-weight:700; font-size:.9rem; color:#1e293b; }
.formation-desc  { font-size:.75rem; color:#64748b; }
.pitch-svg { background:linear-gradient(to bottom, #16a34a 0%,#15803d 50%,#16a34a 100%); border-radius:8px; border:3px solid rgba(255,255,255,.3); }
.tactics-output { background:#fff; border:1px solid #e2e8f0; border-radius:16px; padding:1.75rem; box-shadow:0 4px 20px rgba(0,0,0,.06); }
.tactics-text { font-size:.92rem; line-height:1.7; color:#334155; white-space:pre-wrap; }
.style-chip { border:2px solid #e2e8f0; border-radius:20px; padding:.4rem 1rem; font-size:.85rem; font-weight:600; cursor:pointer; background:#fff; transition:all .15s; }
.style-chip.active,.style-chip:hover { border-color:#059669; background:#f0fdf4; color:#065f46; }
.btn-tactics { background:linear-gradient(135deg,#059669,#10b981); color:#fff; border:none; border-radius:10px; padding:.75rem 2rem; font-weight:700; font-size:1rem; transition:all .15s; }
.btn-tactics:hover { opacity:.9; transform:translateY(-1px); color:#fff; }
</style>

<div class="container py-4" style="max-width:960px;">

<div class="tactics-hero">
    <div class="d-flex align-items-center gap-3">
        <div style="font-size:2.5rem;">⚽</div>
        <div>
            <h1 style="font-size:1.6rem;font-weight:800;margin:0;">AI Tactics Card Generator</h1>
            <p style="color:rgba(255,255,255,.75);margin:.25rem 0 0;font-size:.9rem;">Choose a formation — Gemini writes your complete tactics brief</p>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left: Builder -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm p-3">
            <h6 class="fw-bold mb-3">1. Choose Formation</h6>
            <div class="d-flex flex-column gap-2" id="formationBtns">
                <?php foreach ($formations as $key => $f): ?>
                <div class="formation-btn <?php echo $key === '4-4-2' ? 'active' : ''; ?>"
                     onclick="selectFormation('<?php echo e($key); ?>', this)">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="formation-label"><?php echo e($f['label']); ?></div>
                            <div class="formation-desc"><?php echo e($f['desc']); ?></div>
                        </div>
                        <div style="font-size:1.4rem;color:#059669;" class="check-icon" style="display:none;">✓</div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <hr class="my-3">
            <h6 class="fw-bold mb-2">2. Playing Style</h6>
            <div class="d-flex flex-wrap gap-2 mb-3" id="styleChips">
                <?php foreach (['Balanced','High Press','Tiki-Taka','Counter-Attack','Long Ball','Possession'] as $s): ?>
                <span class="style-chip <?php echo $s==='Balanced'?'active':''; ?>"
                      onclick="selectStyle('<?php echo e($s); ?>', this)"><?php echo e($s); ?></span>
                <?php endforeach; ?>
            </div>

            <h6 class="fw-bold mb-2">3. Opposition (optional)</h6>
            <input type="text" id="oppInput" class="form-control mb-3" placeholder="e.g. Nairobi United FC">

            <h6 class="fw-bold mb-2">4. Coach Notes (optional)</h6>
            <textarea id="notesInput" class="form-control mb-3" rows="3" placeholder="e.g. Their #10 is dangerous. Press high…"></textarea>

            <!-- Pitch Preview -->
            <h6 class="fw-bold mb-2">Formation Preview</h6>
            <div class="text-center">
                <svg id="pitchSvg" class="pitch-svg" width="100%" viewBox="0 0 300 400" xmlns="http://www.w3.org/2000/svg">
                    <!-- Pitch markings -->
                    <rect x="0" y="0" width="300" height="400" fill="#16a34a"/>
                    <rect x="20" y="20" width="260" height="360" fill="none" stroke="rgba(255,255,255,.5)" stroke-width="2"/>
                    <line x1="20" y1="200" x2="280" y2="200" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
                    <circle cx="150" cy="200" r="40" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
                    <circle cx="150" cy="200" r="3" fill="rgba(255,255,255,.6)"/>
                    <!-- Goal areas -->
                    <rect x="95" y="20" width="110" height="40" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
                    <rect x="95" y="340" width="110" height="40" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
                    <rect x="115" y="20" width="70" height="18" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
                    <rect x="115" y="362" width="70" height="18" fill="none" stroke="rgba(255,255,255,.4)" stroke-width="1.5"/>
                    <g id="playerDots"></g>
                </svg>
            </div>

            <button class="btn-tactics w-100 mt-3" onclick="generateTactics()" id="tacBtn">
                <i class="fas fa-wand-magic-sparkles me-2"></i>Generate Tactics Card
            </button>
        </div>
    </div>

    <!-- Right: Output -->
    <div class="col-lg-7">
        <div class="tactics-output">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="fw-bold mb-0">Tactics Brief</h5>
                <span class="badge" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);font-size:.72rem;" id="formBadge">4-4-2</span>
            </div>
            <div id="tacticsContent">
                <div class="text-center py-5 text-muted">
                    <div style="font-size:3rem;">🎯</div>
                    <p class="mt-2">Select a formation and click <strong>Generate Tactics Card</strong></p>
                    <p class="small">Gemini will write a complete professional tactics brief</p>
                </div>
            </div>
            <div id="exportBtn" style="display:none;" class="mt-3">
                <button class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                    <i class="fas fa-print me-1"></i>Print Tactics Card
                </button>
            </div>
        </div>
    </div>
</div>
</div>

<script>
const CSRF_TOKEN = "<?php echo htmlspecialchars(csrf_ensure('member_csrf'), ENT_QUOTES, 'UTF-8'); ?>";
const SELF = '<?php echo e(app_url('public/tactics_card.php')); ?>';
let selectedFormation = '4-4-2';
let selectedStyle = 'Balanced';

const formationPositions = <?php echo json_encode($formationPositions); ?>;

function selectFormation(key, el) {
    selectedFormation = key;
    document.querySelectorAll('.formation-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('formBadge').textContent = key;
    drawPitch(key);
}

function selectStyle(style, el) {
    selectedStyle = style;
    document.querySelectorAll('.style-chip').forEach(c => c.classList.remove('active'));
    el.classList.add('active');
}

function drawPitch(formation) {
    const pos = formationPositions[formation] || [];
    const svg = document.getElementById('playerDots');
    svg.innerHTML = '';

    // Simple position layout
    const rows = formation.split('-').map(Number);
    const totalPlayers = pos.length;
    const yStep = 360 / (rows.length + 1);
    let posIdx = 0;

    // GK
    addDot(svg, 150, 380, pos[posIdx++] || 'GK', '#fff');

    for (let rowIdx = rows.length - 1; rowIdx >= 0; rowIdx--) {
        const count = rows[rowIdx];
        const y = 380 - (rows.length - rowIdx) * yStep;
        const xStep = 240 / (count + 1);
        for (let i = 0; i < count; i++) {
            const x = 30 + xStep * (i + 1);
            addDot(svg, x, y, pos[posIdx++] || '', '#facc15');
        }
    }
}

function addDot(svg, x, y, label, color) {
    const g = document.createElementNS('http://www.w3.org/2000/svg','g');
    const c = document.createElementNS('http://www.w3.org/2000/svg','circle');
    c.setAttribute('cx',x); c.setAttribute('cy',y); c.setAttribute('r','12');
    c.setAttribute('fill',color); c.setAttribute('stroke','#fff'); c.setAttribute('stroke-width','2');
    const t = document.createElementNS('http://www.w3.org/2000/svg','text');
    t.setAttribute('x',x); t.setAttribute('y',y+4); t.setAttribute('text-anchor','middle');
    t.setAttribute('font-size','7'); t.setAttribute('fill','#1e293b'); t.setAttribute('font-weight','bold');
    t.textContent = label;
    g.appendChild(c); g.appendChild(t); svg.appendChild(g);
}

async function generateTactics() {
    const btn = document.getElementById('tacBtn');
    const out = document.getElementById('tacticsContent');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Gemini is thinking…';
    out.innerHTML = `<div class="text-center py-5">
        <div class="spinner-border text-success mb-3" style="width:3rem;height:3rem;"></div>
        <p class="text-muted">Generating your ${selectedFormation} tactics brief…</p></div>`;

    try {
        const fd = new FormData();
        fd.append('tc_action','generate');
        fd.append('csrf_token', CSRF_TOKEN);
        fd.append('formation', selectedFormation);
        fd.append('style', selectedStyle);
        fd.append('opposition', document.getElementById('oppInput').value);
        fd.append('notes', document.getElementById('notesInput').value);
        const res = await fetch(SELF, { method:'POST', body:fd });
        const data = await res.json();

        if (data.success) {
            const html = data.tactics.replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>').replace(/\n/g,'<br>');
            out.innerHTML = `<div class="tactics-text">${html}</div>`;
            document.getElementById('exportBtn').style.display = '';
        } else {
            out.innerHTML = `<div class="alert alert-danger">${esc(data.error)}</div>`;
        }
    } catch(e) {
        out.innerHTML = `<div class="alert alert-danger">Network error. Please try again.</div>`;
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-wand-magic-sparkles me-2"></i>Regenerate Tactics Card';
}

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// Init pitch
drawPitch('4-4-2');
</script>

<?php include_once '../includes/footer.php'; ?>
