<?php
require_once '../config/db_connect.php';
require_once '../includes/match_events.php';
require_once '../includes/url.php';
include_once '../includes/header.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$league_id = (int) ($_GET['league_id'] ?? 0);
$leagues = $conn->query("SELECT league_id, name, season FROM leagues ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$scorers = asc_top_scorers($conn, $league_id > 0 ? $league_id : null, 50);
?>

<style>
:root {
  --primary: #0f172a;
  --accent: #1d5c8f;
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
  overflow: hidden;
}

.scorers-table {
  margin-bottom: 0;
  font-size: 0.88rem;
}

.scorers-table thead th {
  background-color: #f8fafc;
  color: #64748b;
  font-weight: 700;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border-bottom: 1px solid var(--ui-border);
  padding: 0.85rem 1.25rem;
}

.scorers-table tbody td {
  padding: 0.95rem 1.25rem;
  color: #334155;
  vertical-align: middle;
  border-bottom: 1px solid #f1f5f9;
}

.scorers-table tbody tr:last-child td {
  border-bottom: none;
}

.rank-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  font-weight: 700;
  font-size: 0.75rem;
  color: #64748b;
  background: #f1f5f9;
  border-radius: 4px;
}

.rank-top-1 { background-color: #fef9c3; color: #a16207; }
.rank-top-2 { background-color: #f1f5f9; color: #475569; }
.rank-top-3 { background-color: #ffedd5; color: #c2410c; }

.goals-badge {
  display: inline-block;
  min-width: 36px;
  padding: 0.3rem 0.5rem;
  border-radius: 6px;
  font-weight: 800;
  background: #e8f1f8;
  color: var(--accent);
  text-align: center;
  font-size: 0.88rem;
  border: 1px solid #d3e4f2;
}
</style>

<div class="container py-5">
  
  <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
    <div>
      <h1 class="fw-bold text-dark mb-1" style="letter-spacing: -0.8px;">⚽ Performance Metrics: Top Scorers</h1>
      <p class="text-muted mb-0 small">Aggregated evaluation logs reflecting player efficiency and scoring operations across registered league systems.</p>
    </div>

    <form method="get" class="m-0">
      <select name="league_id" class="form-select form-select-sm bg-white font-monospace text-dark fw-semibold" style="min-width: 220px; border-color: var(--ui-border); padding: 0.45rem 2rem 0.45rem 0.75rem;" onchange="this.form.submit()">
        <option value="0">📊 System Scope: All Leagues</option>
        <?php foreach ($leagues as $lg): ?>
          <option value="<?php echo (int) $lg['league_id']; ?>" <?php echo $league_id === (int) $lg['league_id'] ? 'selected' : ''; ?>>
            🏆 <?php echo e($lg['name'] . ' (' . $lg['season'] . ')'); ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (!asc_match_events_ready($conn)): ?>
    <div class="alert alert-warning border-warning d-flex align-items-center gap-2 p-4" style="border-radius: 8px;">
      <i class="fas fa-exclamation-triangle text-warning fs-5"></i>
      <div>
        <strong class="d-block text-dark mb-1">Database Migrations Required</strong>
        <span class="small text-muted">Execute transaction package <code>011_competition_and_ops_features.sql</code> to provision the goal telemetry schemas.</span>
      </div>
    </div>
  <?php elseif (empty($scorers)): ?>
    <div class="alert alert-info border d-flex align-items-center gap-2 p-4 bg-white shadow-sm" style="border-radius: 8px;">
      <i class="fas fa-info-circle text-accent fs-5"></i>
      <div>
        <strong class="d-block text-dark mb-1">No Statistics Available</strong>
        <span class="small text-muted">No goal records matched the current selection. Telemetry records populate live as match event logs stream into the pipeline.</span>
      </div>
    </div>
  <?php else: ?>
    
    <div class="table-responsive workspace-block-card">
      <table class="table scorers-table table-hover">
        <thead>
          <tr>
            <th class="text-center" style="width: 70px;">Rank</th>
            <th>Athlete Profile</th>
            <th>Squad Assignment</th>
            <th>Competition Scope</th>
            <th class="text-center" style="width: 100px;">Goals Logged</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($scorers as $i => $s): 
            $rank = $i + 1;
            $rank_class = $rank === 1 ? 'rank-top-1' : ($rank === 2 ? 'rank-top-2' : ($rank === 3 ? 'rank-top-3' : ''));
          ?>
            <tr>
              <td class="text-center">
                <span class="rank-pill <?php echo $rank_class; ?>">
                  <?php echo $rank; ?>
                </span>
              </td>
              <td>
                <span class="fw-bold text-dark"><?php echo e($s['player_name']); ?></span>
              </td>
              <td>
                <span class="fw-semibold text-secondary small"><?php echo e($s['team_name']); ?></span>
              </td>
              <td>
                <span class="badge bg-light text-dark border font-monospace text-uppercase" style="font-size: 0.68rem; padding: 0.3rem 0.5rem;">
                  <?php echo e($s['league_name']); ?>
                </span>
              </td>
              <td class="text-center">
                <span class="goals-badge"><?php echo (int) $s['goals']; ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php include_once '../includes/footer.php'; $conn->close(); ?>