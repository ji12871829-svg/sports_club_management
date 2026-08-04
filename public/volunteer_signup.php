<?php
require_once '../includes/session_config.php';
asc_session_start();
if (empty($_SESSION['loggedin'])) { header('Location: login.php'); exit; }
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int)$_SESSION['member_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'vol_csrf')) {
    $eid = (int)$_POST['event_id'];
    $role = trim($_POST['role_note'] ?? '');
    $stmt = $conn->prepare("INSERT INTO volunteer_signups (event_id, member_id, role_note) VALUES (?,?,?)");
    $stmt->bind_param('iis', $eid, $member_id, $role);
  if ($stmt->execute()) {
        $message = '<div class="alert alert-success">You are registered. Admin will confirm your slot.</div>';
    } else {
        $message = '<div class="alert alert-warning">Already registered or event full.</div>';
    }
    $stmt->close();
}

$events = $conn->query("SELECT * FROM volunteer_events WHERE is_active=1 AND event_date >= CURDATE() ORDER BY event_date")->fetch_all(MYSQLI_ASSOC);
$conn->close();
include '../includes/header.php';
?>
<div class="container py-4">
    <h2 class="fw-bold mb-3">Volunteer sign-up</h2>
    <?php echo $message; ?>
    <div class="row g-3">
        <?php foreach ($events as $ev): ?>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h5 class="fw-bold"><?php echo e($ev['title']); ?></h5>
                    <p class="text-muted small mb-2"><?php echo e($ev['event_date']); ?> <?php echo $ev['event_time'] ? e(substr($ev['event_time'],0,5)) : ''; ?> · <?php echo e($ev['venue']); ?></p>
                    <p class="small"><?php echo e($ev['description']); ?></p>
                    <form method="post">
                        <?php echo csrf_field('vol_csrf'); ?>
                        <input type="hidden" name="event_id" value="<?php echo (int)$ev['event_id']; ?>">
                        <input name="role_note" class="form-control form-control-sm mb-2" placeholder="Preferred role (optional)">
                        <button class="btn btn-sm btn-primary">Sign up</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php include '../includes/footer.php'; ?>
