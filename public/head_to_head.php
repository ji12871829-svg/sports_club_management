<?php
require_once '../config/db_connect.php';
require_once '../includes/head_to_head.php';
require_once '../includes/url.php';
include_once '../includes/header.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$team_a = (int) ($_GET['team_a'] ?? 0);
$team_b = (int) ($_GET['team_b'] ?? 0);
$league_id = (int) ($_GET['league_id'] ?? 0);

$teams = $conn->query('SELECT team_id, name, league_id FROM teams ORDER BY name')->fetch_all(MYSQLI_ASSOC);

// Execute calculation sequence if distinct parameters are provided
$data = ($team_a > 0 && $team_b > 0 && $team_a !== $team_b)
    ? asc_head_to_head($conn, $team_a, $team_b, $league_id > 0 ? $league_id : null)
    : ['matches' => [], 'stats' => ['played' => 0, 'team_a_wins' => 0, 'team_b_wins' => 0, 'draws' => 0, 'team_a_goals' => 0, 'team_b_goals' => 0]];

$name_a = $name_b = '';
foreach ($teams as $t) {
    if ((int) $t['team_id'] === $team_a) $name_a = $t['name'];
    if ((int) $t['team_id'] === $team_b) $name_b = $t['name'];
}
?>

<!-- Corporate Minimalist Design Tokens Layer -->
<style>
:root {
  --primary: #0f172a;
  --accent: #2563eb;
  --ui-border: #e2e8f0;
  --surface-bg: #ffffff;
  --workspace-bg: #f8fafc;
}

body {
  background-color: var(--workspace-bg) !important;
  color: #334155 !important;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}

.workspace-block-card {
  border: 1px solid var(--ui-border);
  border-radius: 12px;
  background: var(--surface-bg);
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
}

.comparison-matrix {
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
  color: #f8fafc;
  border-radius: 12px;
  padding: 2rem;
  border: 1px solid #334155;
}

.metric-divider {
  border-left: 1px solid #334155;
  height: 40px;
}

.match-log-row {
  border: 1px solid var(--ui-border);
  border-radius: 8px;
  background: var(--surface-bg);
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}

.match-log-row:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
  border-color: #cbd5e1;
}

.outcome-badge {
  display: inline-block;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  letter-spacing: 0.5px;
}
.outcome-win { background-color: #dcfce7; color: #15803d; }
.outcome-loss { background-color: #fee2e2; color: #b91c1c; }
.outcome-draw { background-color: #f1f5f9; color: #475569; }
</style>

<div class="container py-5">
  
  <!-- Framework Header Context -->
  <div class="mb-4">
    <h1 class="fw-bold text-dark mb-1" style="letter-spacing: -0.8px;">⚔️ Head-to-Head Analytics</h1>
    <p class="text-muted mb-0 small">Cross-referencing transactional histories and competitive records between specified squad profiles.</p>
  </div>

  <!-- Operational Inputs Form -->
  <div class="workspace-block-card p-4 mb-5">
    <form method="get" class="row g-3 align-items-end">
      <div class="col-md-4">
        <label class="form-label font-monospace text-muted small fw-bold text-uppercase">Primary Node (Team A)</label>
        <select name="team_a" class="form-select bg-white text-dark fw-semibold" style="border-color: var(--ui-border);" required>
          <option value="">-- Select Target A --</option>
          <?php foreach ($teams as $t): ?>
            <option value="<?php echo (int) $t['team_id']; ?>" <?php echo $team_a === (int) $t['team_id'] ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label font-monospace text-muted small fw-bold text-uppercase">Comparative Node (Team B)</label>
        <select name="team_b" class="form-select bg-white text-dark fw-semibold" style="border-color: var(--ui-border);" required>
          <option value="">-- Select Target B --</option>
          <?php foreach ($teams as $t): ?>
            <option value="<?php echo (int) $t['team_id']; ?>" <?php echo $team_b === (int) $t['team_id'] ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <button type="submit" class="btn btn-primary w-100 fw-bold" style="background-color: var(--accent); border: none; padding: 0.58rem 1rem;">
          Compute Match Matrix
        </button>
      </div>
    </form>
  </div>

  <!-- Telemetry Output Interface -->
  <?php if ($team_a && $team_b): ?>
    <?php if ($team_a === $team_b): ?>
      <div class="alert alert-warning border border-warning shadow-sm small p-3" style="border-radius: 8px;">
        <i class="fas fa-exclamation-triangle me-1"></i> Comparative nodes must be unique. Choose two distinct team profiles.
      </div>
    <?php else: ?>
      
      <!-- Summary Metrics Board -->
      <div class="comparison-matrix shadow-sm mb-4">
        <div class="row align-items-center text-center">
          <div class="col-md-4 text-md-end mb-3 mb-md-0">
            <h3 class="fw-bold mb-0 text-white"><?php echo e($name_a); ?></h3>
            <span class="badge bg-primary mt-1 font-monospace" style="font-size:0.75rem;">NODE A</span>
          </div>
          
          <div class="col-md-4">
            <div class="d-flex align-items-center justify-content-center gap-3">
              <div>
                <div class="fs-2 fw-black text-white"><?php echo (int) $data['stats']['team_a_wins']; ?></div>
                <div class="font-monospace text-uppercase text-muted" style="font-size:0.65rem; letter-spacing:1px;">Wins A</div>
              </div>
              <div class="metric-divider"></div>
              <div>
                <div class="fs-2 fw-black text-white-50"><?php echo (int) $data['stats']['draws']; ?></div>
                <div class="font-monospace text-uppercase text-muted" style="font-size:0.65rem; letter-spacing:1px;">Draws</div>
              </div>
              <div class="metric-divider"></div>
              <div>
                <div class="fs-2 fw-black text-white"><?php echo (int) $data['stats']['team_b_wins']; ?></div>
                <div class="font-monospace text-uppercase text-muted" style="font-size:0.65rem; letter-spacing:1px;">Wins B</div>
              </div>
            </div>
          </div>

          <div class="col-md-4 text-md-start mt-3 mt-md-0">
            <h3 class="fw-bold mb-0 text-white"><?php echo e($name_b); ?></h3>
            <span class="badge bg-secondary mt-1 font-monospace" style="font-size:0.75rem;">NODE B</span>
          </div>
        </div>

        <hr style="border-color: #334155; margin: 1.5rem 0;">

        <div class="d-flex justify-content-center gap-4 text-center font-monospace text-uppercase text-muted small" style="font-size: 0.72rem;">
          <div>Deployments: <strong class="text-white"><?php echo (int) $data['stats']['played']; ?></strong></div>
          <div style="color: #334155;">•</div>
          <div>Cumulative Goals: <strong class="text-white"><?php echo (int) $data['stats']['team_a_goals']; ?> – <?php echo (int) $data['stats']['team_b_goals']; ?></strong></div>
        </div>
      </div>

      <!-- Chronological Event Logs -->
      <h5 class="fw-bold text-dark font-monospace text-uppercase mb-3" style="font-size:0.8rem; letter-spacing: 0.5px;">📋 Match History Matrix</h5>
      
      <?php foreach ($data['matches'] as $m): 
        $home_id = (int)$m['home_team_id'];
        $away_id = (int)$m['away_team_id'];
        $home_score = (int)$m['home_score'];
        $away_score = (int)$m['away_score'];

        // Determine indicators contextual to Team A's perspective
        $indicator = '<span class="outcome-badge outcome-draw">D</span>';
        if (($home_id === $team_a && $home_score > $away_score) || ($away_id === $team_a && $away_score > $home_score)) {
            $indicator = '<span class="outcome-badge outcome-win">W</span>';
        } elseif (($home_id === $team_a && $home_score < $away_score) || ($away_id === $team_a && $away_score < $home_score)) {
            $indicator = '<span class="outcome-badge outcome-loss">L</span>';
        }
      ?>
        <div class="match-log-row p-3 mb-2 shadow-sm">
          <div class="row align-items-center">
            <div class="col-2 col-md-1 text-center">
              <?php echo $indicator; ?>
            </div>
            <div class="col-6 col-md-7">
              <span class="<?php echo $home_id === $team_a ? 'fw-bold text-dark' : 'text-secondary'; ?>"><?php echo e($m['home_team']); ?></span>
              <span class="text-muted mx-2">vs</span>
              <span class="<?php echo $away_id === $team_a ? 'fw-bold text-dark' : 'text-secondary'; ?>"><?php echo e($m['away_team']); ?></span>
            </div>
            <div class="col-4 col-md-2 text-end text-md-center">
              <span class="badge font-monospace bg-light text-dark border px-2 py-1 fs-6">
                <?php echo $home_score; ?> – <?php echo $away_score; ?>
              </span>
            </div>
            <div class="col-12 col-md-2 text-md-end mt-2 mt-md-0">
              <small class="text-muted font-monospace" style="font-size: 0.75rem;">
                <i class="far fa-calendar-alt me-1"></i><?php echo e(date('d M Y', strtotime($m['match_date']))); ?>
              </small>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <?php if (empty($data['matches'])): ?>
        <div class="alert alert-info border d-flex align-items-center gap-2 p-4 bg-white shadow-sm" style="border-radius: 8px;">
          <i class="fas fa-info-circle text-accent fs-5"></i>
          <div>
            <strong class="d-block text-dark mb-1">Zero Historical Matrices Found</strong>
            <span class="small text-muted">No competitive fixtures have been registered or logged between these specific nodes inside the current timelines.</span>
          </div>
        </div>
      <?php endif; ?>

    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include_once '../includes/footer.php'; $conn->close(); ?>