<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';

require_once __DIR__ . '/../includes/input_sanitize.php';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $stmt = $conn->prepare("INSERT INTO member_injuries (member_id,injury_date,body_area,description,recovery_status,notes) VALUES (?,?,?,?,?,?)");
    $mid = (int)$_POST['member_id'];
    $date = $_POST['injury_date'];
    $area = trim($_POST['body_area'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $status = $_POST['recovery_status'] ?? 'Active';
    $notes = trim($_POST['notes'] ?? '');
    $stmt->bind_param('isssss', $mid, $date, $area, $desc, $status, $notes);
    $stmt->execute();
    $stmt->close();
    log_activity($conn, 'Logged injury record', 'Members', $mid);
    $message = '<div class="alert alert-success">Injury record saved.</div>';
}

$injuries = $conn->query("
    SELECT i.*, m.first_name, m.last_name FROM member_injuries i
    JOIN members m ON m.member_id = i.member_id
    ORDER BY i.injury_date DESC LIMIT 80
")->fetch_all(MYSQLI_ASSOC);
$members = $conn->query("SELECT member_id, first_name, last_name FROM members ORDER BY first_name")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<div class="container-fluid py-4">
    <h1 class="fw-bold fs-4 mb-3">Injury &amp; medical log</h1>
    <?php echo $message; ?>
    <div class="card border-0 shadow-sm p-3 mb-4">
        <form method="post" class="row g-2">
            <input type="hidden" name="action" value="add">
            <div class="col-md-3"><select name="member_id" class="form-select" required><?php foreach ($members as $m): ?><option value="<?php echo (int)$m['member_id']; ?>"><?php echo e($m['first_name'].' '.$m['last_name']); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><input type="date" name="injury_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
            <div class="col-md-2"><input name="body_area" class="form-control" placeholder="Body area"></div>
            <div class="col-md-2"><select name="recovery_status" class="form-select"><option>Active</option><option>Recovering</option><option>Cleared</option></select></div>
            <div class="col-md-3"><input name="description" class="form-control" placeholder="Description" required></div>
            <div class="col-12"><button class="btn btn-primary">Add record</button></div>
        </form>
    </div>
    <div class="table-responsive card border-0 shadow-sm">
        <table class="table table-sm mb-0">
            <thead><tr><th>Member</th><th>Date</th><th>Area</th><th>Status</th><th>Description</th></tr></thead>
            <tbody>
            <?php foreach ($injuries as $i): ?>
                <tr>
                    <td><?php echo e($i['first_name'].' '.$i['last_name']); ?></td>
                    <td><?php echo e($i['injury_date']); ?></td>
                    <td><?php echo e($i['body_area']); ?></td>
                    <td><?php echo e($i['recovery_status']); ?></td>
                    <td><?php echo e($i['description']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
