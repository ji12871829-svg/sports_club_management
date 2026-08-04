<?php
require_once '../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$logged_in = !empty($_SESSION['loggedin']);
$member_id = (int)($_SESSION['member_id'] ?? 0);
$message = '';
$post_id = (int)($_GET['post_id'] ?? 0);

if ($logged_in && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'forum_csrf')) {
    if (($_POST['action'] ?? '') === 'thread') {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if ($title !== '' && $body !== '') {
            $stmt = $conn->prepare("INSERT INTO forum_posts (member_id, title, body) VALUES (?,?,?)");
            $stmt->bind_param('iss', $member_id, $title, $body);
            $stmt->execute();
            $stmt->close();
            $message = '<div class="alert alert-success">Topic posted.</div>';
        }
    } elseif (($_POST['action'] ?? '') === 'reply') {
        $pid = (int)$_POST['post_id'];
        $body = trim($_POST['body'] ?? '');
        if ($pid > 0 && $body !== '') {
            $stmt = $conn->prepare("INSERT INTO forum_replies (post_id, member_id, body) VALUES (?,?,?)");
            $stmt->bind_param('iis', $pid, $member_id, $body);
            $stmt->execute();
            $stmt->close();
            $post_id = $pid;
            $message = '<div class="alert alert-success">Reply added.</div>';
        }
    }
}

if ($post_id > 0) {
    $stmt = $conn->prepare("SELECT p.*, m.first_name, m.last_name FROM forum_posts p JOIN members m ON m.member_id=p.member_id WHERE p.post_id=? AND p.is_hidden=0");
    $stmt->bind_param('i', $post_id);
    $stmt->execute();
    $thread = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $replies = [];
    if ($thread) {
        $replies = $conn->query("SELECT r.*, m.first_name, m.last_name FROM forum_replies r JOIN members m ON m.member_id=r.member_id WHERE r.post_id=$post_id AND r.is_hidden=0 ORDER BY r.created_at")->fetch_all(MYSQLI_ASSOC);
    }
} else {
    $threads = $conn->query("SELECT p.*, m.first_name, m.last_name FROM forum_posts p JOIN members m ON m.member_id=p.member_id WHERE p.is_hidden=0 ORDER BY p.created_at DESC LIMIT 40")->fetch_all(MYSQLI_ASSOC);
}
$conn->close();
include '../includes/header.php';
?>
<div class="container py-4" style="max-width:800px;">
    <h2 class="fw-bold mb-3">Member forum</h2>
    <?php echo $message; ?>
    <?php if ($post_id > 0 && !empty($thread)): ?>
        <a href="forum.php" class="btn btn-sm btn-outline-secondary mb-3">← All topics</a>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h4><?php echo e($thread['title']); ?></h4>
                <p class="text-muted small">By <?php echo e($thread['first_name'].' '.$thread['last_name']); ?> · <?php echo e($thread['created_at']); ?></p>
                <p><?php echo nl2br(e($thread['body'])); ?></p>
            </div>
        </div>
        <?php foreach ($replies as $r): ?>
            <div class="card border-0 shadow-sm mb-2 ms-3"><div class="card-body py-2"><p class="mb-1 small text-muted"><?php echo e($r['first_name'].' '.$r['last_name']); ?></p><p class="mb-0"><?php echo nl2br(e($r['body'])); ?></p></div></div>
        <?php endforeach; ?>
        <?php if ($logged_in): ?>
        <form method="post" class="card border-0 shadow-sm p-3 mt-3">
            <?php echo csrf_field('forum_csrf'); ?>
            <input type="hidden" name="action" value="reply">
            <input type="hidden" name="post_id" value="<?php echo $post_id; ?>">
            <textarea name="body" class="form-control mb-2" rows="3" required></textarea>
            <button class="btn btn-primary btn-sm">Reply</button>
        </form>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($logged_in): ?>
        <form method="post" class="card border-0 shadow-sm p-3 mb-4">
            <?php echo csrf_field('forum_csrf'); ?>
            <input type="hidden" name="action" value="thread">
            <input name="title" class="form-control mb-2" placeholder="Topic title" required>
            <textarea name="body" class="form-control mb-2" rows="3" required></textarea>
            <button class="btn btn-primary">New topic</button>
        </form>
        <?php else: ?><p class="alert alert-info"><a href="login.php">Log in</a> to post.</p><?php endif; ?>
        <ul class="list-group">
            <?php foreach ($threads ?? [] as $t): ?>
                <a href="forum.php?post_id=<?php echo (int)$t['post_id']; ?>" class="list-group-item list-group-item-action">
                    <strong><?php echo e($t['title']); ?></strong>
                    <span class="d-block small text-muted"><?php echo e($t['first_name'].' '.$t['last_name']); ?> · <?php echo e($t['created_at']); ?></span>
                </a>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
<?php include '../includes/footer.php'; ?>
