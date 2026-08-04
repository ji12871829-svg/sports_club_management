<?php
require_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/gemini_client.php';

require_once __DIR__ . '/../includes/input_sanitize.php';
require_once '../includes/feature_helpers.php';

// Check if coach_session_notes table exists
$notes_table_exists = db_table_exists($conn, 'coach_session_notes');

// Fetch coaches & sports
$coaches = [];
$q = $conn->query("SELECT coach_id, first_name, last_name FROM coaches ORDER BY first_name");
if ($q) while ($r = $q->fetch_assoc()) $coaches[] = $r;

$sports = [];
$q2 = $conn->query("SELECT sport_id, name FROM sports ORDER BY name");
if ($q2) while ($r = $q2->fetch_assoc()) $sports[] = $r;

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sn_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['sn_action'];

    if ($action === 'save') {
        if (!$notes_table_exists) { echo json_encode(['error'=>'Table coach_session_notes does not exist. Run migrations.']); exit; }
        $coachId  = (int)($_POST['coach_id'] ?? 0);
        $date     = trim((string)($_POST['session_date'] ?? ''));
        $sportId  = (int)($_POST['sport_id'] ?? 0) ?: null;
        $title    = trim((string)($_POST['title'] ?? ''));
        $notes    = trim((string)($_POST['notes'] ?? ''));
        if (!$coachId || !$date || !$title || !$notes) {
            echo json_encode(['error'=>'All required fields must be filled.']); exit;
        }
        $stmt = $conn->prepare("INSERT INTO coach_session_notes (coach_id,session_date,sport_id,title,notes) VALUES (?,?,?,?,?)");
        $stmt->bind_param('isiss', $coachId, $date, $sportId, $title, $notes);
        $stmt->execute();
        echo json_encode(['success'=>true,'id'=>$stmt->insert_id]); exit;
    }

    if ($action === 'ai_summarize') {
        $id    = (int)($_POST['id'] ?? 0);
        $notes = trim((string)($_POST['notes'] ?? ''));
        $coachName = trim((string)($_POST['coach_name'] ?? 'Coach'));
        if (!$notes) { echo json_encode(['error'=>'No notes to summarize.']); exit; }

        $prompt = "You are a sports performance analyst. A coach named {$coachName} has written the following session notes:

---
{$notes}
---

Produce a structured session summary with these exact sections:

**Session Overview:** (1-2 sentences)
**Key Activities:** (bullet list)
**Player Performance Highlights:** (bullet list)
**Areas Needing Improvement:** (bullet list)
**Action Points for Next Session:** (numbered list)
**Overall Assessment:** (1 sentence rating: Excellent/Good/Average/Below Average with brief reason)";

        $res = asc_gemini_generate_text($prompt, ['temperature'=>0.5,'maxOutputTokens'=>600,'timeout'=>25]);
        if (!empty($res['success'])) {
            $summary = $res['text'];
            if ($id > 0 && $notes_table_exists) {
                $stmt = $conn->prepare("UPDATE coach_session_notes SET ai_summary=? WHERE id=?");
                $stmt->bind_param('si', $summary, $id);
                $stmt->execute();
            }
            echo json_encode(['success'=>true,'summary'=>$summary]); 
        } else {
            echo json_encode(['error'=>$res['error']??'AI unavailable.']);
        }
        exit;
    }

    if ($action === 'delete') {
        if (!$notes_table_exists) { echo json_encode(['error'=>'Table coach_session_notes does not exist.']); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM coach_session_notes WHERE id=$id");
        echo json_encode(['success'=>true]); exit;
    }

    echo json_encode(['error'=>'Unknown action.']); exit;
}

// Fetch notes list
$coachFilter = (int)($_GET['coach_id'] ?? 0);
$notes = [];
if ($notes_table_exists) {
    $filterSql = $coachFilter ? "WHERE csn.coach_id=$coachFilter" : '';
    $q3 = $conn->query("SELECT csn.*, c.first_name, c.last_name, s.name AS sport_name
        FROM coach_session_notes csn
        JOIN coaches c ON c.coach_id=csn.coach_id
        LEFT JOIN sports s ON s.sport_id=csn.sport_id
        $filterSql
        ORDER BY csn.session_date DESC LIMIT 100");
    if ($q3) while ($r = $q3->fetch_assoc()) $notes[] = $r;
}

$conn->close();
?>
<style>
.notes-hero { background:linear-gradient(135deg,#0c4a6e,#0369a1); color:#fff; border-radius:14px; padding:1.75rem 2rem; margin-bottom:1.5rem; }
.note-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; margin-bottom:.75rem; transition:box-shadow .15s; }
.note-card:hover { box-shadow:0 4px 14px rgba(0,0,0,.07); }
.note-title { font-weight:700; font-size:.95rem; color:#0f172a; }
.note-meta  { font-size:.78rem; color:#64748b; margin-top:.2rem; }
.note-preview { font-size:.85rem; color:#475569; margin-top:.5rem; line-height:1.5; }
.ai-summary-box { background:#f0fdf4; border:1px solid #bbf7d0; border-radius:8px; padding:1rem; margin-top:.75rem; font-size:.85rem; line-height:1.65; white-space:pre-wrap; }
.form-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.5rem; box-shadow:0 4px 20px rgba(0,0,0,.05); }
.btn-ai-sum { background:linear-gradient(135deg,#059669,#10b981); color:#fff; border:none; border-radius:8px; padding:.5rem 1rem; font-size:.85rem; font-weight:600; }
.btn-ai-sum:hover { opacity:.88; color:#fff; }
</style>

<div style="max-width:1100px;margin:0 auto;">
<div class="notes-hero">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div style="font-size:2.5rem;">📝</div>
            <div>
                <h2 class="mb-1">Coach Session Notes</h2>
                <p class="mb-0" style="color:rgba(255,255,255,.75);font-size:.9rem;">Log training sessions and get AI-powered summaries</p>
            </div>
        </div>
        <button class="btn btn-light fw-semibold" data-bs-toggle="modal" data-bs-target="#newNoteModal" <?php echo !$notes_table_exists ? 'disabled' : ''; ?>>
            <i class="fas fa-plus me-1"></i>New Note
        </button>
    </div>
</div>

<?php if (!$notes_table_exists): ?>
<div class="alert alert-warning border-0 shadow-sm d-flex align-items-center gap-3">
    <i class="fas fa-database fa-2x text-warning"></i>
    <div>
        <strong class="d-block">Missing Database Table</strong>
        <span>The <code>coach_session_notes</code> table does not exist yet. Run <code>php scripts/migrate.php</code> from the project root to create it.</span>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Filter sidebar -->
    <div class="col-lg-3">
        <div class="form-card">
            <h6 class="fw-bold mb-3">Filter by Coach</h6>
            <a href="coach_session_notes.php" class="btn btn-sm <?php echo !$coachFilter?'btn-primary':'btn-outline-secondary'; ?> w-100 mb-2">All Coaches</a>
            <?php foreach ($coaches as $c): ?>
            <a href="?coach_id=<?php echo $c['coach_id']; ?>" class="btn btn-sm <?php echo $coachFilter==$c['coach_id']?'btn-primary':'btn-outline-secondary'; ?> w-100 mb-1">
                <?php echo e($c['first_name'].' '.$c['last_name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Notes list -->
    <div class="col-lg-9">
        <?php if (empty($notes)): ?>
        <div class="text-center py-5 text-muted">
            <div style="font-size:3rem;">📝</div>
            <p class="mt-2">No session notes yet. Click <strong>New Note</strong> to create one.</p>
        </div>
        <?php else: ?>
        <?php foreach ($notes as $n):
            $preview = substr(strip_tags($n['notes']), 0, 140) . (strlen($n['notes'])>140?'…':'');
        ?>
        <div class="note-card" id="note_<?php echo $n['id']; ?>">
            <div class="d-flex justify-content-between align-items-start">
                <div style="flex:1">
                    <div class="note-title"><?php echo e($n['title']); ?></div>
                    <div class="note-meta">
                        <i class="fas fa-user-tie me-1"></i><?php echo e($n['first_name'].' '.$n['last_name']); ?>
                        &nbsp;•&nbsp;<i class="fas fa-calendar me-1"></i><?php echo date('d M Y', strtotime($n['session_date'])); ?>
                        <?php if ($n['sport_name']): ?>&nbsp;•&nbsp;<i class="fas fa-futbol me-1"></i><?php echo e($n['sport_name']); ?><?php endif; ?>
                    </div>
                    <div class="note-preview"><?php echo e($preview); ?></div>
                </div>
                <div class="d-flex gap-1 ms-3">
                    <button class="btn btn-sm btn-outline-secondary" onclick="viewNote(<?php echo $n['id']; ?>)"
                        data-id="<?php echo $n['id']; ?>"
                        data-notes="<?php echo e($n['notes']); ?>"
                        data-summary="<?php echo e($n['ai_summary'] ?? ''); ?>"
                        data-coach="<?php echo e($n['first_name'].' '.$n['last_name']); ?>"
                        data-title="<?php echo e($n['title']); ?>">
                        <i class="fas fa-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="deleteNote(<?php echo $n['id']; ?>)">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <?php if ($n['ai_summary']): ?>
            <div class="ai-summary-box mt-2">
                <strong style="color:#059669;"><i class="fas fa-wand-magic-sparkles me-1"></i>AI Summary</strong><br>
                <?php echo e(substr($n['ai_summary'],0,300)); ?>…
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
</div>

<!-- New Note Modal -->
<div class="modal fade" id="newNoteModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">New Session Note</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-semibold">Coach *</label>
            <select id="nnCoach" class="form-select">
              <?php foreach ($coaches as $c): ?>
              <option value="<?php echo $c['coach_id']; ?>"><?php echo e($c['first_name'].' '.$c['last_name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Session Date *</label>
            <input type="date" id="nnDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Sport</label>
            <select id="nnSport" class="form-select">
              <option value="">— Any Sport —</option>
              <?php foreach ($sports as $s): ?>
              <option value="<?php echo $s['sport_id']; ?>"><?php echo e($s['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-semibold">Session Title *</label>
            <input type="text" id="nnTitle" class="form-control" placeholder="e.g. Tuesday Tactical Session">
          </div>
          <div class="col-12">
            <label class="form-label fw-semibold">Notes *</label>
            <textarea id="nnNotes" class="form-control" rows="6" placeholder="Write your full session notes here…"></textarea>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="saveNote()"><i class="fas fa-save me-1"></i>Save Note</button>
      </div>
    </div>
  </div>
</div>

<!-- View / AI Summarize Modal -->
<div class="modal fade" id="viewNoteModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="vnTitle">Session Notes</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3">
          <h6 class="text-muted">Raw Notes</h6>
          <div id="vnNotes" style="background:#f8fafc;border-radius:8px;padding:1rem;font-size:.9rem;line-height:1.65;white-space:pre-wrap;max-height:250px;overflow-y:auto;"></div>
        </div>
        <div id="vnSummaryBox">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="text-success mb-0"><i class="fas fa-wand-magic-sparkles me-1"></i>AI Summary</h6>
            <button class="btn-ai-sum" onclick="aiSummarize()" id="sumBtn">Generate AI Summary</button>
          </div>
          <div id="vnSummary" class="ai-summary-box" style="display:none;"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
const SELF = window.location.href.split('?')[0];
let currentNoteId = null;

async function saveNote() {
    const fd = new FormData();
    fd.append('sn_action','save');
    fd.append('coach_id', document.getElementById('nnCoach').value);
    fd.append('session_date', document.getElementById('nnDate').value);
    fd.append('sport_id', document.getElementById('nnSport').value);
    fd.append('title', document.getElementById('nnTitle').value);
    fd.append('notes', document.getElementById('nnNotes').value);
    const res = await fetch(SELF,{method:'POST',body:fd});
    const data = await res.json();
    if (data.success) { location.reload(); } else { alert(data.error); }
}

function viewNote(id) {
    const btn = document.querySelector(`[data-id="${id}"]`);
    currentNoteId = id;
    document.getElementById('vnTitle').textContent = btn.dataset.title;
    document.getElementById('vnNotes').textContent = btn.dataset.notes;
    const existingSummary = btn.dataset.summary;
    if (existingSummary) {
        document.getElementById('vnSummary').textContent = existingSummary;
        document.getElementById('vnSummary').style.display = '';
        document.getElementById('sumBtn').textContent = 'Regenerate Summary';
    } else {
        document.getElementById('vnSummary').style.display = 'none';
        document.getElementById('sumBtn').textContent = 'Generate AI Summary';
    }
    document.getElementById('sumBtn').dataset.coach = btn.dataset.coach;
    document.getElementById('sumBtn').dataset.notes = btn.dataset.notes;
    new bootstrap.Modal(document.getElementById('viewNoteModal')).show();
}

async function aiSummarize() {
    const btn = document.getElementById('sumBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Summarizing…';
    const fd = new FormData();
    fd.append('sn_action','ai_summarize');
    fd.append('id', currentNoteId);
    fd.append('notes', btn.dataset.notes);
    fd.append('coach_name', btn.dataset.coach);
    const res = await fetch(SELF,{method:'POST',body:fd});
    const data = await res.json();
    btn.disabled = false; btn.textContent = 'Regenerate Summary';
    if (data.success) {
        document.getElementById('vnSummary').textContent = data.summary;
        document.getElementById('vnSummary').style.display = '';
    } else { alert(data.error); }
}

async function deleteNote(id) {
    if (!confirm('Delete this session note?')) return;
    const fd = new FormData();
    fd.append('sn_action','delete'); fd.append('id',id);
    await fetch(SELF,{method:'POST',body:fd});
    document.getElementById('note_'+id)?.remove();
}
</script>

<?php include_once '../includes/footer.php'; ?>
