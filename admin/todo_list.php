<?php
require_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/gemini_client.php';
require_once '../includes/csrf.php';
require_once '../includes/feature_helpers.php';

// Check if admin_todos table exists, so we can fail gracefully
$todos_table_exists = db_table_exists($conn, 'admin_todos');

require_once __DIR__ . '/../includes/input_sanitize.php';

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['todo_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['todo_action'];

    if ($action === 'add') {
        if (!$todos_table_exists) { echo json_encode(['error'=>'Table admin_todos does not exist. Run migrations.']); exit; }
        $title    = trim((string)($_POST['title'] ?? ''));
        $desc     = trim((string)($_POST['description'] ?? ''));
        $priority = in_array($_POST['priority']??'', ['low','medium','high','urgent']) ? $_POST['priority'] : 'medium';
        $due      = !empty($_POST['due_date']) ? $_POST['due_date'] : null;
        if ($title === '') { echo json_encode(['error'=>'Title required.']); exit; }
        $stmt = $conn->prepare("INSERT INTO admin_todos (title,description,priority,due_date) VALUES (?,?,?,?)");
        $stmt->bind_param('ssss', $title, $desc, $priority, $due);
        $stmt->execute();
        echo json_encode(['success'=>true, 'id'=>$stmt->insert_id]);
        exit;
    }

    if ($action === 'update_status') {
        if (!$todos_table_exists) { echo json_encode(['error'=>'Table admin_todos does not exist.']); exit; }
        $id     = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status']??'', ['open','in_progress','done']) ? $_POST['status'] : 'open';
        $conn->query("UPDATE admin_todos SET status='$status' WHERE id=$id");
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'delete') {
        if (!$todos_table_exists) { echo json_encode(['error'=>'Table admin_todos does not exist.']); exit; }
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM admin_todos WHERE id=$id");
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'ai_suggest') {
        $prompt = "You are the assistant for Apex Sports Club admin. Suggest 6 practical, specific to-do tasks for a sports club manager to handle this week.
Include a mix of priorities: urgent, high, and medium tasks.
Format as JSON array:
[{\"title\":\"...\",\"description\":\"...\",\"priority\":\"urgent|high|medium\",\"due_days\":3}]
Topics to cover: member management, facilities, events, finances, communications, team logistics.";
        $res = asc_gemini_generate_text($prompt, ['temperature'=>0.7,'maxOutputTokens'=>600,'timeout'=>20]);
        if (!empty($res['success'])) {
            $text = preg_replace('/```json\s*|\s*```/', '', $res['text']);
            if (preg_match('/\[[\s\S]+\]/m', $text, $m)) {
                $tasks = json_decode($m[0], true);
                echo json_encode(['success'=>true,'tasks'=>$tasks]);
            } else {
                echo json_encode(['error'=>'Could not parse AI response.']);
            }
        } else {
            echo json_encode(['error'=>$res['error']??'AI unavailable.']);
        }
        exit;
    }

    echo json_encode(['error'=>'Unknown action.']); exit;
}

// ── Fetch todos ───────────────────────────────────────────────────────────────
$todos = ['open'=>[], 'in_progress'=>[], 'done'=>[]];
if ($todos_table_exists) {
    $q = $conn->query("SELECT * FROM admin_todos ORDER BY FIELD(priority,'urgent','high','medium','low'), due_date ASC, created_at DESC");
    if ($q) while ($row = $q->fetch_assoc()) $todos[$row['status']][] = $row;
}
$conn->close();

$priorityColors = ['urgent'=>'#ef4444','high'=>'#f97316','medium'=>'#3b82f6','low'=>'#94a3b8'];
$priorityLabels = ['urgent'=>'Urgent','high'=>'High','medium'=>'Medium','low'=>'Low'];
?>

<style>
.todo-shell { max-width: 1200px; }
.todo-hero { background: linear-gradient(135deg,#0f172a,#1e293b); color:#fff; border-radius:14px; padding:1.5rem 2rem; margin-bottom:1.5rem; }
.kanban-col { background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:1rem; min-height:300px; }
.kanban-header { font-weight:700; font-size:.85rem; text-transform:uppercase; letter-spacing:.5px; margin-bottom:1rem; display:flex; align-items:center; gap:.5rem; }
.todo-card { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:.9rem 1rem; margin-bottom:.6rem; transition:box-shadow .15s; cursor:default; }
.todo-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.07); }
.priority-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
.badge-priority { font-size:.7rem; font-weight:700; padding:.2rem .6rem; border-radius:12px; }
.todo-title { font-weight:600; font-size:.9rem; color:#1e293b; }
.todo-desc  { font-size:.8rem; color:#64748b; margin-top:.2rem; }
.due-tag    { font-size:.75rem; color:#64748b; }
.due-overdue { color:#ef4444; font-weight:600; }
.btn-status { font-size:.75rem; border-radius:6px; padding:.2rem .6rem; border:1px solid #e2e8f0; background:#f8fafc; cursor:pointer; transition:all .1s; }
.btn-status:hover { background:#e2e8f0; }
.col-count { background:rgba(0,0,0,.08); border-radius:10px; padding:.1rem .5rem; font-size:.75rem; font-weight:700; }
.btn-ai { background:linear-gradient(135deg,#4f46e5,#7c3aed); color:#fff; border:none; border-radius:8px; padding:.5rem 1rem; font-size:.85rem; font-weight:600; transition:opacity .15s; }
.btn-ai:hover { opacity:.88; color:#fff; }
</style>

<div class="todo-shell mx-auto">
    <div class="todo-hero d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
            <h2 class="mb-1"><i class="fas fa-list-check me-2"></i>Admin To-Do List</h2>
            <p class="mb-0" style="color:rgba(255,255,255,.7);font-size:.9rem;">Track tasks by priority across your Kanban board</p>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <button class="btn-ai" onclick="aiSuggest()" id="aiBtn">
                <i class="fas fa-wand-magic-sparkles me-1"></i>AI Suggest Tasks
            </button>
            <button class="btn btn-light fw-semibold" data-bs-toggle="modal" data-bs-target="#addModal">
                <i class="fas fa-plus me-1"></i>Add Task
            </button>
        </div>
    </div>

    <?php if (!$todos_table_exists): ?>
    <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center gap-3">
        <i class="fas fa-database fa-2x text-warning"></i>
        <div>
            <strong class="d-block">Missing Database Table</strong>
            <span>The <code>admin_todos</code> table does not exist yet. Run <code>php scripts/migrate.php</code> from the project root to create it.</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Kanban Board -->
    <div class="row g-3">
        <?php
        $cols = [
            'open'        => ['label'=>'Open', 'icon'=>'fa-circle-dot', 'color'=>'#3b82f6'],
            'in_progress' => ['label'=>'In Progress', 'icon'=>'fa-spinner', 'color'=>'#f97316'],
            'done'        => ['label'=>'Done', 'icon'=>'fa-circle-check', 'color'=>'#059669'],
        ];
        foreach ($cols as $status => $col):
        ?>
        <div class="col-lg-4">
            <div class="kanban-col">
                <div class="kanban-header" style="color:<?php echo $col['color']; ?>">
                    <i class="fas <?php echo $col['icon']; ?>"></i>
                    <?php echo $col['label']; ?>
                    <span class="col-count"><?php echo count($todos[$status]); ?></span>
                </div>
                <div id="col_<?php echo $status; ?>">
                <?php foreach ($todos[$status] as $t):
                    $pColor = $priorityColors[$t['priority']] ?? '#94a3b8';
                    $pLabel = $priorityLabels[$t['priority']] ?? ucfirst($t['priority']);
                    $isOverdue = $t['due_date'] && $t['status']!=='done' && strtotime($t['due_date']) < time();
                ?>
                <div class="todo-card" id="card_<?php echo $t['id']; ?>">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div class="todo-title"><?php echo e($t['title']); ?></div>
                        <span class="badge-priority ms-2 flex-shrink-0"
                            style="background:<?php echo $pColor; ?>22;color:<?php echo $pColor; ?>;border:1px solid <?php echo $pColor; ?>44;">
                            <?php echo $pLabel; ?>
                        </span>
                    </div>
                    <?php if ($t['description']): ?>
                    <div class="todo-desc"><?php echo e($t['description']); ?></div>
                    <?php endif; ?>
                    <?php if ($t['due_date']): ?>
                    <div class="due-tag mt-1 <?php echo $isOverdue?'due-overdue':''; ?>">
                        <i class="fas fa-calendar-alt me-1"></i>
                        Due: <?php echo date('d M Y', strtotime($t['due_date'])); ?>
                        <?php echo $isOverdue ? '⚠️ Overdue' : ''; ?>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex gap-1 mt-2 flex-wrap">
                        <?php if ($status !== 'open'):        ?><button class="btn-status" onclick="updateStatus(<?php echo $t['id']; ?>,'open')">↩ Open</button><?php endif; ?>
                        <?php if ($status !== 'in_progress'): ?><button class="btn-status" onclick="updateStatus(<?php echo $t['id']; ?>,'in_progress')">▶ Start</button><?php endif; ?>
                        <?php if ($status !== 'done'):        ?><button class="btn-status" onclick="updateStatus(<?php echo $t['id']; ?>,'done')">✔ Done</button><?php endif; ?>
                        <button class="btn-status" style="color:#ef4444" onclick="deleteTodo(<?php echo $t['id']; ?>)">🗑</button>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Add Task Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add Task</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-semibold">Title *</label>
          <input type="text" id="newTitle" class="form-control" placeholder="Task title…"></div>
        <div class="mb-3"><label class="form-label fw-semibold">Description</label>
          <textarea id="newDesc" class="form-control" rows="3" placeholder="Optional details…"></textarea></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label fw-semibold">Priority</label>
            <select id="newPriority" class="form-select">
              <option value="medium">Medium</option>
              <option value="high">High</option>
              <option value="urgent">Urgent</option>
              <option value="low">Low</option>
            </select></div>
          <div class="col-6"><label class="form-label fw-semibold">Due Date</label>
            <input type="date" id="newDue" class="form-control" min="<?php echo date('Y-m-d'); ?>"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary" onclick="addTodo()"><i class="fas fa-plus me-1"></i>Add Task</button>
      </div>
    </div>
  </div>
</div>

<!-- AI Suggest Modal -->
<div class="modal fade" id="aiModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;">
        <h5 class="modal-title"><i class="fas fa-wand-magic-sparkles me-2"></i>AI Suggested Tasks</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="aiModalBody">
        <div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Gemini is generating tasks…</p></div>
      </div>
    </div>
  </div>
</div>

<script>
const SELF = window.location.href.split('?')[0];

async function addTodo() {
    const title = document.getElementById('newTitle').value.trim();
    if (!title) { alert('Title is required'); return; }
    const fd = new FormData();
    fd.append('todo_action','add');
    fd.append('title', title);
    fd.append('description', document.getElementById('newDesc').value);
    fd.append('priority', document.getElementById('newPriority').value);
    fd.append('due_date', document.getElementById('newDue').value);
    const res = await fetch(SELF, {method:'POST',body:fd});
    const data = await res.json();
    if (data.success) { location.reload(); }
    else alert(data.error);
}

async function updateStatus(id, status) {
    const fd = new FormData();
    fd.append('todo_action','update_status');
    fd.append('id', id);
    fd.append('status', status);
    await fetch(SELF, {method:'POST',body:fd});
    location.reload();
}

async function deleteTodo(id) {
    if (!confirm('Delete this task?')) return;
    const fd = new FormData();
    fd.append('todo_action','delete');
    fd.append('id', id);
    await fetch(SELF, {method:'POST',body:fd});
    document.getElementById('card_'+id)?.remove();
}

async function aiSuggest() {
    const modal = new bootstrap.Modal(document.getElementById('aiModal'));
    document.getElementById('aiModalBody').innerHTML = `<div class="text-center py-4"><div class="spinner-border text-primary"></div><p class="mt-2">Gemini is generating tasks…</p></div>`;
    modal.show();
    const fd = new FormData();
    fd.append('todo_action','ai_suggest');
    const res = await fetch(SELF, {method:'POST',body:fd});
    const data = await res.json();
    const body = document.getElementById('aiModalBody');
    if (data.success && data.tasks) {
        const pColors = {urgent:'#ef4444',high:'#f97316',medium:'#3b82f6',low:'#94a3b8'};
        let html = '<div class="d-flex flex-column gap-2">';
        data.tasks.forEach((t,i) => {
            const pc = pColors[t.priority] || '#94a3b8';
            const due = t.due_days ? new Date(Date.now()+t.due_days*86400000).toISOString().slice(0,10) : '';
            html += `<div class="d-flex align-items-start gap-3 border rounded p-3">
                <div style="flex:1">
                    <strong>${esc(t.title)}</strong>
                    <span class="ms-2 badge" style="background:${pc}22;color:${pc};border:1px solid ${pc}44;font-size:.7rem;">${esc(t.priority)}</span>
                    <br><small class="text-muted">${esc(t.description||'')}</small>
                </div>
                <button class="btn btn-sm btn-success" onclick="importTask('${esc(t.title)}','${esc(t.description||'')}','${esc(t.priority)}','${due}',this)">
                    <i class="fas fa-plus"></i> Add
                </button>
            </div>`;
        });
        html += '</div>';
        body.innerHTML = html;
    } else {
        body.innerHTML = `<div class="alert alert-danger">${esc(data.error||'Could not generate suggestions.')}</div>`;
    }
}

async function importTask(title, desc, priority, due, btn) {
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    const fd = new FormData();
    fd.append('todo_action','add');
    fd.append('title', title);
    fd.append('description', desc);
    fd.append('priority', priority);
    fd.append('due_date', due);
    await fetch(SELF, {method:'POST',body:fd});
    btn.innerHTML = '✓ Added'; btn.classList.replace('btn-success','btn-secondary');
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/'/g,'&#39;'); }
</script>

<?php include_once '../includes/footer.php'; ?>
