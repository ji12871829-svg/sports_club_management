<?php
/**
 * admin/upload_storage.php
 * Object-storage dashboard — shows the active storage driver (local vs
 * S3-compatible), total upload size, largest files, and orphaned uploads
 * (files on disk whose path is not referenced by any DB row), with a
 * CSRF-protected cleanup action.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/object_storage.php';

$msg     = '';
$msgType = 'success';
$cleaned = 0;

// ── Cleanup action (CSRF-protected) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanup_orphans'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'upload_storage_cleanup')) {
        $msgType = 'danger';
        $msg     = 'Security check failed. Please refresh and try again.';
    } else {
        $cleaned = asc_delete_orphaned_uploads($conn);
        $msg = ($cleaned > 0)
            ? "Removed {$cleaned} orphaned upload file(s)."
            : 'No orphaned uploads found — nothing to clean.';
    }
}

// ── Storage driver test (GET, read-only) ───────────────────────────────
$storageTest = null;
if (isset($_GET['test']) && $_GET['test'] === '1') {
    $storageTest = asc_storage_test();
}

// ── Scan uploads directory ─────────────────────────────────────────────
$uploadRoot = dirname(__DIR__) . '/uploads';
$allFiles   = [];
if (is_dir($uploadRoot)) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && strpos($file->getFilename(), '.') !== 0) { // skip dotfiles (.htaccess, etc.)
            $rel   = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen(dirname(__DIR__)) + 1));
            $allFiles[$rel] = ['size' => $file->getSize(), 'mtime' => $file->getMTime()];
        }
    }
}
ksort($allFiles);

$totalSize = array_sum(array_column($allFiles, 'size'));
$fileCount = count($allFiles);

// Largest files
usort($allFiles, fn($a, $b) => $b['size'] <=> $a['size']);
$largest = array_slice($allFiles, 0, 12, true);

// Orphan check — gather every path referenced in the DB
$refTables = [
    'members'                    => 'profile_photo',
    'gallery_items'              => 'image_url',
    'gear_marketplace_listings'  => 'item_image_url',
    'equipment_damage_reports'   => 'damage_photo_url',
    'sponsors'                   => 'logo_url',
    'sponsorship_campaigns'      => 'ad_image_url',
    'sponsors_extended'          => 'logo_url',
    'social_media_posts'         => 'image_url',
];
$referenced = [];
foreach ($refTables as $table => $col) {
    try {
        $r = $conn->query("SELECT $col FROM $table WHERE $col IS NOT NULL AND $col != ''");
        if ($r) {
            while ($row = $r->fetch_row()) {
                $referenced[trim($row[0])] = true;
            }
            $r->free();
        }
    } catch (\Throwable $e) {
        // Table may not exist (optional feature modules) — skip.
    }
}

$orphans = [];
foreach ($allFiles as $rel => $info) {
    if (!isset($referenced[$rel])) {
        $orphans[$rel] = $info;
    }
}

$conn->close();
?>

<div class="asc-dash">
    <div class="asc-page-head">
        <div>
            <h1 class="asc-page-title">Object Storage</h1>
            <p class="asc-page-sub">Uploaded media — driver status, disk usage, and orphaned files</p>
        </div>
        <div class="asc-page-actions">
            <a href="?test=1" class="asc-btn asc-btn-ghost">
                <i class="fas fa-vial"></i> Test Storage
            </a>
            <a href="system_health.php" class="asc-btn asc-btn-ghost">
                <i class="fas fa-server"></i> System Health
            </a>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-<?php echo $msgType === 'danger' ? 'danger' : 'success'; ?> border-0 shadow-sm rounded-3 d-flex align-items-center gap-2 mb-3">
            <i class="fas fa-<?php echo $msgType === 'danger' ? 'triangle-exclamation' : 'circle-check'; ?>"></i> <?php echo e($msg); ?>
        </div>
    <?php endif; ?>

    <?php if ($storageTest): ?>
        <div class="alert <?php echo $storageTest['ok'] ? 'alert-success' : 'alert-danger'; ?> border-0 shadow-sm rounded-3 d-flex align-items-center gap-2 mb-3">
            <i class="fas fa-<?php echo $storageTest['ok'] ? 'check-circle' : 'exclamation-circle'; ?>"></i>
            <span>
                <strong><?php echo strtoupper($storageTest['driver']); ?> driver:</strong>
                <?php echo e($storageTest['message']); ?>
            </span>
        </div>
    <?php endif; ?>

    <!-- Driver status -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top"><span class="asc-stat-icon asc-icon-brand"><i class="fas fa-cloud"></i></span></div>
                <p class="asc-stat-label">Storage Driver</p>
                <p class="asc-stat-value"><?php echo strtoupper(asc_storage_driver()); ?></p>
                <p class="asc-stat-note"><?php echo AscStorage::isS3() ? 'S3-compatible (remote)' : 'Local filesystem'; ?></p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top"><span class="asc-stat-icon asc-icon-info"><i class="fas fa-hard-drive"></i></span></div>
                <p class="asc-stat-label">Total Size</p>
                <p class="asc-stat-value"><?php echo e(fmt_bytes_short($totalSize)); ?></p>
                <p class="asc-stat-note"><?php echo number_format($fileCount); ?> file(s) in uploads/</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top"><span class="asc-stat-icon asc-icon-warning"><i class="fas fa-trash-can"></i></span></div>
                <p class="asc-stat-label">Orphaned Files</p>
                <p class="asc-stat-value"><?php echo number_format(count($orphans)); ?></p>
                <p class="asc-stat-note">Not referenced by any DB row</p>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="asc-stat-card">
                <div class="asc-stat-top"><span class="asc-stat-icon asc-icon-success"><i class="fas fa-database"></i></span></div>
                <p class="asc-stat-label">DB Stores</p>
                <p class="asc-stat-value"><?php echo count(array_filter($referenced)); ?></p>
                <p class="asc-stat-note">Paths, not image bytes</p>
            </div>
        </div>
    </div>

    <!-- Cleanup orphaned uploads -->
    <div class="asc-card mb-4">
        <div class="asc-card-head">
            <h4 class="asc-card-title">Orphaned Uploads</h4>
            <span class="text-muted small"><?php echo number_format(count($orphans)); ?> file(s) safe to delete</span>
        </div>
        <div class="asc-table-wrap">
            <table class="asc-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th class="text-end">Size</th>
                        <th>Last Modified</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orphans)): ?>
                        <tr>
                            <td colspan="3">
                                <div class="asc-empty"><i class="fas fa-circle-check"></i><p>No orphaned files — every upload is referenced.</p></div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach (array_slice($orphans, 0, 50, true) as $rel => $info): ?>
                        <tr>
                            <td class="font-monospace small"><?php echo e($rel); ?></td>
                            <td class="text-end text-muted"><?php echo e(fmt_bytes_short($info['size'])); ?></td>
                            <td class="text-muted"><?php echo e(date('d M Y H:i', $info['mtime'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (count($orphans) > 50): ?>
                        <tr><td colspan="3" class="text-muted small">… and <?php echo number_format(count($orphans) - 50); ?> more</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!empty($orphans)): ?>
        <div class="card-body pt-0 pb-3 px-4">
            <button type="button" class="asc-btn asc-btn-ghost" data-bs-toggle="modal" data-bs-target="#cleanupModal" style="color:#dc2626;">
                <i class="fas fa-eraser"></i> Delete <?php echo number_format(count($orphans)); ?> orphaned file(s)
            </button>
        </div>
        <?php endif; ?>
    </div>

    <!-- Largest files -->
    <div class="asc-card">
        <div class="asc-card-head">
            <h4 class="asc-card-title">Largest Files</h4>
            <span class="text-muted small">Top <?php echo count($largest); ?> by size</span>
        </div>
        <div class="asc-table-wrap">
            <table class="asc-table">
                <thead>
                    <tr>
                        <th>File</th>
                        <th class="text-end">Size</th>
                        <th>Last Modified</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($largest as $rel => $info): ?>
                        <tr>
                            <td class="font-monospace small"><?php echo e($rel); ?></td>
                            <td class="text-end fw-semibold"><?php echo e(fmt_bytes_short($info['size'])); ?></td>
                            <td class="text-muted"><?php echo e(date('d M Y H:i', $info['mtime'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Cleanup confirm modal -->
<div class="modal fade" id="cleanupModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <h5 class="modal-title fw-bold"><i class="fas fa-eraser me-2 text-danger"></i>Delete orphaned uploads?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">This permanently deletes <strong><?php echo number_format(count($orphans)); ?> file(s)</strong> from <code>uploads/</code> that are not referenced by any member, gallery, sponsor, or report in the database. This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="post" action="upload_storage.php" class="d-inline">
                    <?php echo csrf_field('upload_storage_cleanup'); ?>
                    <input type="hidden" name="cleanup_orphans" value="1">
                    <button type="submit" class="btn btn-danger"><i class="fas fa-trash me-1"></i>Delete Orphans</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
/** Byte formatter shared with this page. */
function fmt_bytes_short(int $bytes): string
{
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

/**
 * Delete uploads not referenced by any DB row. Returns the number removed.
 */
function asc_delete_orphaned_uploads($conn): int
{
    $uploadRoot = dirname(__DIR__) . '/uploads';
    if (!is_dir($uploadRoot)) {
        return 0;
    }

    $refTables = [
        'members'                   => 'profile_photo',
        'gallery_items'             => 'image_url',
        'gear_marketplace_listings' => 'item_image_url',
        'equipment_damage_reports'  => 'damage_photo_url',
        'sponsors'                  => 'logo_url',
        'sponsorship_campaigns'     => 'ad_image_url',
        'sponsors_extended'         => 'logo_url',
        'social_media_posts'        => 'image_url',
    ];
    $referenced = [];
    foreach ($refTables as $table => $col) {
        try {
            $r = $conn->query("SELECT $col FROM $table WHERE $col IS NOT NULL AND $col != ''");
            if ($r) {
                while ($row = $r->fetch_row()) {
                    $referenced[trim($row[0])] = true;
                }
                $r->free();
            }
        } catch (\Throwable $e) {
            // skip optional tables
        }
    }

    $removed = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if (!$file->isFile() || strpos($file->getFilename(), '.') === 0) {
            continue; // skip dotfiles (infrastructure, not user uploads)
        }
        $rel = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen(dirname(__DIR__)) + 1));
        if (!isset($referenced[$rel])) {
            if (@unlink($file->getPathname())) {
                $removed++;
            }
        }
    }
    return $removed;
}

include_once("../includes/footer.php");
