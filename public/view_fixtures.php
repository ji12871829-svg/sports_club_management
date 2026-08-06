<?php
session_start();
require_once "../config/db_connect.php";
include_once "../includes/header.php";

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Active league filter
$selected_league = (int)($_GET['league_id'] ?? 0);

// Fetch all leagues for the selector
$leagues = $conn->query(
    "SELECT l.league_id, l.name AS league_name, l.season, s.name AS sport_name
     FROM leagues l JOIN sports s ON s.sport_id = l.sport_id
     ORDER BY s.name, l.name"
)->fetch_all(MYSQLI_ASSOC);

if ($selected_league === 0 && !empty($leagues)) {
    $selected_league = (int)$leagues[0]['league_id'];
}

// Active league meta
$active_league = null;
foreach ($leagues as $lg) {
    if ((int)$lg['league_id'] === $selected_league) { $active_league = $lg; break; }
}

// Standings for active league
$standings = [];
if ($selected_league > 0) {
    $res = $conn->query(
        "SELECT st.played, st.won, st.drawn, st.lost,
                st.goals_for, st.goals_against, st.goal_diff, st.points,
                t.name AS team_name, t.short_name
         FROM standings st
         JOIN teams t ON t.team_id = st.team_id
         WHERE st.league_id = $selected_league
         ORDER BY st.points DESC, st.goal_diff DESC, st.goals_for DESC, t.name"
    );
    while ($row = $res->fetch_assoc()) $standings[] = $row;
}

// Fixtures for active league — split into upcoming & results
$upcoming = $results = [];
if ($selected_league > 0) {
    $res = $conn->query(
        "SELECT f.fixture_id, f.match_date, f.match_time, f.venue, f.matchday,
                f.status, f.home_score, f.away_score,
                h.name AS home_team, a.name AS away_team
         FROM fixtures f
         JOIN teams h ON h.team_id = f.home_team_id
         JOIN teams a ON a.team_id = f.away_team_id
         WHERE f.league_id = $selected_league
         ORDER BY f.match_date ASC, f.matchday ASC"
    );
    while ($row = $res->fetch_assoc()) {
        if ($row['status'] === 'Completed') $results[] = $row;
        else $upcoming[] = $row;
    }
    // Show latest results first
    $results = array_reverse($results);
}

$conn->close();
?>

<style>
:root {
  --primary: #14497a;
  --accent:  #16a34a;
  --gold:    #f59e0b;
}
.league-pill {
  display: inline-block;
  padding: .35rem .85rem;
  border-radius: 50px;
  font-size: .85rem;
  font-weight: 600;
  border: 2px solid transparent;
  cursor: pointer;
  transition: all .2s;
  text-decoration: none;
  color: #374151;
  background: #f3f4f6;
}
.league-pill:hover, .league-pill.active {
  background: var(--primary);
  color: #fff;
  border-color: var(--primary);
}
.section-title {
  font-size: 1.2rem;
  font-weight: 700;
  border-left: 4px solid var(--primary);
  padding-left: .75rem;
  margin-bottom: 1.2rem;
}
/* Standings table */
.standings-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
.pos-1 { background: linear-gradient(90deg,#dcfce7,#fff) !important; }
.pos-2 { background: linear-gradient(90deg,#d3e4f2,#fff) !important; }
.pos-3 { background: linear-gradient(90deg,#fef9c3,#fff) !important; }
.pts-badge {
  display: inline-block;
  min-width: 36px;
  padding: .2rem .5rem;
  border-radius: 6px;
  font-weight: 700;
  background: var(--primary);
  color: #fff;
  text-align: center;
}
/* Fixture card */
.fixture-card {
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 1rem 1.25rem;
  margin-bottom: .75rem;
  display: flex;
  align-items: center;
  gap: .75rem;
  transition: box-shadow .2s, transform .2s;
  background: #fff;
}
.fixture-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,.1); transform: translateY(-2px); }
.team-name { font-weight: 600; font-size: .95rem; flex: 1; }
.team-name.home { text-align: right; }
.team-name.away { text-align: left; }
.score-block {
  min-width: 80px;
  text-align: center;
  font-size: 1.3rem;
  font-weight: 800;
  color: #111;
}
.score-block small { display: block; font-size: .7rem; font-weight: 400; color: #6b7280; }
.badge-scheduled { background: #2a6ba8; }
.badge-postponed { background: #f59e0b; }
.badge-cancelled { background: #ef4444; }
.matchday-tag {
  font-size: .7rem;
  color: #6b7280;
  background: #f3f4f6;
  border-radius: 4px;
  padding: 1px 6px;
}
</style>

<div class="container py-4">

  <!-- Page title -->
  <div class="d-flex align-items-center gap-3 mb-4">
    <div>
      <h1 class="mb-0 fw-bold">🏆 Fixtures &amp; Standings</h1>
      <p class="text-muted mb-0">Live league tables and match schedule</p>
    </div>
  </div>

  <!-- League selector pills -->
  <div class="mb-4 d-flex flex-wrap gap-2">
    <?php foreach ($leagues as $lg): ?>
      <a href="?league_id=<?php echo e($lg['league_id']); ?>"
         class="league-pill <?php echo (int)$lg['league_id'] === $selected_league ? 'active' : ''; ?>">
        <?php echo e($lg['sport_name'].' — '.$lg['league_name']); ?>
      </a>
    <?php endforeach; ?>
  </div>

  <?php if (!$active_league): ?>
    <div class="alert alert-info">No leagues available yet.</div>
  <?php else: ?>

  <div class="row g-4">

    <!-- ══ LEFT: Standings Table ══════════════════════════════ -->
    <div class="col-lg-5">
      <div class="card shadow-sm border-0">
        <div class="card-header bg-white border-bottom">
          <div class="section-title mb-0">📊 League Table</div>
          <small class="text-muted">
            <?php echo e($active_league['sport_name'].' — '.$active_league['league_name'].' '.$active_league['season']); ?>
          </small>
        </div>
        <div class="card-body p-0">
          <?php if (empty($standings)): ?>
            <p class="p-3 text-muted mb-0">No results recorded yet.</p>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table standings-table table-hover mb-0">
                <thead class="table-dark">
                  <tr>
                    <th class="text-center">#</th>
                    <th>Team</th>
                    <th class="text-center" title="Played">P</th>
                    <th class="text-center" title="Won">W</th>
                    <th class="text-center" title="Drawn">D</th>
                    <th class="text-center" title="Lost">L</th>
                    <th class="text-center" title="Goal Difference">GD</th>
                    <th class="text-center" title="Points">Pts</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($standings as $pos => $st): ?>
                    <tr class="<?php echo $pos === 0 ? 'pos-1' : ($pos === 1 ? 'pos-2' : ($pos === 2 ? 'pos-3' : '')); ?>">
                      <td class="text-center fw-bold">
                        <?php if ($pos === 0): ?>🥇
                        <?php elseif ($pos === 1): ?>🥈
                        <?php elseif ($pos === 2): ?>🥉
                        <?php else: echo $pos + 1; endif; ?>
                      </td>
                      <td>
                        <span class="fw-semibold"><?php echo e($st['team_name']); ?></span>
                        <?php if ($st['short_name']): ?>
                          <br><small class="text-muted"><?php echo e($st['short_name']); ?></small>
                        <?php endif; ?>
                      </td>
                      <td class="text-center"><?php echo e($st['played']); ?></td>
                      <td class="text-center text-success fw-semibold"><?php echo e($st['won']); ?></td>
                      <td class="text-center text-secondary"><?php echo e($st['drawn']); ?></td>
                      <td class="text-center text-danger"><?php echo e($st['lost']); ?></td>
                      <td class="text-center"><?php echo ($st['goal_diff'] >= 0 ? '+' : '') . e($st['goal_diff']); ?></td>
                      <td class="text-center"><span class="pts-badge"><?php echo e($st['points']); ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="px-3 py-2 bg-light border-top">
              <small class="text-muted">W=3pts &nbsp;·&nbsp; D=1pt &nbsp;·&nbsp; L=0pts</small>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- ══ RIGHT: Fixtures & Results ══════════════════════════ -->
    <div class="col-lg-7">

      <!-- Upcoming fixtures -->
      <div class="section-title">📅 Upcoming Fixtures</div>
      <?php if (empty($upcoming)): ?>
        <p class="text-muted">No upcoming fixtures scheduled.</p>
      <?php else: ?>
        <?php foreach ($upcoming as $f): ?>
          <div class="fixture-card">
            <div class="team-name home"><?php echo e($f['home_team']); ?></div>
            <div class="score-block">
              <span class="badge badge-<?php echo strtolower(e($f['status'])); ?> text-white px-2 py-1 rounded">
                <?php echo e($f['status']); ?>
              </span>
              <small><?php echo e(date('d M', strtotime($f['match_date']))); ?>
                     · <?php echo e(date('H:i', strtotime($f['match_time']))); ?></small>
            </div>
            <div class="team-name away"><?php echo e($f['away_team']); ?></div>
            <?php if ($f['venue']): ?>
              <div class="d-none d-md-block text-muted small text-end" style="min-width:90px">
                📍 <?php echo e($f['venue']); ?>
              </div>
            <?php endif; ?>
            <span class="matchday-tag">MD <?php echo e($f['matchday']); ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Recent results -->
      <div class="section-title mt-4">✅ Recent Results</div>
      <?php if (empty($results)): ?>
        <p class="text-muted">No results recorded yet.</p>
      <?php else: ?>
        <?php foreach ($results as $f): ?>
          <div class="fixture-card">
            <div class="team-name home"><?php echo e($f['home_team']); ?></div>
            <div class="score-block">
              <?php echo e($f['home_score'].' – '.$f['away_score']); ?>
              <small><?php echo e(date('d M Y', strtotime($f['match_date']))); ?></small>
            </div>
            <div class="team-name away"><?php echo e($f['away_team']); ?></div>
            <span class="matchday-tag">MD <?php echo e($f['matchday']); ?></span>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

    </div><!-- /col -->
  </div><!-- /row -->

  <?php endif; ?>
</div><!-- /container -->

<?php include_once "../includes/footer.php"; ?>
