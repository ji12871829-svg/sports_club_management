<?php
include_once '../includes/header.php';
require_once '../config/db_connect.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$items = $conn->query("SELECT * FROM gallery_items WHERE is_public=1 ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<div class="container py-4">
    <h1 class="fw-bold mb-3">Match gallery</h1>
    <div class="row g-3">
        <?php if (empty($items)): ?>
            <p class="text-muted">Photos will appear here after match days.</p>
        <?php else: foreach ($items as $it): ?>
            <div class="col-md-4 col-sm-6">
                <div class="card border-0 shadow-sm">
                    <img src="<?php echo e($it['image_url']); ?>" class="card-img-top" alt="<?php echo e($it['title']); ?>" loading="lazy" decoding="async" style="height:220px;object-fit:cover;">
                    <div class="card-body"><h6 class="fw-bold mb-1"><?php echo e($it['title']); ?></h6><p class="small text-muted mb-0"><?php echo e($it['caption']); ?></p></div>
                </div>
            </div>
        <?php endforeach; endif; ?>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
