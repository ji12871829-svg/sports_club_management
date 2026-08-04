<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';

require_once __DIR__ . '/../includes/input_sanitize.php';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int)($_POST['wait_id'] ?? 0);
    $status = $_POST['status'] ?? 'Waiting';
    if (in_array($status, ['Waiting','Offered','Accepted','Cancelled'], true) && $id > 0) {
        $stmt = $conn->prepare("UPDATE waiting_list SET status=? WHERE wait_id=?");
        $stmt->bind_param('si', $status, $id);
        $stmt->execute();
        $stmt->close();
        $message = '<div class="alert alert-success">Updated.</div>';
    }
}

$rows = $conn->query("
    SELECT w.*, m.first_name, m.last_name, m.email
    FROM waiting_list w
    JOIN members m ON m.member_id = w.member_id
    ORDER BY w.created_at DESC
")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<div class="container-fluid py-4">
    <h1 class="fw-bold fs-4 mb-3">Waiting lists</h1>
    <?php echo $message; ?>
    <div class="table-responsive card border-0 shadow-sm">
        <table class="table mb-0">
            <thead><tr><th>Member</th><th>Type</th><th>Resource ID</th><th>Status</th><th>Joined</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo e($r['first_name'].' '.$r['last_name']); ?><br><small><?php echo e($r['email']); ?></small></td>
                    <td><?php echo e($r['resource_type']); ?></td>
                    <td>#<?php echo (int)$r['resource_id']; ?></td>
                    <td><span class="badge bg-info"><?php echo e($r['status']); ?></span></td>
                    <td><?php echo e($r['created_at']); ?></td>
                    <td>
                        <form method="post" class="d-flex gap-1">
                            <input type="hidden" name="wait_id" value="<?php echo (int)$r['wait_id']; ?>">
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach (['Waiting','Offered','Accepted','Cancelled'] as $st): ?>
                                    <option value="<?php echo $st; ?>" <?php echo $r['status']===$st?'selected':''; ?>><?php echo $st; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-primary">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
