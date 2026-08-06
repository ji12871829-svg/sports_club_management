<?php
/**
 * public/player_stats.php
 * Per-member season statistics derived from match_events.
 */
include_once '../includes/header.php';
require_once '../config/db_connect.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

// Filter: league
$league_id = (int)($_GET['league_id'] ?? 0);

$leagues = $conn->query("SELECT league_id, name FROM leagues ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);

if ($league_id === 0 && !empty($leagues)) {
    $league_id = (int)$leagues[0]['league_id'];
}

// Build stats from match_events joined to fixtures
$stats = [];
if ($league_id > 0) {
    // Refactored to parameterized prepared statements to eliminate SQL Injection vulnerability
    $stmt = $conn->prepare("
        SELECT
            COALESCE(m.member_id, 0)                                       AS member_id,
            COALESCE(CONCAT(m.first_name,' ',m.last_name), me.player_name) AS player_name,
            t.name                                                         AS team_name,
            COUNT(CASE WHEN me.event_type IN ('goal','penalty') THEN 1 END) AS goals,
            COUNT(CASE WHEN me.event_type = 'own_goal'          THEN 1 END) AS own_goals,
            COUNT(CASE WHEN me.event_type = 'yellow_card'       THEN 1 END) AS yellow_cards,
            COUNT(CASE WHEN me.event_type = 'red_card'          THEN 1 END) AS red_cards,
            COUNT(DISTINCT me.fixture_id)                                     AS appearances
        FROM match_events me
        JOIN fixtures f  ON f.fixture_id = me.fixture_id
        JOIN teams    t  ON t.team_id    = me.team_id
        LEFT JOIN members m ON m.member_id = me.member_id
        WHERE f.league_id = ?
        GROUP BY me.member_id, me.player_name, t.name
        HAVING goals > 0 OR appearances > 0
        ORDER BY goals DESC, appearances DESC
    ");
    $stmt->bind_param("i", $league_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $stats = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
}

$league_name = '';
foreach ($leagues as $l) {
    if ((int)$l['league_id'] === $league_id) { $league_name = $l['name']; break; }
}
$conn->close();
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

.stats-table {
  margin-bottom: 0;
  font-size: 0.88rem;
}

.stats-table thead th {
  background-color: #f8fafc;
  color: #64748b;
  font-weight: 700;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border-bottom: 1px solid var(--ui-border);
  padding: 0.85rem 1.25rem;
}

.stats-table tbody td {
  padding: 0.95rem 1.25rem;
  color: #334155;
  vertical-align: middle;
  border-bottom: 1px solid #f1f5f9;
}

.stats-table tbody tr:last-child td {
  border-bottom: none;
}

.metric-badge {
  display: inline-block;
  min-width: 32px;
  padding: 0.25rem 0.4rem;
  border-radius: 4px;
  font-weight: 700;
  text-align: center;
  font-size: 0.82rem;
}
.badge-goals { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.badge-yellow { background: #fef9c3; color: #713f12; border: 1px solid #fef08a; }
.badge-red { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
.badge-apps { background: #f1f5f9; color: var(--primary); border: 1px solid var(--ui-border); }

.rank-pill {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  font-weight: 600;
  font-size: 0.75rem;
  color: #64748b;
  background: #f1f5f9;
  border-radius: 4px;
}
</style>

<div class="container py-5">
    
    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold text-dark mb-1" style="letter-spacing: -0.8px;">📊 Athlete Performance Registry</h2>
            <p class="text-muted mb-0 small">Aggregated tactical performance metrics derived directly from validated match event streams.</p>
        </div>

        <form method="GET" class="m-0 d-flex gap-2 align-items-center">
            <select name="league_id" class="form-select form-select-sm bg-white font-monospace text-dark fw-semibold" style="min-width: 220px; border-color: var(--ui-border); padding: 0.45rem 2rem 0.45rem 0.75rem;" onchange="this.form.submit()">
                <?php foreach ($leagues as $l): ?>
                    <option value="<?php echo e($l['league_id']); ?>"
                        <?php echo (int)$l['league_id'] === $league_id ? 'selected' : ''; ?>>
                        🏆 <?php echo e($l['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="badge bg-light text-dark border font-monospace px-2 py-2" style="font-size:0.75rem; border-color: var(--ui-border) !important;"><?php echo count($stats); ?> Nodes Tracked</span>
        </form>
    </div>

    <?php if (empty($stats)): ?>
        <div class="alert alert-info border d-flex align-items-center gap-2 p-4 bg-white shadow-sm" style="border-radius: 8px;">
            <i class="fas fa-info-circle text-accent fs-5"></i>
            <div>
                <strong class="d-block text-dark mb-1">No Telemetry Recorded</strong>
                <span class="small text-muted">No historical match events mapped to this competition timeline. Event logs require operational clearance via <strong>Admin Dashboard → Goals &amp; Cards Log</strong>.</span>
            </div>
        </div>
    <?php else: ?>

    <div class="table-responsive workspace-block-card">
        <table class="table stats-table table-hover">
            <thead>
                <tr>
                    <th class="text-center" style="width: 60px;">#</th>
                    <th>Athlete Profile</th>
                    <th>Squad Assignment</th>
                    <th class="text-center" style="width: 90px;">⚽ Goals</th>
                    <th class="text-center" style="width: 90px;">🟡 Cautions</th>
                    <th class="text-center" style="width: 90px;">🔴 Dismissals</th>
                    <th class="text-center" style="width: 90px;">📅 Deployments</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($stats as $i => $s): ?>
                <tr>
                    <td class="text-center">
                        <span class="rank-pill"><?php echo $i + 1; ?></span>
                    </td>
                    <td>
                        <?php if ($s['member_id'] > 0): ?>
                            <a href="member_profile.php?id=<?php echo e($s['member_id']); ?>" class="fw-bold text-accent text-decoration-none">
                                <?php echo e($s['player_name']); ?>
                            </a>
                        <?php else: ?>
                            <span class="fw-bold text-dark"><?php echo e($s['player_name']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fw-semibold text-secondary small"><?php echo e($s['team_name']); ?></span>
                    </td>
                    <td class="text-center">
                        <span class="metric-badge badge-goals"><?php echo (int)$s['goals']; ?></span>
                    </td>
                    <td class="text-center">
                        <span class="metric-badge badge-yellow"><?php echo (int)$s['yellow_cards']; ?></span>
                    </td>
                    <td class="text-center">
                        <span class="metric-badge badge-red"><?php echo (int)$s['red_cards']; ?></span>
                    </td>
                    <td class="text-center">
                        <span class="metric-badge badge-apps"><?php echo (int)$s['appearances']; ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include_once '../includes/footer.php'; ?>