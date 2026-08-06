<?php
require_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/gemini_client.php';
require_once '../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

// Check if event_checklists table exists
$list_table_exists = db_table_exists($conn, 'event_checklists');

// Fetch fixtures for selector
$fixtures = [];
$q = $conn->query("SELECT f.fixture_id, f.match_date, ht.name AS home, at.name AS away
    FROM fixtures f
    JOIN teams ht ON ht.team_id=f.home_team_id
    JOIN teams at ON at.team_id=f.away_team_id
    WHERE f.match_date >= CURDATE() - INTERVAL 7 DAY
    ORDER BY f.match_date ASC LIMIT 50");
if ($q) while ($r = $q->fetch_assoc()) $fixtures[] = $r;

// ── AJAX handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cl_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['cl_action'];

    if (!$list_table_exists) {
        echo json_encode(['error'=>'The event_checklists table does not exist. Please run php scripts/migrate.php from the project root to set up the required tables.']);
        exit;
    }

    if ($action === 'load') {
        $fid = (int)($_POST['fixture_id'] ?? 0);
        $items = [];
        $q2 = $conn->query("SELECT * FROM event_checklists WHERE fixture_id=$fid ORDER BY sort_order, id");
        if ($q2) while ($r = $q2->fetch_assoc()) $items[] = $r;
        echo json_encode(['success'=>true,'items'=>$items]); exit;
    }

    if ($action === 'add_item') {
        $fid  = (int)($_POST['fixture_id'] ?? 0);
        $item = trim((string)($_POST['item'] ?? ''));
        $resp = trim((string)($_POST['responsible'] ?? ''));
        if (!$item) { echo json_encode(['error'=>'Item text required.']); exit; }
        $stmt = $conn->prepare("INSERT INTO event_checklists (fixture_id,item,responsible,sort_order) VALUES (?,?,?,(SELECT COALESCE(MAX(sort_order),0)+1 FROM event_checklists ec2 WHERE fixture_id=?))");
        $stmt->bind_param('issi', $fid, $item, $resp, $fid);
        $stmt->execute();
        echo json_encode(['success'=>true,'id'=>$stmt->insert_id]); exit;
    }

    if ($action === 'toggle') {
        $id     = (int)($_POST['id'] ?? 0);
        $done   = (int)($_POST['done'] ?? 0);
        $doneAt = $done ? date('Y-m-d H:i:s') : null;
        $stmt = $conn->prepare("UPDATE event_checklists SET is_done=?, done_at=? WHERE id=?");
        $stmt->bind_param('isi', $done, $doneAt, $id);
        $stmt->execute();
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM event_checklists WHERE id=$id");
        echo json_encode(['success'=>true]); exit;
    }

    if ($action === 'ai_generate') {
        $fid     = (int)($_POST['fixture_id'] ?? 0);
        $matchInfo = trim((string)($_POST['match_info'] ?? 'a football match'));

        // Clear old AI items for this fixture
        $conn->query("DELETE FROM event_checklists WHERE fixture_id=$fid AND is_done=0");

        $prompt = "Generate a comprehensive event day checklist for: $matchInfo

Return as JSON array of checklist items:
[{\"item\":\"...\",\"responsible\":\"...\"}]

Include items across these categories:
- Pitch & Venue (setup, markings, goalposts, nets)
- Equipment (balls, bibs, cones, first-aid kit, stretcher)
- Officials (referee confirmation, linesmen, scoresheets)
- Players (kit, warm-up, team sheet submission)
- Hospitality (water, refreshments, changing rooms)
- Safety & Medical (ambulance contact, first-aider on site)
- Administration (match tickets, photography clearance, announcements)
- Post-Match (score submission, equipment collection, venue cleanup)

Generate 16-20 practical items. Each responsible field should be a role like 'Kit Manager', 'Club Secretary', 'Coach', 'Ground Staff', etc.";

        $res = asc_gemini_generate_text($prompt, ['temperature'=>0.5,'maxOutputTokens'=>800,'timeout'=>25]);
        if (!empty($res['success'])) {
            $text = preg_replace('/```json\s*|\s*```/', '', $res['text']);
            if (preg_match('/\[[\s\S]+\]/m', $text, $m)) {
                $items = json_decode($m[0], true);
                if (is_array($items)) {
                    $stmt2 = $conn->prepare("INSERT INTO event_checklists (fixture_id,item,responsible,sort_order) VALUES (?,?,?,?)");
                    foreach ($items as $i=>$item) {
                        $itm  = substr(trim((string)($item['item'] ?? '')), 0, 255);
                        $resp = substr(trim((string)($item['responsible'] ?? '')), 0, 120);
                        $ord  = $i + 1;
                        $stmt2->bind_param('issi', $fid, $itm, $resp, $ord);
                        $stmt2->execute();
                    }
                    $stmt2->close();
                    echo json_encode(['success'=>true,'count'=>count($items)]); exit;
                }
            }
        }
        echo json_encode(['error'=>'Could not generate checklist. Check Gemini API key.']); exit;
    }

    echo json_encode(['error'=>'Unknown action.']); exit;
}
$conn->close();
?>

<style>
.event-hero { background:linear-gradient(135deg,#7c2d12,#c2410c); color:#fff; border-radius:14px; padding:1.75rem 2rem; margin-bottom:1.5rem; }
.event-warn { background:#fef9c3; border:1px solid #fde68a; border-radius:10px; padding:1rem 1.25rem; margin-bottom:1rem; color:#92400e; font-size:.9rem; }
.checklist-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.5rem; box-shadow:0 4px 20px rgba(0,0,0,.05); }
.checklist-item { display:flex; align-items:flex-start; gap:.75rem; padding:.75rem; border-bottom:1px solid #f1f5f9; transition:background .1s; }
.checklist-item:last-child { border-bottom:none; }
.checklist-item:hover { background:#fafafa; }
.checklist-item.done { opacity:.55; }
.checklist-item.done .item-text { text-decoration:line-through; color:#94a3b8; }
.item-text { font-size:.9rem; font-weight:600; color:#1e293b; }
.item-resp { font-size:.77rem; color:#64748b; margin-top:.1rem; }
.check-box { width:22px; height:22px; border-radius:6px; border:2px solid #cbd5e1; cursor:pointer; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:1px; transition:all .15s; }
.check-box.checked { background:#059669; border-color:#059669; color:#fff; }
.progress-bar-event { height:8px; border-radius:4px; background:#e2e8f0; overflow:hidden; }
.progress-fill { height:100%; background:linear-gradient(90deg,#059669,#10b981); transition:width .3s; border-radius:4px; }
.btn-ai-cl { background:linear-gradient(135deg,#14497a,#1a5a8c); color:#fff; border:none; border-radius:8px; padding:.5rem 1rem; font-weight:600; font-size:.85rem; }
.btn-ai-cl:hover { opacity:.88; color:#fff; }
.item-delete { color:#cbd5e1; cursor:pointer; padding:.25rem; border-radius:4px; transition:color .1s; flex-shrink:0; }
.item-delete:hover { color:#ef4444; }
</style>

<div style="max-width:900px;margin:0 auto;">
<?php if (!$list_table_exists): ?>
<div class="event-warn">
    <i class="fas fa-exclamation-triangle me-2"></i>
    The <code>event_checklists</code> table is not installed yet.
    Run <code>php scripts/migrate.php</code> from the project root to create it.
</div>
<?php endif; ?>

<div class="event-hero">
    <div class="d-flex align-items-center gap-3">
        <div style="font-size:2.5rem;">📋</div>
        <div>
            <h2 class="mb-1">Event Day Checklist</h2>
            <p class="mb-0" style="color:rgba(255,255,255,.75);font-size:.9rem;">Track every preparation item before, during, and after a match</p>
        </div>
    </div>
</div>

<div class="checklist-card mb-3">
    <div class="row g-3 align-items-end">
        <div class="col-md-7">
            <label class="form-label fw-semibold">Select Match / Event</label>
            <select id="fixtureSelect" class="form-select" onchange="loadChecklist()">
                <option value="">— Select a Fixture —</option>
                <?php foreach ($fixtures as $f): ?>
                <option value="<?php echo e($f['fixture_id']); ?>" data-label="<?php echo e($f['home'].' vs '.$f['away'].' — '.date('d M Y',strtotime($f['match_date']))); ?>">
                    <?php echo e(date('d M Y',strtotime($f['match_date']))); ?> — <?php echo e($f['home']); ?> vs <?php echo e($f['away']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5 d-flex gap-2">
            <button class="btn-ai-cl flex-grow-1" id="aiGenBtn" onclick="aiGenerateChecklist()" disabled <?php echo !$list_table_exists ? 'style="opacity:.4;pointer-events:none;"' : ''; ?>>
                <i class="fas fa-wand-magic-sparkles me-1"></i>AI Generate Checklist
            </button>
            <button class="btn btn-outline-secondary" onclick="window.print()" title="Print">
                <i class="fas fa-print"></i>
            </button>
        </div>
    </div>
</div>

<div id="mainPanel" style="display:none;">
    <!-- Progress -->
    <div class="checklist-card mb-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <span class="fw-bold" id="progressLabel">0 / 0 completed</span>
            <span class="text-muted small" id="matchLabel"></span>
        </div>
        <div class="progress-bar-event">
            <div class="progress-fill" id="progressFill" style="width:0%"></div>
        </div>
    </div>

    <!-- Checklist -->
    <div class="checklist-card mb-3">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-bold mb-0">Checklist Items</h6>
            <button class="btn btn-sm btn-outline-primary" onclick="showAddItem()">
                <i class="fas fa-plus me-1"></i>Add Item
            </button>
        </div>
        <div id="checklistItems"><p class="text-muted text-center py-3">Loading checklist…</p></div>
    </div>

    <!-- Add Item Form (hidden by default) -->
    <div class="checklist-card mb-3" id="addItemForm" style="display:none;">
        <h6 class="fw-bold mb-3">Add Checklist Item</h6>
        <div class="row g-2">
            <div class="col-md-6">
                <input type="text" id="newItem" class="form-control" placeholder="e.g. Check corner flags are in place">
            </div>
            <div class="col-md-4">
                <input type="text" id="newResp" class="form-control" placeholder="Responsible (e.g. Ground Staff)">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" onclick="addItem()">Add</button>
            </div>
        </div>
    </div>
</div>

<div id="emptyPanel" class="checklist-card text-center py-5 text-muted">
    <div style="font-size:3rem;">📋</div>
    <p class="mt-2">Select a fixture to view or create its checklist</p>
</div>

<script>
const SELF = window.location.href.split('?')[0];
let currentFixtureId = null;

async function loadChecklist() {
    const sel = document.getElementById('fixtureSelect');
    currentFixtureId = sel.value;
    const label = sel.options[sel.selectedIndex]?.dataset.label || '';
    document.getElementById('matchLabel').textContent = label;

    if (!currentFixtureId) {
        document.getElementById('mainPanel').style.display = 'none';
        document.getElementById('emptyPanel').style.display = '';
        document.getElementById('aiGenBtn').disabled = true;
        return;
    }
    document.getElementById('mainPanel').style.display = '';
    document.getElementById('emptyPanel').style.display = 'none';
    document.getElementById('aiGenBtn').disabled = false;

    const fd = new FormData();
    fd.append('cl_action','load');
    fd.append('fixture_id', currentFixtureId);
    const res = await fetch(SELF, {method:'POST',body:fd});
    const data = await res.json();
    renderItems(data.items || []);
}

function renderItems(items) {
    const box = document.getElementById('checklistItems');
    if (!items.length) {
        box.innerHTML = '<p class="text-muted text-center py-3">No items yet. Click AI Generate or Add Item to start.</p>';
        updateProgress(0, 0); return;
    }
    const done = items.filter(i=>i.is_done==1).length;
    updateProgress(done, items.length);

    box.innerHTML = items.map(item => `
        <div class="checklist-item ${item.is_done==1?'done':''}" id="ci_${item.id}">
            <div class="check-box ${item.is_done==1?'checked':''}" onclick="toggleItem(${item.id}, ${item.is_done==1?0:1})">
                ${item.is_done==1?'<i class="fas fa-check" style="font-size:.7rem;"></i>':''}
            </div>
            <div style="flex:1">
                <div class="item-text">${esc(item.item)}</div>
                ${item.responsible ? `<div class="item-resp"><i class="fas fa-user me-1"></i>${esc(item.responsible)}</div>` : ''}
                ${item.done_at ? `<div class="item-resp text-success"><i class="fas fa-check-circle me-1"></i>Done at ${item.done_at}</div>` : ''}
            </div>
            <span class="item-delete" onclick="deleteItem(${item.id})"><i class="fas fa-times"></i></span>
        </div>`).join('');
}

function updateProgress(done, total) {
    const pct = total > 0 ? Math.round(done/total*100) : 0;
    document.getElementById('progressLabel').textContent = `${done} / ${total} completed (${pct}%)`;
    document.getElementById('progressFill').style.width = pct + '%';
}

async function toggleItem(id, done) {
    const fd = new FormData();
    fd.append('cl_action','toggle'); fd.append('id',id); fd.append('done',done);
    await fetch(SELF, {method:'POST',body:fd});
    loadChecklist();
}

async function deleteItem(id) {
    if (!confirm('Remove this item?')) return;
    const fd = new FormData();
    fd.append('cl_action','delete'); fd.append('id',id);
    await fetch(SELF, {method:'POST',body:fd});
    document.getElementById('ci_'+id)?.remove();
    loadChecklist();
}

function showAddItem() {
    const f = document.getElementById('addItemForm');
    f.style.display = f.style.display==='none' ? '' : 'none';
}

async function addItem() {
    const item = document.getElementById('newItem').value.trim();
    if (!item) { alert('Item text required'); return; }
    const fd = new FormData();
    fd.append('cl_action','add_item');
    fd.append('fixture_id', currentFixtureId);
    fd.append('item', item);
    fd.append('responsible', document.getElementById('newResp').value);
    await fetch(SELF, {method:'POST',body:fd});
    document.getElementById('newItem').value = '';
    document.getElementById('newResp').value = '';
    loadChecklist();
}

async function aiGenerateChecklist() {
    const sel = document.getElementById('fixtureSelect');
    const label = sel.options[sel.selectedIndex]?.dataset.label || 'a football match';
    if (!confirm(`Generate AI checklist for:\n${label}\n\nThis will clear existing uncompleted items.`)) return;
    const btn = document.getElementById('aiGenBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Generating…';
    const fd = new FormData();
    fd.append('cl_action','ai_generate');
    fd.append('fixture_id', currentFixtureId);
    fd.append('match_info', label);
    const res = await fetch(SELF, {method:'POST',body:fd});
    const data = await res.json();
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-wand-magic-sparkles me-1"></i>AI Generate Checklist';
    if (data.success) { loadChecklist(); } 
    else { alert('Error: ' + (data.error||'AI failed.')); }
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
</script>

<?php include_once '../includes/footer.php'; ?>
