<?php
/**
 * public/polls.php
 * Members vote on club polls created by admin.
 */
include_once '../includes/header.php';
require_once '../config/db_connect.php';
require_once '../includes/session_config.php';
asc_session_start();
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/csrf.php';

$logged_in = isset($_SESSION['member_loggedin']) && $_SESSION['member_loggedin'] === true;
$member_id = $logged_in ? (int)$_SESSION['member_id'] : 0;
$message   = '';

// Handle vote
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $logged_in) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
        $message = '<div class="alert alert-danger"><i class="fas fa-shield-halved me-2"></i>Invalid or missing CSRF token. Please reload the page and try again.</div>';
    } else {
    $poll_id   = (int)($_POST['poll_id']   ?? 0);
    $option_id = (int)($_POST['option_id'] ?? 0);

    // Check not already voted
    $check = $conn->prepare("SELECT vote_id FROM poll_votes WHERE poll_id=? AND member_id=?");
    $check->bind_param("ii", $poll_id, $member_id);
    $check->execute();
    $already = $check->get_result()->fetch_assoc();
    $check->close();

    if ($already) {
        $message = '<div class="alert alert-warning">You have already voted on this poll.</div>';
    } else {
        $conn->begin_transaction();
        $v = $conn->prepare("INSERT INTO poll_votes (poll_id, option_id, member_id) VALUES (?,?,?)");
        $v->bind_param("iii", $poll_id, $option_id, $member_id);
        $v->execute(); $v->close();
        $conn->query("UPDATE poll_options SET vote_count = vote_count + 1 WHERE option_id = $option_id");
        $conn->commit();
        $message = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Vote recorded!</div>';
    }
    }
}

// Load active polls with options
$polls = $conn->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM poll_votes pv WHERE pv.poll_id = p.poll_id) AS total_votes
    FROM polls p
    WHERE p.is_active = 1
      AND (p.expires_at IS NULL OR p.expires_at > NOW())
    ORDER BY p.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

foreach ($polls as &$poll) {
    $poll['options'] = $conn->query("
        SELECT * FROM poll_options WHERE poll_id = {$poll['poll_id']} ORDER BY option_id ASC
    ")->fetch_all(MYSQLI_ASSOC);

    // Check if member already voted
    if ($logged_in) {
        $v = $conn->prepare("SELECT option_id FROM poll_votes WHERE poll_id=? AND member_id=?");
        $v->bind_param("ii", $poll['poll_id'], $member_id);
        $v->execute();
        $poll['voted_option'] = $v->get_result()->fetch_row()[0] ?? null;
        $v->close();
    } else {
        $poll['voted_option'] = null;
    }
}
unset($poll);

$conn->close();
?>

<div class="container py-5" style="max-width:720px;">
    <h2 class="fw-bold mb-1">🗳️ Club Polls</h2>
    <p class="text-muted mb-4">Have your say on club decisions</p>

    <?php if ($message) echo $message; ?>

    <?php if (!$logged_in): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i>
            <a href="login.php">Log in</a> to vote on polls.
        </div>
    <?php endif; ?>

    <?php if (empty($polls)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-poll fa-2x mb-3 d-block"></i>
            No active polls at the moment. Check back soon!
        </div>
    <?php else: ?>
        <?php foreach ($polls as $poll):
            $total     = (int)$poll['total_votes'];
            $voted     = $poll['voted_option'] !== null;
            $show_results = $voted || !$logged_in;
        ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h5 class="fw-bold mb-1"><?php echo e($poll['question']); ?></h5>
                <div class="text-muted small mb-3">
                    <?php echo $total; ?> vote(s)
                    <?php if ($poll['expires_at']): ?>
                        · Closes <?php echo e(date('d M Y', strtotime($poll['expires_at']))); ?>
                    <?php endif; ?>
                </div>

                <?php if (!$show_results): ?>
                    <!-- Voting form -->
                    <form method="POST">
                        <?php echo csrf_field('member_csrf'); ?>
                        <input type="hidden" name="poll_id" value="<?php echo e($poll['poll_id']); ?>">
                        <?php foreach ($poll['options'] as $opt): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="radio"
                                   name="option_id" value="<?php echo e($opt['option_id']); ?>"
                                   id="opt_<?php echo e($opt['option_id']); ?>" required>
                            <label class="form-check-label" for="opt_<?php echo e($opt['option_id']); ?>">
                                <?php echo e($opt['option_text']); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary mt-2">
                            <i class="fas fa-vote-yea me-1"></i> Cast Vote
                        </button>
                    </form>
                <?php else: ?>
                    <!-- Results -->
                    <?php foreach ($poll['options'] as $opt):
                        $pct = $total > 0 ? round(($opt['vote_count'] / $total) * 100) : 0;
                        $is_winner = $opt['vote_count'] === max(array_column($poll['options'], 'vote_count')) && $total > 0;
                        $is_voted  = (string)$opt['option_id'] === (string)$poll['voted_option'];
                    ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="<?php echo $is_voted ? 'fw-bold text-primary' : ''; ?>">
                                <?php echo e($opt['option_text']); ?>
                                <?php if ($is_voted): ?> <i class="fas fa-check-circle text-primary ms-1"></i><?php endif; ?>
                                <?php if ($is_winner): ?> <i class="fas fa-crown text-warning ms-1"></i><?php endif; ?>
                            </span>
                            <span class="fw-semibold"><?php echo $pct; ?>% <small class="text-muted">(<?php echo $opt['vote_count']; ?>)</small></span>
                        </div>
                        <div class="progress" style="height:10px;">
                            <div class="progress-bar <?php echo $is_voted ? 'bg-primary' : 'bg-secondary'; ?>"
                                 style="width:<?php echo $pct; ?>%;border-radius:5px;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($voted): ?>
                        <small class="text-muted">Your vote has been recorded.</small>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include_once '../includes/footer.php'; ?>
