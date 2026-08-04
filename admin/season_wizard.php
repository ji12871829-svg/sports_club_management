<?php
require_once '../includes/admin_header.php';
require_once '../config/db_connect.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

// Fetch data for steps
$sports = [];
$q = $conn->query("SELECT sport_id, name FROM sports ORDER BY name");
if ($q) while ($r = $q->fetch_assoc()) $sports[] = $r;

$existingLeagues = [];
$q2 = $conn->query("SELECT league_id, name, sport_id FROM leagues ORDER BY name");
if ($q2) while ($r = $q2->fetch_assoc()) $existingLeagues[] = $r;

$allTeams = [];
$q3 = $conn->query("SELECT team_id, name, sport_id FROM teams ORDER BY name");
if ($q3) while ($r = $q3->fetch_assoc()) $allTeams[] = $r;

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wizard_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['wizard_action'];

    if ($action === 'preview_fixtures') {
        $teams   = json_decode($_POST['teams'] ?? '[]', true);
        $startDt = $_POST['start_date'] ?? date('Y-m-d');
        $matchDay = (int)($_POST['match_day'] ?? 6); // Saturday default
        if (count($teams) < 2) { echo json_encode(['error'=>'Need at least 2 teams.']); exit; }

        // Round-robin schedule
        $fixtures = [];
        $date = new DateTime($startDt);
        // Advance to next matchday
        while ((int)$date->format('N') !== $matchDay) $date->modify('+1 day');

        $n = count($teams);
        // If odd, add a "BYE"
        if ($n % 2 !== 0) $teams[] = 'BYE';
        $n = count($teams);
        $rounds = $n - 1;

        for ($r = 0; $r < $rounds; $r++) {
            for ($i = 0; $i < $n/2; $i++) {
                $home = $teams[$i];
                $away = $teams[$n - 1 - $i];
                if ($home !== 'BYE' && $away !== 'BYE') {
                    $fixtures[] = ['home'=>$home,'away'=>$away,'date'=>$date->format('d M Y')];
                }
            }
            // Rotate
            array_splice($teams, 1, 0, array_pop($teams));
            $date->modify('+7 days');
        }
        echo json_encode(['success'=>true,'fixtures'=>$fixtures,'rounds'=>$rounds]);
        exit;
    }

    if ($action === 'create_season') {
        $leagueName = trim((string)($_POST['league_name'] ?? ''));
        $sportId    = (int)($_POST['sport_id'] ?? 0);
        $startDate  = $_POST['start_date'] ?? '';
        $endDate    = $_POST['end_date']   ?? '';
        $teams      = json_decode($_POST['teams'] ?? '[]', true);
        $matchDay   = (int)($_POST['match_day'] ?? 6);

        if (!$leagueName || !$sportId || !$startDate) {
            echo json_encode(['error'=>'Missing required fields.']); exit;
        }

        // Insert league
        $stmt = $conn->prepare("INSERT INTO leagues (name, sport_id, start_date, end_date, status) VALUES (?,?,?,?,'Active')");
        $stmt->bind_param('siss', $leagueName, $sportId, $startDate, $endDate);
        $stmt->execute();
        $leagueId = $stmt->insert_id;
        $stmt->close();

        // Get team IDs
        $teamIds = [];
        foreach ($teams as $tname) {
            $esc = $conn->real_escape_string($tname);
            $r = $conn->query("SELECT team_id FROM teams WHERE name='$esc' LIMIT 1");
            if ($r && $row = $r->fetch_assoc()) $teamIds[$tname] = $row['team_id'];
        }

        // Generate fixtures
        $teamList = array_keys($teamIds);
        $date = new DateTime($startDate);
        while ((int)$date->format('N') !== $matchDay) $date->modify('+1 day');

        if (count($teamList) % 2 !== 0) $teamList[] = 'BYE';
        $n = count($teamList);
        $inserted = 0;

        for ($round = 0; $round < $n - 1; $round++) {
            for ($i = 0; $i < $n/2; $i++) {
                $home = $teamList[$i];
                $away = $teamList[$n - 1 - $i];
                if (isset($teamIds[$home]) && isset($teamIds[$away])) {
                    $hId = $teamIds[$home];
                    $aId = $teamIds[$away];
                    $d   = $date->format('Y-m-d');
                    $conn->query("INSERT INTO fixtures (league_id,home_team_id,away_team_id,match_date,status)
                                  VALUES ($leagueId,$hId,$aId,'$d','Scheduled')");
                    $inserted++;
                }
            }
            array_splice($teamList, 1, 0, array_pop($teamList));
            $date->modify('+7 days');
        }

        echo json_encode(['success'=>true,'league_id'=>$leagueId,'fixtures_created'=>$inserted]);
        exit;
    }

    echo json_encode(['error'=>'Unknown action.']); exit;
}

$conn->close();
?>

<style>
.wizard-hero { background:linear-gradient(135deg,#4338ca,#6366f1); color:#fff; border-radius:14px; padding:1.75rem 2rem; margin-bottom:1.5rem; }
.wizard-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:2rem; box-shadow:0 4px 20px rgba(0,0,0,.06); }
.step-bar { display:flex; gap:0; margin-bottom:2rem; }
.step-item { flex:1; text-align:center; position:relative; }
.step-item::after { content:''; position:absolute; top:18px; left:50%; width:100%; height:2px; background:#e2e8f0; z-index:0; }
.step-item:last-child::after { display:none; }
.step-circle { width:36px; height:36px; border-radius:50%; border:2px solid #e2e8f0; background:#fff; display:flex; align-items:center; justify-content:center; margin:0 auto .5rem; font-weight:700; font-size:.85rem; position:relative; z-index:1; transition:all .25s; }
.step-item.active .step-circle { background:#4f46e5; border-color:#4f46e5; color:#fff; }
.step-item.done .step-circle   { background:#059669; border-color:#059669; color:#fff; }
.step-label { font-size:.72rem; color:#64748b; font-weight:600; }
.step-item.active .step-label  { color:#4f46e5; }
.step-panel { display:none; }
.step-panel.active { display:block; animation: fadeIn .2s ease-out; }
@keyframes fadeIn { from{opacity:0;transform:translateY(6px)} to{opacity:1;transform:none} }
.team-checkbox { padding:.5rem .75rem; border:1px solid #e2e8f0; border-radius:8px; cursor:pointer; transition:all .15s; }
.team-checkbox:has(input:checked) { border-color:#4f46e5; background:#eff6ff; }
.fixture-row { background:#f8fafc; border-radius:6px; padding:.5rem .75rem; font-size:.85rem; }
.btn-wizard-next { background:linear-gradient(135deg,#4f46e5,#6366f1); color:#fff; border:none; border-radius:10px; padding:.65rem 1.5rem; font-weight:700; }
.btn-wizard-back { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; border-radius:10px; padding:.65rem 1.5rem; font-weight:700; }
</style>

<div style="max-width:800px;margin:0 auto;">
    <div class="wizard-hero">
        <div class="d-flex align-items-center gap-3">
            <div style="font-size:2.5rem;">🗓️</div>
            <div>
                <h2 class="mb-1">Season Setup Wizard</h2>
                <p class="mb-0" style="color:rgba(255,255,255,.75);font-size:.9rem;">Create a new season, league, and auto-generate fixtures in 4 steps</p>
            </div>
        </div>
    </div>

    <!-- Progress Bar -->
    <div class="step-bar">
        <?php foreach (['Season Details','League Setup','Select Teams','Generate Fixtures'] as $i=>$label): ?>
        <div class="step-item <?php echo $i===0?'active':''; ?>" id="step_nav_<?php echo $i; ?>">
            <div class="step-circle"><?php echo $i+1; ?></div>
            <div class="step-label"><?php echo $label; ?></div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="wizard-card">
        <!-- STEP 0: Season Details -->
        <div class="step-panel active" id="panel_0">
            <h5 class="fw-bold mb-3">Season Details</h5>
            <div class="mb-3">
                <label class="form-label fw-semibold">Season / League Name *</label>
                <input type="text" id="leagueName" class="form-control" placeholder="e.g. 2026/27 Football Premier Season">
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Sport *</label>
                <select id="sportId" class="form-select">
                    <option value="">— Select Sport —</option>
                    <?php foreach ($sports as $s): ?>
                    <option value="<?php echo e($s['sport_id']); ?>"><?php echo e($s['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="row g-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">Start Date *</label>
                    <input type="date" id="startDate" class="form-control" value="<?php echo date('Y-m-d',strtotime('+7 days')); ?>">
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">End Date</label>
                    <input type="date" id="endDate" class="form-control" value="<?php echo date('Y-m-d',strtotime('+180 days')); ?>">
                </div>
            </div>
        </div>

        <!-- STEP 1: Match Day -->
        <div class="step-panel" id="panel_1">
            <h5 class="fw-bold mb-3">League Setup</h5>
            <div class="mb-3">
                <label class="form-label fw-semibold">Preferred Match Day</label>
                <select id="matchDay" class="form-select">
                    <option value="6">Saturday</option>
                    <option value="7">Sunday</option>
                    <option value="3">Wednesday</option>
                    <option value="5">Friday</option>
                </select>
            </div>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Fixtures will be auto-generated every week on the selected match day using a round-robin schedule.
            </div>
        </div>

        <!-- STEP 2: Teams -->
        <div class="step-panel" id="panel_2">
            <h5 class="fw-bold mb-3">Select Participating Teams</h5>
            <p class="text-muted small">Select at least 2 teams for round-robin scheduling.</p>
            <div id="teamFiltered" class="alert alert-info py-2 mb-3">Select a sport first to filter teams.</div>
            <div class="d-flex flex-wrap gap-2" id="teamsList">
                <?php foreach ($allTeams as $t): ?>
                <label class="team-checkbox" data-sport="<?php echo e($t['sport_id']); ?>">
                    <input type="checkbox" value="<?php echo e($t['name']); ?>" class="me-2 team-cb">
                    <?php echo e($t['name']); ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="mt-2 small text-muted"><span id="selectedCount">0</span> teams selected</div>
        </div>

        <!-- STEP 3: Preview & Confirm -->
        <div class="step-panel" id="panel_3">
            <h5 class="fw-bold mb-3">Fixture Preview</h5>
            <div id="fixturePreview">
                <div class="text-center py-3 text-muted"><i class="fas fa-spinner fa-spin"></i> Generating preview…</div>
            </div>
            <div id="previewSummary" class="mt-3"></div>
        </div>

        <!-- Navigation -->
        <div class="d-flex justify-content-between mt-4">
            <button class="btn-wizard-back" id="backBtn" onclick="goStep(-1)" style="display:none;">
                <i class="fas fa-arrow-left me-1"></i>Back
            </button>
            <button class="btn-wizard-next ms-auto" id="nextBtn" onclick="goStep(1)">
                Next <i class="fas fa-arrow-right ms-1"></i>
            </button>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1">
  <div class="modal-dialog modal-sm">
    <div class="modal-content text-center p-4">
      <div style="font-size:3rem;">🎉</div>
      <h5 class="fw-bold mt-2">Season Created!</h5>
      <p class="text-muted" id="successMsg"></p>
      <a href="manage_leagues.php" class="btn btn-primary w-100">View Leagues</a>
    </div>
  </div>
</div>

<script>
let currentStep = 0;
const TOTAL = 4;
const SELF = window.location.href.split('?')[0];
const allTeamsData = <?php echo json_encode($allTeams); ?>;

function updateStepUI() {
    document.querySelectorAll('.step-panel').forEach((p,i) => p.classList.toggle('active', i===currentStep));
    document.querySelectorAll('.step-item').forEach((p,i) => {
        p.classList.toggle('active', i===currentStep);
        p.classList.toggle('done', i<currentStep);
    });
    document.getElementById('backBtn').style.display = currentStep > 0 ? '' : 'none';
    const nextBtn = document.getElementById('nextBtn');
    if (currentStep === TOTAL-1) {
        nextBtn.innerHTML = '<i class="fas fa-rocket me-1"></i>Create Season';
        nextBtn.onclick = createSeason;
    } else {
        nextBtn.innerHTML = 'Next <i class="fas fa-arrow-right ms-1"></i>';
        nextBtn.onclick = () => goStep(1);
    }
}

function goStep(dir) {
    // Validate
    if (dir > 0) {
        if (currentStep === 0) {
            if (!document.getElementById('leagueName').value.trim()) { alert('Please enter a league name.'); return; }
            if (!document.getElementById('sportId').value) { alert('Please select a sport.'); return; }
            // Filter teams by sport
            filterTeams();
        }
        if (currentStep === 2) {
            const sel = getSelectedTeams();
            if (sel.length < 2) { alert('Please select at least 2 teams.'); return; }
            // Load preview
            loadPreview();
        }
    }
    currentStep = Math.max(0, Math.min(TOTAL-1, currentStep+dir));
    updateStepUI();
}

function filterTeams() {
    const sid = document.getElementById('sportId').value;
    const labels = document.querySelectorAll('.team-checkbox');
    let visible = 0;
    labels.forEach(l => {
        const show = !sid || l.dataset.sport == sid;
        l.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('teamFiltered').textContent = `${visible} teams available for selected sport`;
}

function getSelectedTeams() {
    return [...document.querySelectorAll('.team-cb:checked')].map(c=>c.value);
}

document.querySelectorAll('.team-cb').forEach(c => {
    c.addEventListener('change', () => {
        document.getElementById('selectedCount').textContent = getSelectedTeams().length;
    });
});

async function loadPreview() {
    const teams = getSelectedTeams();
    const fd = new FormData();
    fd.append('wizard_action','preview_fixtures');
    fd.append('teams', JSON.stringify(teams));
    fd.append('start_date', document.getElementById('startDate').value);
    fd.append('match_day', document.getElementById('matchDay').value);
    const res = await fetch(SELF,{method:'POST',body:fd});
    const data = await res.json();
    const box = document.getElementById('fixturePreview');
    if (data.success) {
        let html = `<div class="alert alert-success py-2 mb-3"><strong>${data.fixtures.length} fixtures</strong> will be generated across <strong>${data.rounds} rounds</strong></div>`;
        html += '<div class="d-flex flex-column gap-1" style="max-height:300px;overflow-y:auto;">';
        data.fixtures.forEach(f => {
            html += `<div class="fixture-row d-flex justify-content-between"><span><strong>${f.home}</strong> vs ${f.away}</span><span class="text-muted">${f.date}</span></div>`;
        });
        html += '</div>';
        box.innerHTML = html;
        document.getElementById('previewSummary').innerHTML = `
            <div class="card border-0 bg-light p-3 mt-2">
                <strong>Summary</strong><br>
                <small>League: <strong>${document.getElementById('leagueName').value}</strong></small><br>
                <small>Teams: <strong>${teams.length}</strong> | Fixtures: <strong>${data.fixtures.length}</strong></small>
            </div>`;
    } else {
        box.innerHTML = `<div class="alert alert-danger">${data.error}</div>`;
    }
}

async function createSeason() {
    const btn = document.getElementById('nextBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating…';
    const fd = new FormData();
    fd.append('wizard_action','create_season');
    fd.append('league_name', document.getElementById('leagueName').value);
    fd.append('sport_id', document.getElementById('sportId').value);
    fd.append('start_date', document.getElementById('startDate').value);
    fd.append('end_date', document.getElementById('endDate').value);
    fd.append('match_day', document.getElementById('matchDay').value);
    fd.append('teams', JSON.stringify(getSelectedTeams()));
    const res = await fetch(SELF,{method:'POST',body:fd});
    const data = await res.json();
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-rocket me-1"></i>Create Season';
    if (data.success) {
        document.getElementById('successMsg').textContent = `${data.fixtures_created} fixtures created successfully!`;
        new bootstrap.Modal(document.getElementById('successModal')).show();
    } else {
        alert('Error: ' + (data.error||'Unknown error.'));
    }
}
</script>

<?php include_once '../includes/footer.php'; ?>
