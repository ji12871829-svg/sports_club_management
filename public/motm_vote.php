<?php
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
asc_session_start();

if (empty($_SESSION['loggedin'])) {
    header('Location: login.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/motm.php';
require_once '../includes/head_to_head.php';
require_once '../includes/url.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$fixture_id = (int) ($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? 0);
$member_id = (int) $_SESSION['member_id'];
$fixture = $fixture_id > 0 ? asc_get_fixture_detail($conn, $fixture_id) : null;
$message = '';

if (!$fixture || $fixture['status'] !== 'Completed') {
    include_once '../includes/header.php';
    echo '<div class="container py-5"><div class="alert alert-warning">Voting is only open for completed matches.</div></div>';
    include_once '../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'motm_csrf')) {
    $result = asc_cast_motm_vote(
        $conn,
        $fixture_id,
        $member_id,
        (int) ($_POST['team_id'] ?? 0),
        trim($_POST['player_name'] ?? ''),
        !empty($_POST['member_id']) ? (int) $_POST['member_id'] : null
    );
    $message = $result['ok']
        ? '<div class="alert alert-success">Thank you — your vote was recorded.</div>'
        : '<div class="alert alert-danger">' . e($result['error']) . '</div>';
}

$results = asc_motm_results($conn, $fixture_id);
$my_vote = asc_member_motm_vote($conn, $fixture_id, $member_id);

include_once '../includes/header.php';
?>

<div class="container py-4" style="max-width: 560px;">
  <h2 class="fw-bold mb-1">Man of the Match</h2>
  <p class="text-muted"><?php echo e($fixture['home_team'] . ' vs ' . $fixture['away_team']); ?></p>
  <?php echo $message; ?>

  <form method="post" class="card border-0 shadow-sm mb-4">
    <div class="card-body">
      <?php echo csrf_field('motm_csrf'); ?>
      <input type="hidden" name="fixture_id" value="<?php echo $fixture_id; ?>">
      <div class="mb-3">
        <label class="form-label">Team</label>
        <select name="team_id" class="form-select" required>
          <option value="<?php echo (int) $fixture['home_team_id']; ?>"><?php echo e($fixture['home_team']); ?></option>
          <option value="<?php echo (int) $fixture['away_team_id']; ?>"><?php echo e($fixture['away_team']); ?></option>
        </select>
      </div>
      <div class="mb-3">
        <label class="form-label">Player name</label>
        <input type="text" name="player_name" class="form-control" required maxlength="120"
               value="<?php echo e($my_vote['player_name'] ?? ''); ?>" placeholder="e.g. John Kamau">
      </div>
      <button type="submit" class="btn btn-primary w-100">Submit vote</button>
    </div>
  </form>

  <h5>Current standings</h5>
  <ol class="mb-0">
    <?php foreach ($results as $r): ?>
      <li><?php echo e($r['player_name']); ?> (<?php echo e($r['team_name']); ?>) — <?php echo (int) $r['votes']; ?></li>
    <?php endforeach; ?>
    <?php if (empty($results)): ?><li class="text-muted">No votes yet</li><?php endif; ?>
  </ol>

  <a href="<?php echo e(app_url('public/fixture_detail.php?id=' . $fixture_id)); ?>" class="btn btn-link mt-3 ps-0">← Back to match</a>
</div>

<?php include_once '../includes/footer.php'; $conn->close(); ?>
