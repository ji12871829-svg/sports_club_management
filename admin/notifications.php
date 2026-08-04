<?php
/**
 * admin/notifications.php
 * Admin notification center — shows unread alerts from across the system.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

// Mark actions now use POST + CSRF (the admin header enforces centrally
// via csrf_valid_any). The GET-based variants still work as a fallback
// for the UI links but are kept for backward compatibility — the admin
// header's JS interceptor stamps the CSRF token on POST forms.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_all_read') {
        $conn->query("UPDATE admin_notifications SET is_read = 1");
        header('Location: notifications.php'); exit;
    }
    if ($action === 'mark_read' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        $conn->query("UPDATE admin_notifications SET is_read = 1 WHERE notification_id = $id");
        $link = $_POST['redirect'] ?? 'notifications.php';
        header('Location: ' . $link); exit;
    }
}

// Legacy GET-based fallback (still supported for direct links, but
// eventually all UI should POST).
if (($_GET['action'] ?? '') === 'mark_all_read') {
    $conn->query("UPDATE admin_notifications SET is_read = 1");
    header('Location: notifications.php'); exit;
}
if (($_GET['action'] ?? '') === 'mark_read' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $conn->query("UPDATE admin_notifications SET is_read = 1 WHERE notification_id = $id");
    $link = $_GET['redirect'] ?? 'notifications.php';
    header('Location: ' . $link); exit;
}

$filter   = $_GET['filter'] ?? 'all';
$where    = $filter === 'unread' ? 'WHERE is_read = 0' : '';
$notifs   = $conn->query("SELECT * FROM admin_notifications $where ORDER BY created_at DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
$unread   = (int)$conn->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0")->fetch_row()[0];
$conn->close();

$icons = [
    'damage_report'      => ['fas fa-tools',           'text-danger'],
    'payment_failed'     => ['fas fa-credit-card',     'text-danger'],
    'membership_expiry'  => ['fas fa-clock',            'text-warning'],
    'new_member'         => ['fas fa-user-plus',        'text-success'],
    'booking'            => ['fas fa-calendar-check',   'text-primary'],
    'equipment_low'      => ['fas fa-box-open',         'text-warning'],
    'fixture'            => ['fas fa-futbol',           'text-info'],
];
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:#6366f1;">
            <i class="fas fa-bell text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">Notification Center</h1>
            <p class="text-muted mb-0 small"><?php echo $unread; ?> unread alert(s)</p>
        </div>
        <?php if ($unread > 0): ?>
        <form method="post" class="d-inline ms-auto">
            <input type="hidden" name="action" value="mark_all_read">
            <button type="submit" class="btn btn-sm btn-outline-secondary">
                <i class="fas fa-check-double me-1"></i> Mark All Read
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Filter tabs -->
    <ul class="nav nav-tabs mb-4">
        <li class="nav-item">
            <a class="nav-link <?php echo $filter === 'all' ? 'active' : ''; ?>" href="?filter=all">
                All <span class="badge bg-secondary ms-1"><?php echo count($notifs); ?></span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?php echo $filter === 'unread' ? 'active' : ''; ?>" href="?filter=unread">
                Unread <span class="badge bg-danger ms-1"><?php echo $unread; ?></span>
            </a>
        </li>
    </ul>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <?php if (empty($notifs)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-bell-slash fa-2x mb-3 d-block"></i>
                    No notifications yet.
                </div>
            <?php else: ?>
                <?php foreach ($notifs as $n):
                    $ic = $icons[$n['type']] ?? ['fas fa-info-circle', 'text-secondary'];
                    $bg = $n['is_read'] ? '' : 'style="background:#f8f9ff;"';
                ?>
                <div class="d-flex align-items-start gap-3 p-3 border-bottom <?php echo !$n['is_read'] ? 'bg-light' : ''; ?>">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:40px;height:40px;background:#f1f5f9;">
                        <i class="<?php echo $ic[0]; ?> <?php echo $ic[1]; ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold <?php echo !$n['is_read'] ? '' : 'text-muted'; ?>">
                            <?php echo e($n['title']); ?>
                            <?php if (!$n['is_read']): ?>
                                <span class="badge bg-danger ms-1" style="font-size:.65rem;">NEW</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($n['message']): ?>
                            <div class="text-muted small"><?php echo e($n['message']); ?></div>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:.75rem;">
                            <?php echo e(date('d M Y H:i', strtotime($n['created_at']))); ?>
                        </div>
                    </div>
                    <div class="d-flex gap-2 flex-shrink-0">
                        <?php if ($n['link_url']): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="id" value="<?php echo e($n['notification_id']); ?>">
                                <input type="hidden" name="redirect" value="<?php echo e($n['link_url']); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary">View</button>
                            </form>
                        <?php endif; ?>
                        <?php if (!$n['is_read']): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="mark_read">
                                <input type="hidden" name="id" value="<?php echo e($n['notification_id']); ?>">
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    <i class="fas fa-check"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
