<?php
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/csrf.php';

asc_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../config/db_connect.php';

$current_user_id = (int) $_SESSION['member_id'];
$message = '';
$equipment_err = $damage_desc_err = $qty_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'csrf_token')) {
        $message = "<div class='alert alert-danger'>Security check failed. Please refresh and try again.</div>";
    } else {
        $equipment_id       = intval($_POST['equipment_id'] ?? 0);
        $damage_description = trim($_POST['damage_description'] ?? '');
        $qty_damaged        = intval($_POST['qty_damaged'] ?? 1);

        if ($equipment_id <= 0)         $equipment_err   = 'Please select the equipment.';
        if (empty($damage_description)) $damage_desc_err = 'Please describe the damage.';
        if ($qty_damaged <= 0)          $qty_err         = 'Quantity damaged must be at least 1.';

        $current_qty = 0;
        if (empty($equipment_err)) {
            $chk = $conn->prepare('SELECT quantity FROM equipment WHERE equipment_id = ?');
            $chk->bind_param('i', $equipment_id);
            $chk->execute();
            $chk->bind_result($current_qty);
            $chk->fetch();
            $chk->close();

            if ($qty_damaged > $current_qty) {
                $qty_err = "Qty damaged ({$qty_damaged}) exceeds current stock ({$current_qty}).";
            }
        }

        if (empty($equipment_err) && empty($damage_desc_err) && empty($qty_err)) {
            $conn->begin_transaction();

            try {
                $sql = "INSERT INTO damage_reports
                            (equipment_id, reported_by, reported_by_role, damage_description, qty_damaged, fine_amount)
                        VALUES (?, ?, 'user', ?, ?, 0.00)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param('iisd', $equipment_id, $current_user_id, $damage_description, $qty_damaged);
                $stmt->execute();
                $stmt->close();

                $new_qty       = $current_qty - $qty_damaged;
                $new_condition = ($new_qty === 0) ? 'Damaged / Out of Stock' : 'Damaged';

                $upd = $conn->prepare('UPDATE equipment SET quantity = ?, `condition` = ? WHERE equipment_id = ?');
                $upd->bind_param('isi', $new_qty, $new_condition, $equipment_id);
                $upd->execute();
                $upd->close();

                $conn->commit();
                $message = "<div class='alert alert-warning'>
                                <strong>Report Submitted.</strong><br>
                                Your damage report has been received. An admin will review it and notify you of any fine that applies.
                            </div>";
            } catch (Exception $e) {
                $conn->rollback();
                $message = "<div class='alert alert-danger'>Something went wrong. Please try again.</div>";
            }
        }
    }
}

$equipment_list = [];
$sql = 'SELECT equipment_id, name, quantity FROM equipment WHERE quantity > 0 ORDER BY name';
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $equipment_list[] = $row;
    }
    $result->free();
}

$my_reports = [];
$sql = "SELECT dr.report_id, e.name AS equipment_name, dr.damage_description,
               dr.qty_damaged, dr.fine_amount, dr.fine_status, dr.reported_at
        FROM damage_reports dr
        JOIN equipment e ON dr.equipment_id = e.equipment_id
        WHERE dr.reported_by = ?
        ORDER BY dr.reported_at DESC";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('i', $current_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $my_reports[] = $row;
    }
    $stmt->close();
}

$conn->close();
include_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-md-8">

        <div class="card">
            <div class="card-header">
                <h2>Report Equipment Damage</h2>
                <p class="mb-0 text-muted">If you have damaged any equipment, please report it here honestly. An admin will review and confirm any applicable fine.</p>
            </div>
            <div class="card-body">

                <?php echo $message; ?>

                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                    <?php echo csrf_field('csrf_token'); ?>

                    <div class="mb-3">
                        <label>Equipment Damaged <span class="text-danger">*</span></label>
                        <select name="equipment_id" class="form-control <?php echo $equipment_err ? 'is-invalid' : ''; ?>">
                            <option value="">-- Select Equipment --</option>
                            <?php foreach ($equipment_list as $eq): ?>
                                <option value="<?php echo (int) $eq['equipment_id']; ?>">
                                    <?php echo htmlspecialchars($eq['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="invalid-feedback"><?php echo htmlspecialchars($equipment_err); ?></span>
                    </div>

                    <div class="mb-3">
                        <label>Describe the Damage <span class="text-danger">*</span></label>
                        <textarea name="damage_description" rows="4"
                                  class="form-control <?php echo $damage_desc_err ? 'is-invalid' : ''; ?>"
                                  placeholder="Explain what happened and the extent of the damage..."></textarea>
                        <span class="invalid-feedback"><?php echo htmlspecialchars($damage_desc_err); ?></span>
                    </div>

                    <div class="mb-3">
                        <label>Number of Units Damaged <span class="text-danger">*</span></label>
                        <input type="number" name="qty_damaged" value="1" min="1"
                               class="form-control <?php echo $qty_err ? 'is-invalid' : ''; ?>">
                        <span class="invalid-feedback"><?php echo htmlspecialchars($qty_err); ?></span>
                    </div>

                    <div class="alert alert-info">
                        <strong>Note:</strong> A fine will be determined by the admin after reviewing your report. You will be notified of the amount.
                    </div>

                    <button type="submit" class="btn btn-warning">Submit Damage Report</button>
                </form>

            </div>
        </div>

        <?php if (!empty($my_reports)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <h4>My Damage Reports</h4>
            </div>
            <div class="card-body">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Equipment</th>
                            <th>Description</th>
                            <th>Qty</th>
                            <th>Fine (KES)</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($my_reports as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r['equipment_name']); ?></td>
                                <td><?php echo htmlspecialchars($r['damage_description']); ?></td>
                                <td><?php echo (int) $r['qty_damaged']; ?></td>
                                <td>
                                    <?php if ($r['fine_amount'] > 0): ?>
                                        <strong>KES <?php echo number_format((float) $r['fine_amount'], 2); ?></strong>
                                    <?php else: ?>
                                        <em class="text-muted">Pending review</em>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                        $badge = match($r['fine_status']) {
                                            'paid'   => 'bg-success',
                                            'waived' => 'bg-warning text-dark',
                                            default  => 'bg-danger',
                                        };
                                    ?>
                                    <span class="badge <?php echo $badge; ?>">
                                        <?php echo htmlspecialchars(ucfirst($r['fine_status'])); ?>
                                    </span>
                                </td>
                                <td><?php echo date('d M Y', strtotime($r['reported_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include_once __DIR__ . '/../includes/footer.php'; ?>
