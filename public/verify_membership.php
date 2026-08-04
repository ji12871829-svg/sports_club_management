<?php
require_once '../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/membership_gate.php';
require_once '../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/csrf.php';

$mid   = (int)($_GET['mid'] ?? $_POST['mid'] ?? 0);
$token = trim($_GET['t'] ?? $_POST['t'] ?? '');
$code  = trim($_GET['code'] ?? $_POST['code'] ?? '');
$message = '';
$member  = null;
$is_admin = !empty($_SESSION['admin_loggedin']);

if ($code !== '' && preg_match('/^ASC(\d+)-([a-f0-9]{16})$/i', $code, $m)) {
    $mid   = (int)$m[1];
    $token = $m[2];
}

if ($mid > 0) {
    $stmt = $conn->prepare(
        "SELECT m.member_id, m.first_name, m.last_name, m.email, m.phone_number, m.profile_photo,
                mm.status AS mem_status, mm.end_date, mp.name AS plan_name
         FROM members m
         LEFT JOIN member_memberships mm ON mm.member_id = m.member_id AND mm.status = 'Active'
         LEFT JOIN membership_plans mp ON mp.plan_id = mm.plan_id
         WHERE m.member_id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $mid);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$valid = $member && membership_gate_valid((int)$member['member_id'], (string)$member['email'], $token);

if ($is_admin && $valid && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_in' && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
    $message = '<div class="alert alert-success">Member checked in at gate: ' . e($member['first_name'] . ' ' . $member['last_name']) . '.</div>';
}

include_once '../includes/header.php';
?>

<div class="container py-4" style="max-width:640px;">
    <h2 class="fw-bold mb-3"><i class="fas fa-id-card me-2 text-primary"></i>Membership verification</h2>
    <?php echo $message; ?>

    <form method="get" class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <label class="form-label small text-muted">Scan code or enter ASC code</label>
            <input type="text" name="code" class="form-control font-monospace" placeholder="ASC123-abc..." value="<?php echo e($code); ?>">
            <button type="submit" class="btn btn-primary mt-3 w-100">Verify</button>
        </div>
    </form>

    <?php if ($member && $valid): ?>
        <?php
        $expired = !empty($member['end_date']) && strtotime($member['end_date']) < time();
        $active  = ($member['mem_status'] ?? '') === 'Active' && !$expired;
        ?>
        <div class="card border-0 shadow-sm border-start border-4 <?php echo $active ? 'border-success' : 'border-danger'; ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h4 class="mb-1"><?php echo e($member['first_name'] . ' ' . $member['last_name']); ?></h4>
                        <p class="text-muted small mb-2">#<?php echo (int)$member['member_id']; ?> · <?php echo e($member['email']); ?></p>
                    </div>
                    <span class="badge <?php echo $active ? 'bg-success' : 'bg-danger'; ?> fs-6">
                        <?php echo $active ? 'VALID' : 'NOT ACTIVE'; ?>
                    </span>
                </div>
                <p class="mb-1"><strong>Plan:</strong> <?php echo e($member['plan_name'] ?? 'None'); ?></p>
                <?php if (!empty($member['end_date'])): ?>
                    <p class="mb-0"><strong>Expires:</strong> <?php echo e(date('d M Y', strtotime($member['end_date']))); ?></p>
                <?php endif; ?>
                <?php if ($is_admin && $active): ?>
                    <form method="post" class="mt-3">
                        <?php echo csrf_field('admin_csrf'); ?>
                        <input type="hidden" name="mid" value="<?php echo (int)$mid; ?>">
                        <input type="hidden" name="t" value="<?php echo e($token); ?>">
                        <input type="hidden" name="action" value="check_in">
                        <button type="submit" class="btn btn-success w-100"><i class="fas fa-door-open me-1"></i> Gate check-in</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php elseif ($member): ?>
        <div class="alert alert-danger">Invalid or expired verification token.</div>
    <?php elseif ($mid > 0 || $code !== ''): ?>
        <div class="alert alert-warning">Member not found.</div>
    <?php endif; ?>
</div>

<?php include_once '../includes/footer.php'; $conn->close(); ?>
