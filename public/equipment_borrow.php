<?php
require_once '../includes/session_config.php';
asc_session_start();
if (empty($_SESSION['loggedin'])) { header('Location: login.php'); exit; }
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int)$_SESSION['member_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'borrow_csrf')) {
    $eid = (int)($_POST['equipment_id'] ?? 0);
    $qty = max(1, (int)($_POST['qty'] ?? 1));
    $due = $_POST['due_date'] ?? date('Y-m-d', strtotime('+7 days'));
    $notes = trim($_POST['notes'] ?? 'Member request');
    $stmt = $conn->prepare("INSERT INTO equipment_loans (equipment_id,member_id,qty,due_date,notes) VALUES (?,?,?,?,?)");
    $stmt->bind_param('iiiss', $eid, $member_id, $qty, $due, $notes);
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success">Borrow request submitted. Collect item at reception once approved.</div>';
    }
    $stmt->close();
}

$equipment = $conn->query("SELECT equipment_id, name, quantity FROM equipment WHERE quantity > 0 ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$my_loans = $conn->query("SELECT l.*, e.name FROM equipment_loans l JOIN equipment e ON e.equipment_id=l.equipment_id WHERE l.member_id=$member_id ORDER BY l.borrowed_at DESC LIMIT 20")->fetch_all(MYSQLI_ASSOC);
$conn->close();
include '../includes/header.php';
?>
<div class="container py-4" style="max-width:720px;">
    <h2 class="fw-bold mb-3">Borrow equipment</h2>
    <?php echo $message; ?>
    <form method="post" class="card border-0 shadow-sm p-4 mb-4">
        <?php echo csrf_field('borrow_csrf'); ?>
        <div class="mb-3"><label class="form-label">Item</label><select name="equipment_id" class="form-select" required><?php foreach ($equipment as $eq): ?><option value="<?php echo (int)$eq['equipment_id']; ?>"><?php echo e($eq['name'].' ('.$eq['quantity'].' avail)'); ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label">Quantity</label><input type="number" name="qty" value="1" min="1" class="form-control"></div>
        <div class="mb-3"><label class="form-label">Return by</label><input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>" required></div>
        <div class="mb-3"><textarea name="notes" class="form-control" rows="2" placeholder="Notes"></textarea></div>
        <button class="btn btn-primary">Request borrow</button>
    </form>
    <h5 class="fw-bold">Your loans</h5>
    <ul class="list-group"><?php foreach ($my_loans as $l): ?><li class="list-group-item d-flex justify-content-between"><span><?php echo e($l['name']); ?></span><span class="badge bg-secondary"><?php echo e($l['status']); ?> · due <?php echo e($l['due_date']); ?></span></li><?php endforeach; ?></ul>
</div>
<?php include '../includes/footer.php'; ?>
