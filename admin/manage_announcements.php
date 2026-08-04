<?php
/**
 * admin/manage_announcements.php
 * Create, schedule, pin and delete club announcements.
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
        $title      = trim($_POST['title'] ?? '');
        $body       = trim($_POST['body']  ?? '');
        $publish_at = trim($_POST['publish_at'] ?? date('Y-m-d H:i:s'));
        $expires_at = trim($_POST['expires_at'] ?? '') ?: null;
        $is_pinned  = isset($_POST['is_pinned']) ? 1 : 0;

        if ($title && $body) {
            $stmt = $conn->prepare("INSERT INTO announcements (title, body, publish_at, expires_at, is_pinned, created_by) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("ssssii", $title, $body, $publish_at, $expires_at, $is_pinned, $admin_id);
            if ($stmt->execute()) {
                log_activity($conn, 'Created announcement', 'Announcements', $conn->insert_id, $title);
                $message = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>Announcement saved.</div>';
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM announcements WHERE announcement_id = $id");
        log_activity($conn, 'Deleted announcement', 'Announcements', $id);
        $message = '<div class="alert alert-success">Announcement deleted.</div>';
    } elseif ($action === 'toggle_pin') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("UPDATE announcements SET is_pinned = NOT is_pinned WHERE announcement_id = $id");
        $message = '<div class="alert alert-success">Pin status updated.</div>';
    }
}

$announcements = $conn->query("
    SELECT * FROM announcements ORDER BY is_pinned DESC, publish_at DESC
")->fetch_all(MYSQLI_ASSOC);

$conn->close();
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:#0ea5e9;">
            <i class="fas fa-bullhorn text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">Announcements</h1>
            <p class="text-muted mb-0 small">Schedule notices that appear on the public homepage</p>
        </div>
    </div>

    <?php if ($message) echo $message; ?>

    <div class="row g-4">
        <!-- Create form -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-plus-circle me-2 text-primary"></i>New Announcement
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="create">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Title</label>
                            <input type="text" name="title" class="form-control" required placeholder="e.g. Ground closed this weekend">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Message</label>
                            <textarea name="body" class="form-control" rows="4" required placeholder="Full announcement text..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Publish At</label>
                            <input type="datetime-local" name="publish_at" class="form-control"
                                   value="<?php echo date('Y-m-d\TH:i'); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Expires At <small class="text-muted">(optional)</small></label>
                            <input type="datetime-local" name="expires_at" class="form-control">
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_pinned" class="form-check-input" id="pin">
                            <label class="form-check-label" for="pin">📌 Pin to top of homepage</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-save me-2"></i>Save Announcement
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- List -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold d-flex align-items-center">
                    <i class="fas fa-list me-2"></i>All Announcements
                    <span class="badge bg-secondary ms-2"><?php echo count($announcements); ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($announcements)): ?>
                        <div class="text-center py-5 text-muted">No announcements yet.</div>
                    <?php else: ?>
                        <?php foreach ($announcements as $a):
                            $now        = new DateTime();
                            $publish_dt = new DateTime($a['publish_at']);
                            $is_live    = $publish_dt <= $now && (!$a['expires_at'] || new DateTime($a['expires_at']) > $now);
                        ?>
                        <div class="p-3 border-bottom <?php echo $a['is_pinned'] ? 'bg-warning bg-opacity-10' : ''; ?>">
                            <div class="d-flex align-items-start justify-content-between gap-3">
                                <div>
                                    <?php if ($a['is_pinned']): ?><span class="me-1">📌</span><?php endif; ?>
                                    <span class="fw-semibold"><?php echo e($a['title']); ?></span>
                                    <span class="badge ms-2 <?php echo $is_live ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $is_live ? 'Live' : ($publish_dt > $now ? 'Scheduled' : 'Expired'); ?>
                                    </span>
                                    <div class="text-muted small mt-1"><?php echo e(substr($a['body'], 0, 100)); ?>...</div>
                                    <div class="text-muted" style="font-size:.75rem;">
                                        Publish: <?php echo e(date('d M Y H:i', strtotime($a['publish_at']))); ?>
                                        <?php if ($a['expires_at']): ?> · Expires: <?php echo e(date('d M Y H:i', strtotime($a['expires_at']))); ?><?php endif; ?>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 flex-shrink-0">
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="action" value="toggle_pin">
                                        <input type="hidden" name="id" value="<?php echo e($a['announcement_id']); ?>">
                                        <button class="btn btn-sm btn-outline-warning" title="Toggle pin">
                                            <i class="fas fa-thumbtack"></i>
                                        </button>
                                    </form>
                                    <form method="POST" class="d-inline"
                                          onsubmit="return confirm('Delete this announcement?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo e($a['announcement_id']); ?>">
                                        <button class="btn btn-sm btn-outline-danger">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
