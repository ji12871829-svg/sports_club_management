<?php
include_once '../includes/header.php';
require_once '../config/db_connect.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$sponsors = [];
$r = $conn->query("SELECT * FROM sponsors WHERE is_active = 1 ORDER BY FIELD(tier,'Platinum','Gold','Silver','Bronze'), name");
if ($r) { while ($row = $r->fetch_assoc()) { $sponsors[] = $row; } }
$conn->close();
$tier_colors = ['Platinum'=>'#1e293b','Gold'=>'#d97706','Silver'=>'#6b7280','Bronze'=>'#b45309'];
?>
<div class="container py-5">
    <h1 class="fw-bold mb-2">Our sponsors</h1>
    <p class="text-muted mb-4">Thank you to the partners who support Apex Sports Club.</p>
    <?php if (empty($sponsors)): ?>
        <div class="alert alert-light border">Sponsor listings coming soon.</div>
    <?php else: ?>
        <div class="row g-4">
            <?php foreach ($sponsors as $s):
                $color = $tier_colors[$s['tier']] ?? '#475569';
            ?>
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body text-center">
                        <span class="badge mb-2" style="background:<?php echo e($color); ?>"><?php echo e($s['tier']); ?></span>
                        <?php if (!empty($s['logo_url'])): ?>
                            <img src="<?php echo e($s['logo_url']); ?>" alt="" class="img-fluid mb-3" loading="lazy" decoding="async" style="max-height:80px;object-fit:contain;">
                        <?php endif; ?>
                        <h5 class="fw-bold"><?php echo e($s['name']); ?></h5>
                        <?php if (!empty($s['website_url'])): ?>
                            <a href="<?php echo e($s['website_url']); ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary mt-2">Visit website</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php include_once '../includes/footer.php'; ?>
