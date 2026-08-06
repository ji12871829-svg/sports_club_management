<?php
/**
 * admin/manage_polls.php
 * Create and manage member polls.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';
$admin_id = $_SESSION['admin_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $question   = trim($_POST['question'] ?? '');
        $expires_at = trim($_POST['expires_at'] ?? '') ?: null;
        $options    = array_filter(array_map('trim', $_POST['options'] ?? []));

        if ($question && count($options) >= 2) {
            $stmt = $conn->prepare("INSERT INTO polls (question, expires_at, created_by) VALUES (?,?,?)");
            $stmt->bind_param("ssi", $question, $expires_at, $admin_id);
            $stmt->execute();
            $poll_id = $conn->insert_id;
            $stmt->close();

            foreach ($options as $opt) {
                $s = $conn->prepare("INSERT INTO poll_options (poll_id, option_text) VALUES (?,?)");
                $s->bind_param("is", $poll_id, $opt);
                $s->execute(); $s->close();
            }
            log_activity($conn, 'Created poll', 'Polls', $poll_id, $question);
            $message = '<div class="alert alert-success">Poll created and published.</div>';
        } else {
            $message = '<div class="alert alert-danger">Please provide a question and at least 2 options.</div>';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("UPDATE polls SET is_active = NOT is_active WHERE poll_id = $id");
        $message = '<div class="alert alert-success">Poll status toggled.</div>';
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM polls WHERE poll_id = $id");
        log_activity($conn, 'Deleted poll', 'Polls', $id);
        $message = '<div class="alert alert-success">Poll deleted.</div>';
    }
}

$polls = $conn->query("
    SELECT p.*,
           (SELECT COUNT(*) FROM poll_votes v WHERE v.poll_id = p.poll_id) AS total_votes
    FROM polls p ORDER BY p.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

foreach ($polls as &$p) {
    $p['options'] = $conn->query("SELECT * FROM poll_options WHERE poll_id = {$p['poll_id']} ORDER BY vote_count DESC")->fetch_all(MYSQLI_ASSOC);
}
unset($p);
$conn->close();
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:#2a6ba8;">
            <i class="fas fa-poll text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">Manage Polls</h1>
            <p class="text-muted mb-0 small">Create polls for members to vote on</p>
        </div>
    </div>

    <?php if ($message) echo $message; ?>

    <div class="row g-4">
        <!-- Create form -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-plus-circle me-2 text-primary"></i>New Poll
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Question</label>
                            <input type="text" name="question" class="form-control" required
                                   placeholder="e.g. What should our new kit colour be?">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Options <small class="text-muted">(min 2)</small></label>
                            <input type="text" name="options[]" class="form-control mb-2" placeholder="Option 1" required>
                            <input type="text" name="options[]" class="form-control mb-2" placeholder="Option 2" required>
                            <input type="text" name="options[]" class="form-control mb-2" placeholder="Option 3 (optional)">
                            <input type="text" name="options[]" class="form-control" placeholder="Option 4 (optional)">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Closes At <small class="text-muted">(optional)</small></label>
                            <input type="datetime-local" name="expires_at" class="form-control">
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-paper-plane me-2"></i>Publish Poll
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Poll list -->
        <div class="col-lg-8">
            <?php if (empty($polls)): ?>
                <div class="text-center py-5 text-muted">No polls yet.</div>
            <?php else: ?>
                <?php foreach ($polls as $poll): ?>
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-start justify-content-between gap-3">
                            <div class="flex-grow-1">
                                <div class="fw-semibold mb-1">
                                    <?php echo e($poll['question']); ?>
                                    <span class="badge ms-2 <?php echo $poll['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $poll['is_active'] ? 'Active' : 'Closed'; ?>
                                    </span>
                                </div>
                                <div class="text-muted small mb-2"><?php echo (int)$poll['total_votes']; ?> total votes</div>

                                <!-- Results bars -->
                                <?php foreach ($poll['options'] as $opt):
                                    $pct = $poll['total_votes'] > 0
                                        ? round(($opt['vote_count'] / $poll['total_votes']) * 100) : 0;
                                ?>
                                <div class="mb-2">
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span><?php echo e($opt['option_text']); ?></span>
                                        <span><?php echo $pct; ?>% (<?php echo $opt['vote_count']; ?>)</span>
                                    </div>
                                    <div class="progress" style="height:8px;">
                                        <div class="progress-bar bg-primary" style="width:<?php echo $pct; ?>%;border-radius:4px;"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="d-flex flex-column gap-2 flex-shrink-0">
                                <form method="POST">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo e($poll['poll_id']); ?>">
                                    <button class="btn btn-sm <?php echo $poll['is_active'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>">
                                        <?php echo $poll['is_active'] ? 'Close' : 'Reopen'; ?>
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Delete this poll?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo e($poll['poll_id']); ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
