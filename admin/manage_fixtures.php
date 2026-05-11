<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

if (empty($_SESSION['fixtures_csrf'])) {
    $_SESSION['fixtures_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['fixtures_csrf'];
$message = '';

// ── POST HANDLER ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $posted)) {
        $message = '<div class="alert alert-danger">Security check failed.</div>';
    } else {
        $action = $_POST['action'] ?? '';

        // ── ADD FIXTURE ──────────────────────────────────────────────────────
        if ($action === 'add_fixture') {
            $league_id    = (int)($_POST['league_id']    ?? 0);
            $home_team_id = (int)($_POST['home_team_id'] ?? 0);
            $away_team_id = (int)($_POST['away_team_id'] ?? 0);
            $match_date   = trim($_POST['match_date']    ?? '');
            $match_time   = trim($_POST['match_time']    ?? '15:00');
            $venue        = trim($_POST['venue']         ?? '');
            $matchday     = max(1, (int)($_POST['matchday'] ?? 1));

            if ($league_id <= 0 || $home_team_id <= 0 || $away_team_id <= 0 || $match_date === '') {
                $message = '<div class="alert alert-danger">Please fill all required fields.</div>';
            } elseif ($home_team_id === $away_team_id) {
                $message = '<div class="alert alert-danger">Home and away teams must be different.</div>';
            } else {
                $sql = "INSERT INTO fixtures (league_id, home_team_id, away_team_id, match_date, match_time, venue, matchday)
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("iiisssi", $league_id, $home_team_id, $away_team_id,
                                                $match_date, $match_time, $venue, $matchday);
                    if ($stmt->execute()) {
                        $message = '<div class="alert alert-success">Fixture scheduled successfully.</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Could not save fixture: ' . e($conn->error) . '</div>';
                    }
                    $stmt->close();
                }
            }
        }

        // ── RECORD RESULT ────────────────────────────────────────────────────
        if ($action === 'record_result') {
            $fixture_id  = (int)($_POST['fixture_id']  ?? 0);
            $home_score  = (int)($_POST['home_score']  ?? 0);
            $away_score  = (int)($_POST['away_score']  ?? 0);
            $status      = $_POST['result_status'] ?? 'Completed';
            $allowed_statuses = ['Completed','Postponed','Cancelled'];
            if (!in_array($status, $allowed_statuses, true)) $status = 'Completed';

            if ($fixture_id <= 0) {
                $message = '<div class="alert alert-danger">Invalid fixture selected.</div>';
            } else {
                // Fetch the fixture
                $fixture = null;
                $stmt = $conn->prepare("SELECT * FROM fixtures WHERE fixture_id = ? LIMIT 1");
                $stmt->bind_param("i", $fixture_id);
                $stmt->execute();
                $fixture = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$fixture) {
                    $message = '<div class="alert alert-danger">Fixture not found.</div>';
                } else {
                    // Update fixture
                    $sql = "UPDATE fixtures SET status=?, home_score=?, away_score=?, updated_at=NOW() WHERE fixture_id=?";
                    $stmt = $conn->prepare($sql);
                    $hs = ($status === 'Completed') ? $home_score : null;
                    $as_ = ($status === 'Completed') ? $away_score : null;
                    $stmt->bind_param("siii", $status, $hs, $as_, $fixture_id);
                    $stmt->execute();
                    $stmt->close();

                    // Recalculate standings for this league
                    if ($status === 'Completed') {
                        recalculate_standings($conn, (int)$fixture['league_id']);
                    }
                    $message = '<div class="alert alert-success">Result recorded and standings updated.</div>';
                }
            }
        }

        // ── DELETE FIXTURE ───────────────────────────────────────────────────
        if ($action === 'delete_fixture') {
            $fixture_id = (int)($_POST['fixture_id'] ?? 0);
            if ($fixture_id > 0) {
                // Get league before deleting
                $row = $conn->query("SELECT league_id FROM fixtures WHERE fixture_id=$fixture_id")->fetch_assoc();
                $stmt = $conn->prepare("DELETE FROM fixtures WHERE fixture_id=?");
                $stmt->bind_param("i", $fixture_id);
                if ($stmt->execute()) {
                    if ($row) recalculate_standings($conn, (int)$row['league_id']);
                    $message = '<div class="alert alert-success">Fixture deleted.</div>';
                }
                $stmt->close();
            }
        }
    }
}

// ── RECALCULATE STANDINGS ────────────────────────────────────────────────────
function recalculate_standings(mysqli $conn, int $league_id): void {
    // Reset standings for this league
    $conn->query("UPDATE standings SET played=0,won=0,drawn=0,lost=0,goals_for=0,goals_against=0,goal_diff=0,points=0
                  WHERE league_id=$league_id");

    // Ensure every team in the league has a standings row
    $conn->query("INSERT IGNORE INTO standings (league_id,team_id)
                  SELECT league_id,team_id FROM teams WHERE league_id=$league_id");

    // Fetch all completed fixtures for this league
    $result = $conn->query("SELECT home_team_id,away_team_id,home_score,away_score
                            FROM fixtures
                            WHERE league_id=$league_id AND status='Completed'
                              AND home_score IS NOT NULL AND away_score IS NOT NULL");
    while ($f = $result->fetch_assoc()) {
        $h = (int)$f['home_team_id'];
        $a = (int)$f['away_team_id'];
        $hs = (int)$f['home_score'];
        $as_ = (int)$f['away_score'];

        if ($hs > $as_) {
            // Home wins
            update_standing($conn, $league_id, $h, $hs, $as_, 'W');
            update_standing($conn, $league_id, $a, $as_, $hs, 'L');
        } elseif ($as_ > $hs) {
            // Away wins
            update_standing($conn, $league_id, $h, $hs, $as_, 'L');
            update_standing($conn, $league_id, $a, $as_, $hs, 'W');
        } else {
            // Draw
            update_standing($conn, $league_id, $h, $hs, $as_, 'D');
            update_standing($conn, $league_id, $a, $as_, $hs, 'D');
        }
    }
}

function update_standing(mysqli $conn, int $lid, int $tid, int $gf, int $ga, string $result): void {
    $pts = ($result === 'W') ? 3 : (($result === 'D') ? 1 : 0);
    $w   = ($result === 'W') ? 1 : 0;
    $d   = ($result === 'D') ? 1 : 0;
    $l   = ($result === 'L') ? 1 : 0;
    $conn->query("UPDATE standings SET
                    played        = played + 1,
                    won           = won   + $w,
                    drawn         = drawn + $d,
                    lost          = lost  + $l,
                    goals_for     = goals_for     + $gf,
                    goals_against = goals_against + $ga,
                    goal_diff     = goal_diff + ($gf - $ga),
                    points        = points + $pts
                  WHERE league_id=$lid AND team_id=$tid");
}

// ── FETCH DATA ────────────────────────────────────────────────────────────────
$leagues = $conn->query("SELECT l.league_id, l.name, l.season, s.name AS sport_name
                         FROM leagues l JOIN sports s ON s.sport_id=l.sport_id
                         ORDER BY s.name, l.name")->fetch_all(MYSQLI_ASSOC);

$all_teams = $conn->query("SELECT t.team_id, t.name, t.league_id, l.name AS league_name, s.name AS sport_name
                           FROM teams t
                           JOIN leagues l ON l.league_id=t.league_id
                           JOIN sports s ON s.sport_id=t.sport_id
                           ORDER BY s.name, l.name, t.name")->fetch_all(MYSQLI_ASSOC);

// Group teams by league for JS filtering
$teams_by_league = [];
foreach ($all_teams as $t) {
    $teams_by_league[$t['league_id']][] = $t;
}

$fixtures = $conn->query(
    "SELECT f.fixture_id, f.match_date, f.match_time, f.venue, f.matchday,
            f.status, f.home_score, f.away_score,
            h.name AS home_team, a.name AS away_team,
            l.name AS league_name, s.name AS sport_name
     FROM fixtures f
     JOIN teams h   ON h.team_id=f.home_team_id
     JOIN teams a   ON a.team_id=f.away_team_id
     JOIN leagues l ON l.league_id=f.league_id
     JOIN sports s  ON s.sport_id=l.sport_id
     ORDER BY f.match_date DESC, f.matchday DESC, f.fixture_id DESC"
)->fetch_all(MYSQLI_ASSOC);

$pending_fixtures = array_filter($fixtures, fn($f) => $f['status'] === 'Scheduled');

$conn->close();
?>

<style>
.badge-scheduled  { background:#3b82f6; }
.badge-completed  { background:#22c55e; }
.badge-postponed  { background:#f59e0b; }
.badge-cancelled  { background:#ef4444; }
.score-box { font-weight:700; font-size:1.1rem; }
</style>

<div class="row">
  <div class="col-md-12">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="mb-0">🏆 Fixtures &amp; Results</h2>
        <a href="manage_standings.php" class="btn btn-outline-primary btn-sm">📊 View Standings Table</a>
      </div>
      <div class="card-body">
        <?php echo $message; ?>

        <!-- ── TAB NAV ─────────────────────────────────────── -->
        <ul class="nav nav-tabs mb-4" id="fixtureTabs">
          <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-add">➕ Schedule Fixture</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-result">✏️ Record Result</a></li>
          <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-all">📋 All Fixtures</a></li>
        </ul>
        <div class="tab-content">

          <!-- ══ TAB 1: Schedule Fixture ══════════════════════ -->
          <div class="tab-pane fade show active" id="tab-add">
            <div class="row">
              <div class="col-md-7">
                <h5>Schedule a New Fixture</h5>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                  <input type="hidden" name="action" value="add_fixture">

                  <div class="mb-3">
                    <label class="form-label">League *</label>
                    <select name="league_id" id="fx_league" class="form-select" required>
                      <option value="">Select league</option>
                      <?php foreach ($leagues as $lg): ?>
                        <option value="<?php echo e($lg['league_id']); ?>">
                          <?php echo e($lg['sport_name'].' — '.$lg['name'].' ('.$lg['season'].')'); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="row">
                    <div class="col-md-6 mb-3">
                      <label class="form-label">Home Team *</label>
                      <select name="home_team_id" id="fx_home" class="form-select" required>
                        <option value="">Select league first</option>
                      </select>
                    </div>
                    <div class="col-md-6 mb-3">
                      <label class="form-label">Away Team *</label>
                      <select name="away_team_id" id="fx_away" class="form-select" required>
                        <option value="">Select league first</option>
                      </select>
                    </div>
                  </div>

                  <div class="row">
                    <div class="col-md-4 mb-3">
                      <label class="form-label">Match Date *</label>
                      <input type="date" name="match_date" class="form-control" required>
                    </div>
                    <div class="col-md-4 mb-3">
                      <label class="form-label">Kick-off Time</label>
                      <input type="time" name="match_time" class="form-control" value="15:00">
                    </div>
                    <div class="col-md-4 mb-3">
                      <label class="form-label">Matchday / Round</label>
                      <input type="number" name="matchday" class="form-control" value="1" min="1">
                    </div>
                  </div>

                  <div class="mb-3">
                    <label class="form-label">Venue</label>
                    <input type="text" name="venue" class="form-control" placeholder="e.g. Main Stadium">
                  </div>

                  <button type="submit" class="btn btn-primary">📅 Schedule Fixture</button>
                </form>
              </div>
            </div>
          </div>

          <!-- ══ TAB 2: Record Result ══════════════════════════ -->
          <div class="tab-pane fade" id="tab-result">
            <div class="row">
              <div class="col-md-7">
                <h5>Record Match Result</h5>
                <?php if (count($pending_fixtures) === 0): ?>
                  <p class="text-muted">No scheduled fixtures to update.</p>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                    <input type="hidden" name="action" value="record_result">

                    <div class="mb-3">
                      <label class="form-label">Select Fixture *</label>
                      <select name="fixture_id" class="form-select" required>
                        <option value="">Choose fixture</option>
                        <?php foreach ($pending_fixtures as $f): ?>
                          <option value="<?php echo e($f['fixture_id']); ?>">
                            <?php echo e(date('d M Y', strtotime($f['match_date'])).' | '
                                       .$f['home_team'].' vs '.$f['away_team']
                                       .' ['.$f['league_name'].']'); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>

                    <div class="row">
                      <div class="col-md-4 mb-3">
                        <label class="form-label">Home Score</label>
                        <input type="number" name="home_score" class="form-control" value="0" min="0">
                      </div>
                      <div class="col-md-4 mb-3">
                        <label class="form-label">Away Score</label>
                        <input type="number" name="away_score" class="form-control" value="0" min="0">
                      </div>
                      <div class="col-md-4 mb-3">
                        <label class="form-label">Status</label>
                        <select name="result_status" class="form-select">
                          <option value="Completed">Completed</option>
                          <option value="Postponed">Postponed</option>
                          <option value="Cancelled">Cancelled</option>
                        </select>
                      </div>
                    </div>

                    <button type="submit" class="btn btn-success">✅ Save Result</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- ══ TAB 3: All Fixtures ══════════════════════════ -->
          <div class="tab-pane fade" id="tab-all">
            <h5>All Fixtures</h5>
            <div class="table-responsive">
              <table class="table table-striped table-hover align-middle">
                <thead class="table-dark">
                  <tr>
                    <th>League</th>
                    <th>MD</th>
                    <th>Date</th>
                    <th>Home</th>
                    <th class="text-center">Score</th>
                    <th>Away</th>
                    <th>Venue</th>
                    <th>Status</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($fixtures) === 0): ?>
                    <tr><td colspan="9" class="text-center text-muted">No fixtures scheduled yet.</td></tr>
                  <?php endif; ?>
                  <?php foreach ($fixtures as $f): ?>
                    <tr>
                      <td><small><?php echo e($f['sport_name'].'<br>'.$f['league_name']); ?></small></td>
                      <td><?php echo e($f['matchday']); ?></td>
                      <td><?php echo e(date('d M Y', strtotime($f['match_date']))); ?><br>
                          <small class="text-muted"><?php echo e(date('H:i', strtotime($f['match_time']))); ?></small></td>
                      <td><?php echo e($f['home_team']); ?></td>
                      <td class="text-center score-box">
                        <?php if ($f['status'] === 'Completed'): ?>
                          <?php echo e($f['home_score'].' – '.$f['away_score']); ?>
                        <?php else: ?>
                          <span class="text-muted">vs</span>
                        <?php endif; ?>
                      </td>
                      <td><?php echo e($f['away_team']); ?></td>
                      <td><small><?php echo e($f['venue'] ?: '—'); ?></small></td>
                      <td>
                        <?php $sc = strtolower($f['status']); ?>
                        <span class="badge badge-<?php echo e($sc); ?> text-white">
                          <?php echo e($f['status']); ?>
                        </span>
                      </td>
                      <td>
                        <form method="post" class="d-inline"
                              onsubmit="return confirm('Delete this fixture?');">
                          <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                          <input type="hidden" name="action" value="delete_fixture">
                          <input type="hidden" name="fixture_id" value="<?php echo e($f['fixture_id']); ?>">
                          <button class="btn btn-danger btn-sm">🗑</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>

        </div><!-- /tab-content -->
      </div><!-- /card-body -->
    </div>
  </div>
</div>

<script>
// Teams grouped by league (from PHP)
const teamsByLeague = <?php echo json_encode($teams_by_league); ?>;

function populateTeams(leagueId, homeEl, awayEl) {
  const teams = teamsByLeague[leagueId] || [];
  [homeEl, awayEl].forEach(sel => {
    sel.innerHTML = '<option value="">Select team</option>';
    teams.forEach(t => {
      const opt = new Option(t.name, t.team_id);
      sel.add(opt);
    });
  });
}

document.getElementById('fx_league').addEventListener('change', function () {
  populateTeams(this.value,
    document.getElementById('fx_home'),
    document.getElementById('fx_away'));
});
</script>

<?php include_once("../includes/footer.php"); ?>
