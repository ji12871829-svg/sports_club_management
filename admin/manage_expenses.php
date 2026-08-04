<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $cat = trim($_POST['category'] ?? '');
        $amt = (float)($_POST['amount'] ?? 0);
        $desc = trim($_POST['description'] ?? '');
        $date = $_POST['expense_date'] ?? date('Y-m-d');
        $stmt = $conn->prepare("INSERT INTO club_expenses (category, amount, description, expense_date) VALUES (?,?,?,?)");
        $stmt->bind_param('sdss', $cat, $amt, $desc, $date);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'Added expense', 'Finance', $conn->insert_id, $cat);
        $message = '<div class="alert alert-success">Expense recorded.</div>';
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM club_expenses WHERE expense_id = $id");
        $message = '<div class="alert alert-success">Deleted.</div>';
    }
}

$expenses = $conn->query("SELECT * FROM club_expenses ORDER BY expense_date DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
$total = (float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM club_expenses")->fetch_row()[0];
$conn->close();
?>
<div class="container-fluid py-4">
    <h1 class="fw-bold fs-4 mb-1">Club expenses</h1>
    <p class="text-muted">Total recorded: <strong>KES <?php echo number_format($total, 2); ?></strong> · <a href="revenue_dashboard.php">Income vs expenses chart</a></p>
    <?php echo $message; ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm p-3">
                <h6 class="fw-bold">Add expense</h6>
                <form method="post">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-2"><input name="category" class="form-control" placeholder="Category" required></div>
                    <div class="mb-2"><input name="amount" type="number" step="0.01" class="form-control" placeholder="Amount (KES)" required></div>
                    <div class="mb-2"><input name="expense_date" type="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
                    <div class="mb-2"><textarea name="description" class="form-control" rows="2" placeholder="Description"></textarea></div>
                    <button class="btn btn-primary w-100">Save</button>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Date</th><th>Category</th><th>Amount</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($expenses as $x): ?>
                            <tr>
                                <td><?php echo e($x['expense_date']); ?></td>
                                <td><?php echo e($x['category']); ?><br><small class="text-muted"><?php echo e($x['description']); ?></small></td>
                                <td>KES <?php echo number_format((float)$x['amount'], 2); ?></td>
                                <td>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$x['expense_id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger">×</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
