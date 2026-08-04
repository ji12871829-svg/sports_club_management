<?php
include_once("../includes/admin_header.php");
require_once __DIR__ . "/../config/db_connect.php";
require_once __DIR__ . "/../includes/csrf.php";

$message = "";

/* =======================
   UPDATE FINE STATUS / DELETE (POST + CSRF)
======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = "<div class='alert alert-danger'>Security check failed.</div>";
    } elseif (in_array($action, ['mark_paid', 'mark_waived'], true)) {
        $report_id = (int) ($_POST['id'] ?? 0);
        $new_status = ($action === 'mark_paid') ? 'paid' : 'waived';
        $resolved = date('Y-m-d H:i:s');
        $sql = "UPDATE damage_reports SET fine_status = ?, resolved_at = ? WHERE report_id = ?";

        if ($report_id > 0 && ($stmt = $conn->prepare($sql))) {
            $stmt->bind_param('ssi', $new_status, $resolved, $report_id);
            if ($stmt->execute()) {
                $label = ($new_status === 'paid') ? 'paid' : 'waived';
                $message = "<div class='alert alert-success'>Fine marked as {$label}.</div>";
            } else {
                $message = "<div class='alert alert-danger'>Error updating fine status.</div>";
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $report_id = (int) ($_POST['id'] ?? 0);
        $sql = "DELETE FROM damage_reports WHERE report_id = ?";

        if ($report_id > 0 && ($stmt = $conn->prepare($sql))) {
            $stmt->bind_param('i', $report_id);
            if ($stmt->execute()) {
                $message = "<div class='alert alert-success'>Damage report deleted.</div>";
            } else {
                $message = "<div class='alert alert-danger'>Error deleting report.</div>";
            }
            $stmt->close();
        }
    }
}

/* =======================
   ADD NEW DAMAGE REPORT  (Admin side)
======================= */
$reported_by_err = $equipment_err = $damage_desc_err = $qty_err = $fine_err = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["form_type"]) && $_POST["form_type"] === "admin_report") {

    $equipment_id      = intval($_POST["equipment_id"] ?? 0);
    $reported_by       = intval($_POST["reported_by"] ?? 0);      // user_id
    $damage_description = trim($_POST["damage_description"] ?? "");
    $qty_damaged       = intval($_POST["qty_damaged"] ?? 1);
    $fine_amount       = trim($_POST["fine_amount"] ?? "");
    $notes             = trim($_POST["notes"] ?? "");

    // Validation
    if ($equipment_id <= 0)       $equipment_err  = "Please select equipment.";
    if ($reported_by <= 0)        $reported_by_err = "Please enter a valid User ID.";
    if (empty($damage_description)) $damage_desc_err = "Please describe the damage.";
    if ($qty_damaged <= 0)        $qty_err        = "Quantity damaged must be at least 1.";
    if ($fine_amount === "" || !is_numeric($fine_amount) || $fine_amount < 0)
                                  $fine_err       = "Please enter a valid fine amount (0 or more).";

    // Check equipment exists and has enough quantity
    $current_qty = 0;
    if (empty($equipment_err)) {
        $chk = $conn->prepare("SELECT quantity FROM equipment WHERE equipment_id = ?");
        $chk->bind_param("i", $equipment_id);
        $chk->execute();
        $chk->bind_result($current_qty);
        $chk->fetch();
        $chk->close();

        if ($qty_damaged > $current_qty) {
            $qty_err = "Qty damaged ({$qty_damaged}) exceeds current stock ({$current_qty}).";
        }
    }

    if (empty($equipment_err) && empty($reported_by_err) && empty($damage_desc_err) && empty($qty_err) && empty($fine_err)) {

        $conn->begin_transaction();

        try {
            // 1. Insert damage report
            $sql = "INSERT INTO damage_reports 
                        (equipment_id, reported_by, reported_by_role, damage_description, qty_damaged, fine_amount, notes)
                    VALUES (?, ?, 'admin', ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iisids", $equipment_id, $reported_by, $damage_description, $qty_damaged, $fine_amount, $notes);
            $stmt->execute();
            $stmt->close();

            // 2. Reduce equipment quantity
            $new_qty = $current_qty - $qty_damaged;
            $new_condition = ($new_qty === 0) ? "Damaged / Out of Stock" : "Damaged";

            $upd = $conn->prepare("UPDATE equipment SET quantity = ?, `condition` = ? WHERE equipment_id = ?");
            $upd->bind_param("isi", $new_qty, $new_condition, $equipment_id);
            $upd->execute();
            $upd->close();

            $conn->commit();
            $message = "<div class='alert alert-success'>Damage report logged. Fine of <strong>KES " . number_format($fine_amount, 2) . "</strong> has been issued.</div>";

        } catch (Exception $e) {
            $conn->rollback();
            $message = "<div class='alert alert-danger'>Transaction failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

/* =======================
   FETCH ALL REPORTS
======================= */
$reports = [];

$sql = "SELECT 
            dr.report_id,
            dr.reported_by,
            dr.reported_by_role,
            dr.damage_description,
            dr.qty_damaged,
            dr.fine_amount,
            dr.fine_status,
            dr.reported_at,
            dr.resolved_at,
            dr.notes,
            e.name AS equipment_name,
            e.quantity AS current_qty
        FROM damage_reports dr
        JOIN equipment e ON dr.equipment_id = e.equipment_id
        ORDER BY dr.reported_at DESC";

if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $reports[] = $row;
    }
    $result->free();
}

/* =======================
   FETCH EQUIPMENT LIST  (for dropdown)
======================= */
$equipment_list = [];
$sql = "SELECT equipment_id, name, quantity FROM equipment ORDER BY name";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $equipment_list[] = $row;
    }
    $result->free();
}

/* =======================
   SUMMARY STATS
======================= */
$total_fines  = 0;
$unpaid_fines = 0;
$total_reports = count($reports);

foreach ($reports as $r) {
    $total_fines += $r["fine_amount"];
    if ($r["fine_status"] === "unpaid") {
        $unpaid_fines += $r["fine_amount"];
    }
}

$conn->close();
?>

<div class="row">
    <div class="col-md-12">

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card text-white bg-secondary">
                    <div class="card-body text-center">
                        <h5 class="card-title">Total Reports</h5>
                        <h2><?php echo $total_reports; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-primary">
                    <div class="card-body text-center">
                        <h5 class="card-title">Total Fines Issued</h5>
                        <h2>KES <?php echo number_format($total_fines, 2); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card text-white bg-danger">
                    <div class="card-body text-center">
                        <h5 class="card-title">Outstanding (Unpaid)</h5>
                        <h2>KES <?php echo number_format($unpaid_fines, 2); ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2>Damage Reports &amp; Fines</h2>
                <a href="manage_equipment.php" class="btn btn-outline-secondary btn-sm">← Back to Equipment</a>
            </div>
            <div class="card-body">

                <?php echo $message; ?>

                <!-- ========================
                     LOG NEW DAMAGE (Admin)
                ========================= -->
                <h4>Log New Damage Incident</h4>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <input type="hidden" name="form_type" value="admin_report">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Equipment <span class="text-danger">*</span></label>
                            <select name="equipment_id" class="form-control <?php echo $equipment_err ? 'is-invalid' : ''; ?>">
                                <option value="">-- Select Equipment --</option>
                                <?php foreach ($equipment_list as $eq): ?>
                                    <option value="<?php echo $eq["equipment_id"]; ?>">
                                        <?php echo htmlspecialchars($eq["name"]); ?> (Stock: <?php echo $eq["quantity"]; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="invalid-feedback"><?php echo $equipment_err; ?></span>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label>User ID (Person Responsible) <span class="text-danger">*</span></label>
                            <input type="number" name="reported_by" class="form-control <?php echo $reported_by_err ? 'is-invalid' : ''; ?>"
                                   placeholder="Enter the user's ID" min="1">
                            <span class="invalid-feedback"><?php echo $reported_by_err; ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label>Damage Description <span class="text-danger">*</span></label>
                        <textarea name="damage_description" rows="3"
                                  class="form-control <?php echo $damage_desc_err ? 'is-invalid' : ''; ?>"
                                  placeholder="Describe what was damaged and how..."></textarea>
                        <span class="invalid-feedback"><?php echo $damage_desc_err; ?></span>
                    </div>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label>Quantity Damaged <span class="text-danger">*</span></label>
                            <input type="number" name="qty_damaged" value="1" min="1"
                                   class="form-control <?php echo $qty_err ? 'is-invalid' : ''; ?>">
                            <span class="invalid-feedback"><?php echo $qty_err; ?></span>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label>Fine Amount (KES) <span class="text-danger">*</span></label>
                            <input type="number" name="fine_amount" step="0.01" min="0"
                                   class="form-control <?php echo $fine_err ? 'is-invalid' : ''; ?>"
                                   placeholder="e.g. 500.00">
                            <span class="invalid-feedback"><?php echo $fine_err; ?></span>
                        </div>

                        <div class="col-md-4 mb-3">
                            <label>Admin Notes (optional)</label>
                            <input type="text" name="notes" class="form-control"
                                   placeholder="Any additional notes...">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-exclamation-triangle"></i> Log Damage &amp; Issue Fine
                    </button>
                </form>

                <hr>

                <!-- ========================
                     ALL DAMAGE REPORTS
                ========================= -->
                <h4>All Damage Reports</h4>

                <?php if (!empty($reports)): ?>
                <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th>#</th>
                            <th>Equipment</th>
                            <th>User ID</th>
                            <th>Reported By</th>
                            <th>Description</th>
                            <th>Qty Damaged</th>
                            <th>Fine (KES)</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reports as $r): ?>
                            <tr>
                                <td><?php echo $r["report_id"]; ?></td>
                                <td><?php echo htmlspecialchars($r["equipment_name"]); ?></td>
                                <td><?php echo htmlspecialchars($r["reported_by"]); ?></td>
                                <td>
                                    <span class="badge <?php echo $r["reported_by_role"] === 'admin' ? 'bg-dark' : 'bg-secondary'; ?>">
                                        <?php echo ucfirst($r["reported_by_role"]); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($r["damage_description"]); ?>
                                    <?php if (!empty($r["notes"])): ?>
                                        <br><small class="text-muted"><em>Note: <?php echo htmlspecialchars($r["notes"]); ?></em></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $r["qty_damaged"]; ?></td>
                                <td class="fw-bold"><?php echo number_format($r["fine_amount"], 2); ?></td>
                                <td>
                                    <?php
                                        $badge = match($r["fine_status"]) {
                                            "paid"   => "bg-success",
                                            "waived" => "bg-warning text-dark",
                                            default  => "bg-danger",
                                        };
                                    ?>
                                    <span class="badge <?php echo $badge; ?>">
                                        <?php echo ucfirst($r["fine_status"]); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo date("d M Y", strtotime($r["reported_at"])); ?>
                                    <?php if ($r["resolved_at"]): ?>
                                        <br><small class="text-success">Resolved: <?php echo date("d M Y", strtotime($r["resolved_at"])); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r["fine_status"] === "unpaid"): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Mark this fine as paid?');">
                                            <?php echo csrf_field('admin_csrf'); ?>
                                            <input type="hidden" name="action" value="mark_paid">
                                            <input type="hidden" name="id" value="<?php echo (int) $r['report_id']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm mb-1">Paid</button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Waive this fine?');">
                                            <?php echo csrf_field('admin_csrf'); ?>
                                            <input type="hidden" name="action" value="mark_waived">
                                            <input type="hidden" name="id" value="<?php echo (int) $r['report_id']; ?>">
                                            <button type="submit" class="btn btn-warning btn-sm mb-1">Waive</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this report? This cannot be undone.');">
                                        <?php echo csrf_field('admin_csrf'); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int) $r['report_id']; ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                    <div class="alert alert-info">No damage reports on record.</div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<?php include_once("../includes/footer.php"); ?>