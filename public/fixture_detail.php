<?php
require_once '../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/url.php';
require_once '../includes/head_to_head.php';
require_once '../includes/match_events.php';
require_once '../includes/motm.php';
require_once '../includes/lineups.php';
require_once '../includes/match_commentary.php';
require_once '../includes/match_reports.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$fixture_id = (int) ($_GET['id'] ?? 0);
$fixture = $fixture_id > 0 ? asc_get_fixture_detail($conn, $fixture_id) : null;

if (!$fixture) {
    include_once '../includes/header.php';
    echo '<div class="container py-5"><div class="alert alert-warning">Fixture not found.</div></div>';
    include_once '../includes/footer.php';
    $conn->close();
    exit;
}

$events = asc_fixture_events($conn, $fixture_id);
$cards = asc_card_summary_by_fixture($conn, $fixture_id);
$motm = asc_motm_results($conn, $fixture_id);
$h2h = asc_head_to_head($conn, (int) $fixture['home_team_id'], (int) $fixture['away_team_id'], (int) $fixture['league_id']);
$lineups = asc_fetch_fixture_lineups($conn, $fixture_id);
$commentary = commentary_fetch_by_fixture($conn, $fixture_id);
$published_report = asc_get_match_report_by_fixture($conn, $fixture_id, true);

$member_logged_in = !empty($_SESSION['loggedin']);
$member_id = (int) ($_SESSION['member_id'] ?? 0);
$my_vote = $member_logged_in ? asc_member_motm_vote($conn, $fixture_id, $member_id) : null;

include_once '../includes/header.php';
?>

<div class="container py-4">
  <nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?php echo e(app_url('public/view_fixtures.php')); ?>">Fixtures</a></li>
      <li class="breadcrumb-item active">Match detail</li>
    </ol>
  </nav>

  <div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
      <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
        <div>
          <span class="badge bg-secondary"><?php echo e($fixture['sport_name']); ?></span>
          <span class="badge bg-primary"><?php echo e($fixture['league_name'] . ' ' . $fixture['season']); ?></span>
          <span class="badge <?php echo $fixture['status'] === 'Live' ? 'bg-danger' : 'bg-dark'; ?>">
            <?php echo e($fixture['status']); ?>
          </span>
        </div>
        <small class="text-muted">MD <?php echo e($fixture['matchday']); ?> · <?php echo e(date('d M Y', strtotime($fixture['match_date']))); ?> <?php echo e(substr($fixture['match_time'], 0, 5)); ?></small>
      </div>

      <div class="row text-center align-items-center g-3">
        <div class="col-md-4"><h4 class="mb-0"><?php echo e($fixture['home_team']); ?></h4></div>
        <div class="col-md-4">
          <div class="display-5 fw-bold"><?php echo (int) $fixture['home_score']; ?> – <?php echo (int) $fixture['away_score']; ?></div>
          <?php if ($fixture['live_status']): ?><small class="text-danger"><?php echo e($fixture['live_status']); ?><?php echo $fixture['live_minute'] ? ' ' . (int) $fixture['live_minute'] . "'" : ''; ?></small><?php endif; ?>
        </div>
        <div class="col-md-4"><h4 class="mb-0"><?php echo e($fixture['away_team']); ?></h4></div>
      </div>
      <?php if ($fixture['venue']): ?><p class="text-center text-muted mt-3 mb-0"><i class="fas fa-map-marker-alt me-1"></i><?php echo e($fixture['venue']); ?></p><?php endif; ?>
    </div>
  </div>

  <?php if ($published_report): ?>
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header bg-white"><strong>Match report</strong></div>
      <div class="card-body">
        <h5 class="mb-3"><?php echo e($published_report['headline']); ?></h5>
        <div class="text-muted" style="line-height:1.65;"><?php echo nl2br(e($published_report['body'])); ?></div>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong>📋 Published lineups</strong></div>
        <div class="card-body">
          <?php if (empty($lineups)): ?>
            <p class="text-muted mb-0">Lineups are not published yet.</p>
          <?php else: ?>
            <div class="row g-3">
              <?php foreach ($lineups as $lineup): ?>
                <div class="col-md-6">
                  <div class="border rounded p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                      <h6 class="mb-0"><?php echo e($lineup['team_name']); ?></h6>
                      <?php if (!empty($lineup['formation'])): ?>
                        <span class="badge bg-light text-dark border"><?php echo e($lineup['formation']); ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="small text-muted mb-1">Starting XI</div>
                    <ul class="mb-2 ps-3">
                      <?php foreach ($lineup['starters'] as $p): ?>
                        <li><?php echo e($p); ?></li>
                      <?php endforeach; ?>
                    </ul>
                    <div class="small text-muted mb-1">Substitutes</div>
                    <?php if (empty($lineup['substitutes'])): ?>
                      <p class="small mb-0 text-muted">No substitutes listed.</p>
                    <?php else: ?>
                      <ul class="mb-0 ps-3">
                        <?php foreach ($lineup['substitutes'] as $p): ?>
                          <li><?php echo e($p); ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card border-0 shadow-sm h-100">
        <div class="card-header bg-white"><strong>⚽ Match timeline</strong></div>
        <div class="card-body">
          <?php if (empty($events)): ?>
            <p class="text-muted mb-0">No goals or cards recorded yet.</p>
          <?php else: ?>
            <ul class="list-group list-group-flush">
              <?php foreach ($events as $ev): ?>
                <li class="list-group-item d-flex justify-content-between">
                  <span>
                    <?php
                    $icon = '•';
                    if (in_array($ev['event_type'], ['goal', 'penalty'], true)) $icon = '⚽';
                    elseif ($ev['event_type'] === 'own_goal') $icon = '🥅';
                    elseif ($ev['event_type'] === 'yellow_card') $icon = '🟨';
                    elseif ($ev['event_type'] === 'red_card') $icon = '🟥';
                    echo $icon . ' ' . e($ev['player_name'] ?: 'Unknown');
                    ?> <small class="text-muted">(<?php echo e($ev['team_name']); ?>)</small>
                  </span>
                  <span class="text-muted"><?php echo $ev['minute'] !== null ? (int) $ev['minute'] . "'" : '—'; ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white"><strong>🏆 Man of the Match</strong></div>
        <div class="card-body">
          <?php if (empty($motm)): ?>
            <p class="text-muted mb-2">No votes yet.</p>
          <?php else: ?>
            <ol class="mb-0">
              <?php foreach ($motm as $row): ?>
                <li><?php echo e($row['player_name']); ?> <small class="text-muted">(<?php echo e($row['team_name']); ?>)</small> — <strong><?php echo (int) $row['votes']; ?></strong> vote(s)</li>
              <?php endforeach; ?>
            </ol>
          <?php endif; ?>
          <?php if ($fixture['status'] === 'Completed' && $member_logged_in): ?>
            <a href="<?php echo e(app_url('public/motm_vote.php?fixture_id=' . $fixture_id)); ?>" class="btn btn-sm btn-primary mt-3">Cast your vote</a>
          <?php elseif ($fixture['status'] === 'Completed'): ?>
            <a href="<?php echo e(app_url('public/login.php')); ?>" class="btn btn-sm btn-outline-primary mt-3">Log in to vote</a>
          <?php endif; ?>
          <?php if ($my_vote): ?>
            <p class="small text-success mt-2 mb-0">You voted for <?php echo e($my_vote['player_name']); ?>.</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white"><strong>📊 Head-to-head</strong></div>
        <div class="card-body">
          <p class="mb-2">
            <strong><?php echo e($fixture['home_team']); ?></strong> <?php echo (int) $h2h['stats']['team_a_wins']; ?> –
            <?php echo (int) $h2h['stats']['draws']; ?> draws –
            <?php echo (int) $h2h['stats']['team_b_wins']; ?> <strong><?php echo e($fixture['away_team']); ?></strong>
          </p>
          <p class="text-muted small mb-2"><?php echo (int) $h2h['stats']['played']; ?> meetings ·
            Goals <?php echo (int) $h2h['stats']['team_a_goals']; ?>–<?php echo (int) $h2h['stats']['team_b_goals']; ?></p>
          <a href="<?php echo e(app_url('public/head_to_head.php?team_a=' . $fixture['home_team_id'] . '&team_b=' . $fixture['away_team_id'] . '&league_id=' . $fixture['league_id'])); ?>" class="btn btn-sm btn-outline-secondary">Full H2H history</a>
        </div>
      </div>

      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
          <strong>🎙️ Live commentary</strong>
          <?php if ($fixture['status'] === 'Live'): ?><span class="badge bg-danger">LIVE</span><?php endif; ?>
        </div>
        <div class="card-body" style="max-height:320px;overflow-y:auto;">
          <?php if (empty($commentary)): ?>
            <p class="text-muted mb-0 small">No commentary yet. Check back during the match.</p>
          <?php else: ?>
            <ul class="list-unstyled mb-0">
              <?php foreach ($commentary as $c): ?>
                <li class="mb-3 pb-2 border-bottom">
                  <?php if ($c['minute'] !== null): ?><span class="badge bg-dark me-1"><?php echo (int)$c['minute']; ?>'</span><?php endif; ?>
                  <?php echo e($c['text']); ?>
                  <div class="text-muted" style="font-size:.7rem;"><?php echo e(date('H:i', strtotime($c['created_at']))); ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include_once '../includes/footer.php'; $conn->close(); ?>
