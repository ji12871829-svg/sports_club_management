<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Fetch all leagues with their standings
$leagues = $conn->query(
    "SELECT l.league_id, l.name AS league_name, l.season, s.name AS sport_name
     FROM leagues l JOIN sports s ON s.sport_id = l.sport_id
     ORDER BY s.name, l.name"
)->fetch_all(MYSQLI_ASSOC);

$standings_by_league = [];
$result = $conn->query(
    "SELECT st.league_id, st.played, st.won, st.drawn, st.lost,
            st.goals_for, st.goals_against, st.goal_diff, st.points,
            t.name AS team_name, t.short_name
     FROM standings st
     JOIN teams t ON t.team_id = st.team_id
     ORDER BY st.league_id, st.points DESC, st.goal_diff DESC, st.goals_for DESC, t.name"
);
while ($row = $result->fetch_assoc()) {
    $standings_by_league[$row['league_id']][] = $row;
}
$conn->close();
?>

<div class="row">
  <div class="col-md-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="mb-0">📊 League Standings</h2>
        <a href="manage_fixtures.php" class="btn btn-outline-primary btn-sm">🏆 Manage Fixtures</a>
      </div>
      <div class="card-body">

        <?php if (empty($leagues)): ?>
          <p class="text-muted">No leagues found. Please run <code>php scripts/migrate.php</code> first.</p>
        <?php endif; ?>

        <!-- Tab nav — one tab per league -->
        <ul class="nav nav-tabs flex-wrap mb-4" id="standingsTabs" role="tablist">
          <?php foreach ($leagues as $i => $lg): ?>
            <li class="nav-item" role="presentation">
              <button class="nav-link <?php echo $i === 0 ? 'active' : ''; ?>"
                      id="tab-btn-<?php echo e($lg['league_id']); ?>"
                      data-bs-toggle="tab"
                      data-bs-target="#tab-<?php echo e($lg['league_id']); ?>"
                      type="button" role="tab">
                <?php echo e($lg['sport_name'].' – '.$lg['league_name']); ?>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>

        <div class="tab-content">
          <?php foreach ($leagues as $i => $lg): ?>
            <?php $rows = $standings_by_league[$lg['league_id']] ?? []; ?>
            <div class="tab-pane fade <?php echo $i === 0 ? 'show active' : ''; ?>"
                 id="tab-<?php echo e($lg['league_id']); ?>" role="tabpanel">

              <h5 class="mb-3">
                <?php echo e($lg['sport_name'].' — '.$lg['league_name'].' ('.$lg['season'].')'); ?>
              </h5>

              <?php if (empty($rows)): ?>
                <p class="text-muted">No standings data yet. Record match results in
                  <a href="manage_fixtures.php">Manage Fixtures</a>.</p>
              <?php else: ?>
                <div class="table-responsive">
                  <table class="table table-hover table-bordered align-middle">
                    <thead class="table-dark">
                      <tr>
                        <th class="text-center">#</th>
                        <th>Team</th>
                        <th class="text-center" title="Played">P</th>
                        <th class="text-center" title="Won">W</th>
                        <th class="text-center" title="Drawn">D</th>
                        <th class="text-center" title="Lost">L</th>
                        <th class="text-center" title="Goals For">GF</th>
                        <th class="text-center" title="Goals Against">GA</th>
                        <th class="text-center" title="Goal Difference">GD</th>
                        <th class="text-center" title="Points"><strong>Pts</strong></th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($rows as $pos => $st): ?>
                        <?php
                          $rank = $pos + 1;
                          $rowClass = '';
                          if ($rank === 1) $rowClass = 'table-success';
                          elseif ($rank <= 3) $rowClass = 'table-info';
                        ?>
                        <tr class="<?php echo $rowClass; ?>">
                          <td class="text-center fw-bold"><?php echo $rank; ?></td>
                          <td>
                            <?php echo e($st['team_name']); ?>
                            <?php if ($st['short_name']): ?>
                              <small class="text-muted">(<?php echo e($st['short_name']); ?>)</small>
                            <?php endif; ?>
                          </td>
                          <td class="text-center"><?php echo e($st['played']); ?></td>
                          <td class="text-center"><?php echo e($st['won']); ?></td>
                          <td class="text-center"><?php echo e($st['drawn']); ?></td>
                          <td class="text-center"><?php echo e($st['lost']); ?></td>
                          <td class="text-center"><?php echo e($st['goals_for']); ?></td>
                          <td class="text-center"><?php echo e($st['goals_against']); ?></td>
                          <td class="text-center"><?php echo ($st['goal_diff'] > 0 ? '+' : '') . e($st['goal_diff']); ?></td>
                          <td class="text-center fw-bold text-primary"><?php echo e($st['points']); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
                <small class="text-muted">
                  🟢 Champions &nbsp; 🔵 Top 3 &nbsp;
                  Points: Win=3, Draw=1, Loss=0
                </small>
              <?php endif; ?>

            </div>
          <?php endforeach; ?>
        </div>

      </div>
    </div>
  </div>
</div>

<?php include_once("../includes/footer.php"); ?>
