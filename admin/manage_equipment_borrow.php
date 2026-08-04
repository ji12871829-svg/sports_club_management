<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';

require_once __DIR__ . '/../includes/input_sanitize.php';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'return') {
        $id = (int)($_POST['loan_id'] ?? 0);
        $conn->query("UPDATE equipment_loans SET status='Returned', returned_at=NOW() WHERE loan_id=$id");
        $message = '<div class="alert alert-success">Marked returned.</div>';
    } elseif ($action === 'add') {
        $stmt = $conn->prepare("INSERT INTO equipment_loans (equipment_id,member_id,qty,due_date,notes) VALUES (?,?,?,?,?)");
        $eid = (int)$_POST['equipment_id'];
        $mid = (int)$_POST['member_id'];
        $qty = max(1, (int)$_POST['qty']);
        $due = $_POST['due_date'];
        $notes = trim($_POST['notes'] ?? '');
        $stmt->bind_param('iiiss', $eid, $mid, $qty, $due, $notes);
        $stmt->execute();
        $stmt->close();
        $message = '<div class="alert alert-success">Loan recorded.</div>';
    }
}

$conn->query("UPDATE equipment_loans SET status='Overdue' WHERE status='Active' AND due_date < CURDATE()");
$loans = $conn->query("
    SELECT l.*, e.name AS equipment_name, m.first_name, m.last_name
    FROM equipment_loans l
    JOIN equipment e ON e.equipment_id = l.equipment_id
    JOIN members m ON m.member_id = l.member_id
    ORDER BY l.status='Active' DESC, l.due_date ASC
")->fetch_all(MYSQLI_ASSOC);
$equipment = $conn->query("SELECT equipment_id, name, quantity FROM equipment ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$members = $conn->query("SELECT member_id, first_name, last_name FROM members ORDER BY first_name LIMIT 200")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<div class="container-fluid py-4">
    <h1 class="fw-bold fs-4 mb-3">Equipment borrow &amp; return</h1>
    <?php echo $message; ?>
    <div class="card border-0 shadow-sm p-3 mb-4">
        <h6 class="fw-bold">New loan</h6>
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="add">
            <div class="col-md-3"><select name="equipment_id" class="form-select" required><?php foreach ($equipment as $eq): ?><option value="<?php echo (int)$eq['equipment_id']; ?>"><?php echo e($eq['name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><select name="member_id" class="form-select" required><?php foreach ($members as $m): ?><option value="<?php echo (int)$m['member_id']; ?>"><?php echo e($m['first_name'].' '.$m['last_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-1"><input type="number" name="qty" value="1" min="1" class="form-control"></div>
            <div class="col-md-2"><input type="date" name="due_date" class="form-control" required value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>"></div>
            <div class="col-md-2"><input name="notes" class="form-control" placeholder="Notes"></div>
            <div class="col-md-1"><button class="btn btn-primary w-100">Add</button></div>
        </form>
    </div>
    <div class="table-responsive card border-0 shadow-sm">
        <table class="table mb-0">
            <thead><tr><th>Item</th><th>Member</th><th>Due</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($loans as $l): ?>
                <tr class="<?php echo $l['status']==='Overdue'?'table-warning':''; ?>">
                    <td><?php echo e($l['equipment_name']); ?> ×<?php echo (int)$l['qty']; ?></td>
                    <td><?php echo e($l['first_name'].' '.$l['last_name']); ?></td>
                    <td><?php echo e($l['due_date']); ?></td>
                    <td><span class="badge bg-<?php echo $l['status']==='Active'?'primary':($l['status']==='Overdue'?'warning':'success'); ?>"><?php echo e($l['status']); ?></span></td>
                    <td><?php if ($l['status']!=='Returned'): ?><form method="post" class="d-inline"><input type="hidden" name="action" value="return"><input type="hidden" name="loan_id" value="<?php echo (int)$l['loan_id']; ?>"><button class="btn btn-sm btn-success">Return</button></form><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
