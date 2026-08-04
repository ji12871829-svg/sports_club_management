<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/match_events.php';
require_once '../includes/csrf.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';
$fixture_id = (int) ($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'match_events_csrf')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_event') {
        $ok = asc_record_match_event(
            $conn,
            (int) $_POST['fixture_id'],
            (int) $_POST['team_id'],
            $_POST['event_type'] ?? 'goal',
            $_POST['player_name'] ?? null,
            !empty($_POST['member_id']) ? (int) $_POST['member_id'] : null,
            $_POST['minute'] !== '' ? (int) $_POST['minute'] : null,
            'admin'
        );
        $message = $ok ? '<div class="alert alert-success m-0 mb-3">Event recorded successfully.</div>' : '<div class="alert alert-danger m-0 mb-3">Could not record event.</div>';
        $fixture_id = (int) $_POST['fixture_id'];
    } elseif ($action === 'delete_event') {
        $eid = (int) ($_POST['event_id'] ?? 0);
        $stmt = $conn->prepare('DELETE FROM match_events WHERE event_id = ?');
        $stmt->bind_param('i', $eid);
        $message = $stmt->execute() ? '<div class="alert alert-success m-0 mb-3">Event removed.</div>' : '<div class="alert alert-danger m-0 mb-3">Delete failed.</div>';
        $stmt->close();
    }
}

$fixtures = $conn->query(
    "SELECT f.fixture_id, f.match_date, h.name AS home_team, a.name AS away_team,
            f.home_team_id, f.away_team_id, f.status
     FROM fixtures f
     JOIN teams h ON h.team_id = f.home_team_id
     JOIN teams a ON a.team_id = f.away_team_id
     ORDER BY f.match_date DESC LIMIT 80"
)->fetch_all(MYSQLI_ASSOC);

$fixture = null;
$events = [];
if ($fixture_id > 0) {
    foreach ($fixtures as $f) {
        if ((int) $f['fixture_id'] === $fixture_id) { 
            $fixture = $f; 
            break; 
        }
    }
    $events = asc_fixture_events($conn, $fixture_id);
    
    // Sort events by match minute sequentially
    usort($events, function($a, $b) {
        return ((int)($a['minute'] ?? 0)) <=> ((int)($b['minute'] ?? 0));
    });
}
?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">⚽ Goals &amp; Cards Management</h2>
    <a href="manage_fixtures.php" class="btn btn-outline-secondary btn-sm">⬅ Back to Fixtures</a>
  </div>

  <?php echo $message; ?>

  <?php if (!asc_match_events_ready($conn)): ?>
    <div class="alert alert-warning">Run migration <code>011_competition_and_ops_features.sql</code> first.</div>
  <?php else: ?>
  <div class="row g-4">
    
    <div class="col-md-4">
      <div class="card shadow-sm">
        <div class="card-header bg-light fw-bold">Recent & Upcoming Fixtures</div>
        <div class="list-group list-group-flush" style="max-height: 600px; overflow-y: auto;">
          <?php foreach ($fixtures as $f): ?>
            <a href="?fixture_id=<?php echo (int) $f['fixture_id']; ?>"
               class="list-group-item list-group-item-action <?php echo $fixture_id === (int) $f['fixture_id'] ? 'active' : ''; ?>">
              <div class="fw-semibold text-truncate"><?php echo e($f['home_team'] . ' vs ' . $f['away_team']); ?></div>
              <small class="<?php echo $fixture_id === (int) $f['fixture_id'] ? 'text-white-50' : 'text-muted'; ?>">
                <?php echo e($f['match_date'] . ' · ' . strtoupper($f['status'])); ?>
              </small>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    
    <div class="col-md-8">
      <?php if ($fixture): ?>
        <div class="card shadow-sm mb-4">
          <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0 text-truncate"><?php echo e($fixture['home_team'] . ' vs ' . $fixture['away_team']); ?></h5>
            <span class="badge bg-secondary"><?php echo e(strtoupper($fixture['status'])); ?></span>
          </div>
          <div class="card-body">
            
            <form method="post" class="row g-2 align-items-end mb-4 bg-light p-3 rounded border">
              <?php echo csrf_field('match_events_csrf'); ?>
              <input type="hidden" name="action" value="add_event">
              <input type="hidden" name="fixture_id" value="<?php echo $fixture_id; ?>">
              
              <div class="col-md-3">
                <label class="form-label small fw-bold">Team Involved</label>
                <select name="team_id" class="form-select" required>
                  <option value="<?php echo (int) $fixture['home_team_id']; ?>"><?php echo e($fixture['home_team']); ?></option>
                  <option value="<?php echo (int) $fixture['away_team_id']; ?>"><?php echo e($fixture['away_team']); ?></option>
                </select>
              </div>
              
              <div class="col-md-3">
                <label class="form-label small fw-bold">Incident</label>
                <select name="event_type" class="form-select" required>
                  <option value="goal">⚽ Goal</option>
                  <option value="penalty">🎯 Penalty Goal</option>
                  <option value="own_goal">❌ Own Goal</option>
                  <option value="yellow_card">🟨 Yellow Card</option>
                  <option value="red_card">🟥 Red Card</option>
                </select>
              </div>
              
              <div class="col-md-3">
                <label class="form-label small fw-bold">Player Name</label>
                <input type="text" name="player_name" class="form-control" placeholder="e.g. J. Omondi" required>
              </div>
              
              <div class="col-md-2">
                <label class="form-label small fw-bold">Minute</label>
                <input type="number" name="minute" class="form-control" placeholder="Min" min="0" max="130" required>
              </div>
              
              <div class="col-md-1">
                <button class="btn btn-primary w-100">Add</button>
              </div>
            </form>

            <div class="table-responsive">
              <table class="table table-hover align-middle">
                <thead class="table-light">
                  <tr>
                    <th style="width: 10%;">Min</th>
                    <th style="width: 25%;">Event Type</th>
                    <th style="width: 35%;">Player</th>
                    <th style="width: 20%;">Team</th>
                    <th style="width: 10%; text-align: center;">Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($events)): ?>
                    <tr>
                      <td colspan="5" class="text-center text-muted py-4">No events logged for this fixture yet.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($events as $ev): ?>
                      <tr>
                        <td class="fw-bold"><?php echo $ev['minute'] !== null ? (int) $ev['minute'] . "'" : '—'; ?></td>
                        <td>
                          <?php 
                            switch($ev['event_type']) {
                                case 'goal': 
                                    echo '<span class="badge bg-success">⚽ Goal</span>'; break;
                                case 'penalty': 
                                    echo '<span class="badge bg-success">🎯 Penalty</span>'; break;
                                case 'own_goal': 
                                    echo '<span class="badge bg-danger">❌ Own Goal</span>'; break;
                                case 'yellow_card': 
                                    echo '<span class="badge bg-warning text-dark">🟨 Yellow Card</span>'; break;
                                case 'red_card': 
                                    echo '<span class="badge bg-danger">🟥 Red Card</span>'; break;
                                default: 
                                    echo '<span class="badge bg-secondary">' . e($ev['event_type']) . '</span>';
                            }
                          ?>
                        </td>
                        <td class="fw-semibold"><?php echo e($ev['player_name']); ?></td>
                        <td><small class="text-secondary"><?php echo e($ev['team_name']); ?></small></td>
                        <td class="text-center">
                          <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to remove this event?');">
                            <?php echo csrf_field('match_events_csrf'); ?>
                            <input type="hidden" name="action" value="delete_event">
                            <input type="hidden" name="fixture_id" value="<?php echo $fixture_id; ?>">
                            <input type="hidden" name="event_id" value="<?php echo (int) $ev['event_id']; ?>">
                            <button class="btn btn-sm btn-link text-danger p-0 m-0 border-0" title="Delete event">
                              <i class="bi bi-trash"></i> Remove
                            </button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>

          </div>
          <div class="card-footer bg-white d-flex justify-content-end">
            <a href="../public/fixture_detail.php?id=<?php echo $fixture_id; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
              🌐 View on Live Public Site
            </a>
          </div>
        </div>
      <?php else: ?>
        <div class="text-center py-5 border rounded bg-light shadow-sm">
          <p class="text-muted fs-5 mb-0">👉 Select a fixture from the panel to record scorecards, goals, and disciplinary bookings.</p>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php $conn->close(); ?>