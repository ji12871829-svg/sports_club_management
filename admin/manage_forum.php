<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'] ?? 'post';
    $id = (int)$_POST['id'];
  if ($type === 'post') {
        $conn->query("UPDATE forum_posts SET is_hidden=1 WHERE post_id=$id");
    } else {
        $conn->query("UPDATE forum_replies SET is_hidden=1 WHERE reply_id=$id");
    }
}

$posts = $conn->query("SELECT p.*, m.first_name, m.last_name FROM forum_posts p JOIN members m ON m.member_id=p.member_id ORDER BY p.created_at DESC LIMIT 50")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<div class="container-fluid py-4">
    <h1 class="fw-bold fs-4 mb-3">Forum moderation</h1>
    <div class="table-responsive card border-0 shadow-sm">
        <table class="table mb-0">
            <thead><tr><th>Title</th><th>Author</th><th>Hidden</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($posts as $p): ?>
                <tr>
                    <td><?php echo e($p['title']); ?></td>
                    <td><?php echo e($p['first_name'].' '.$p['last_name']); ?></td>
                    <td><?php echo $p['is_hidden'] ? 'Yes' : 'No'; ?></td>
                    <td><?php if (!$p['is_hidden']): ?><form method="post"><input type="hidden" name="type" value="post"><input type="hidden" name="id" value="<?php echo (int)$p['post_id']; ?>"><button class="btn btn-sm btn-outline-danger">Hide</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
