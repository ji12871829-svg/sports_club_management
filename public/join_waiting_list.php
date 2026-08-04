<?php
require_once '../includes/session_config.php';
asc_session_start();
if (empty($_SESSION['loggedin'])) { header('Location: login.php'); exit; }
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int)$_SESSION['member_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'wait_csrf')) {
    $type = $_POST['resource_type'] ?? 'membership_plan';
    $rid = (int)($_POST['resource_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');
    if (!in_array($type, ['membership_plan','training_session','facility_booking'], true)) $type = 'membership_plan';
    $stmt = $conn->prepare("INSERT INTO waiting_list (member_id, resource_type, resource_id, notes) VALUES (?,?,?,?)");
    $stmt->bind_param('isis', $member_id, $type, $rid, $notes);
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success">You are on the waiting list. We will contact you when a slot opens.</div>';
    } else {
        $message = '<div class="alert alert-warning">You may already be on the list for this item.</div>';
    }
    $stmt->close();
}

$plans = $conn->query("SELECT plan_id, name FROM membership_plans ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$conn->close();
include '../includes/header.php';
?>
<div class="container py-4" style="max-width:560px;">
    <h2 class="fw-bold mb-3">Join waiting list</h2>
    <?php echo $message; ?>
    <form method="post" class="card border-0 shadow-sm p-4">
        <?php echo csrf_field('wait_csrf'); ?>
        <div class="mb-3">
            <label class="form-label">What are you waiting for?</label>
            <select name="resource_type" class="form-select">
                <option value="membership_plan">Membership plan</option>
                <option value="training_session">Training session</option>
                <option value="facility_booking">Facility booking</option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Plan / resource ID</label>
            <select name="resource_id" class="form-select">
                <?php foreach ($plans as $p): ?>
                    <option value="<?php echo (int)$p['plan_id']; ?>"><?php echo e($p['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <textarea name="notes" class="form-control mb-3" rows="2" placeholder="Notes (optional)"></textarea>
        <button class="btn btn-primary w-100">Join list</button>
    </form>
</div>
<?php include '../includes/footer.php'; ?>
