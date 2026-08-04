<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';

require_once __DIR__ . '/../includes/input_sanitize.php';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'add') {
        $stmt = $conn->prepare("INSERT INTO gallery_items (title,caption,image_url,is_public) VALUES (?,?,?,?)");
        $title = trim($_POST['title'] ?? '');
        $cap = trim($_POST['caption'] ?? '');
        $url = trim($_POST['image_url'] ?? '');
        $pub = isset($_POST['is_public']) ? 1 : 0;
        $stmt->bind_param('sssi', $title, $cap, $url, $pub);
        $stmt->execute();
        $stmt->close();
        $message = '<div class="alert alert-success">Photo added.</div>';
    } elseif (($_POST['action'] ?? '') === 'delete') {
        $conn->query('DELETE FROM gallery_items WHERE item_id=' . (int)$_POST['id']);
        $message = '<div class="alert alert-success">Removed.</div>';
    }
}

$items = $conn->query("SELECT * FROM gallery_items ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<div class="container-fluid py-4">
    <h1 class="fw-bold fs-4 mb-3">Match gallery</h1>
    <?php echo $message; ?>
    <div class="card border-0 shadow-sm p-3 mb-4">
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="add">
            <div class="col-md-3"><input name="title" class="form-control" placeholder="Title" required></div>
            <div class="col-md-4"><input name="image_url" class="form-control" placeholder="Image URL (https://...)" required></div>
            <div class="col-md-3"><input name="caption" class="form-control" placeholder="Caption"></div>
            <div class="col-md-1 form-check pt-2"><input type="checkbox" name="is_public" class="form-check-input" id="pub" checked><label for="pub" class="form-check-label small">Public</label></div>
            <div class="col-md-1"><button class="btn btn-primary w-100">Add</button></div>
        </form>
    </div>
    <div class="row g-3">
        <?php foreach ($items as $it): ?>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <img src="<?php echo e($it['image_url']); ?>" class="card-img-top" alt="" style="height:180px;object-fit:cover;">
                <div class="card-body p-2">
                    <strong class="small"><?php echo e($it['title']); ?></strong>
                    <form method="post" class="mt-1"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$it['item_id']; ?>"><button class="btn btn-sm btn-outline-danger">Delete</button></form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
