<?php
include_once("../includes/admin_header.php");
require_once __DIR__ . "/../config/db_connect.php";
require_once __DIR__ . '/../includes/csrf.php';

$message = "";
$name = $description = $condition = "";
$quantity = 0;

$name_err = $description_err = $quantity_err = $condition_err = "";

$edit_mode = false;
$equipment_id_to_edit = null;

/* =======================
   EDIT: Pre-fill form
======================= */
if (isset($_GET["action"]) && $_GET["action"] == "edit" && isset($_GET["id"])) {
    $edit_mode = true;
    $equipment_id_to_edit = (int) $_GET["id"];

    $sql = "SELECT name, description, quantity, `condition` FROM equipment WHERE equipment_id = ?";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $equipment_id_to_edit);

        if ($stmt->execute()) {
            $stmt->bind_result($name, $description, $quantity, $condition);
            $stmt->fetch();
        }

        $stmt->close();
    }
}

/* =======================
   ADD / UPDATE
======================= */
if ($_SERVER["REQUEST_METHOD"] == "POST" && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $quantity = trim($_POST["quantity"] ?? "");
    $condition = trim($_POST["condition"] ?? "");

    // Validation
    if (empty($name)) {
        $name_err = "Please enter equipment name.";
    }

    if (empty($description)) {
        $description_err = "Please enter a description.";
    }

    if ($quantity === "") {
        $quantity_err = "Please enter quantity.";
    } elseif (!filter_var($quantity, FILTER_VALIDATE_INT)) {
        $quantity_err = "Quantity must be an integer.";
    }

    if (empty($condition)) {
        $condition_err = "Please enter condition.";
    }

    // If no errors
    if (empty($name_err) && empty($description_err) && empty($quantity_err) && empty($condition_err)) {

        if (!empty($_POST["equipment_id"])) {
            // UPDATE
            $equipment_id = (int) $_POST["equipment_id"];

            $sql = "UPDATE equipment 
                    SET name = ?, description = ?, quantity = ?, `condition` = ? 
                    WHERE equipment_id = ?";

            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ssisi", $name, $description, $quantity, $condition, $equipment_id);

                if ($stmt->execute()) {
                    $message = "<div class='alert alert-success'>Equipment updated successfully.</div>";
                } else {
                    $message = "<div class='alert alert-danger'>Error updating equipment.</div>";
                }

                $stmt->close();
            }

        } else {
            // INSERT
            $sql = "INSERT INTO equipment (name, description, quantity, `condition`) 
                    VALUES (?, ?, ?, ?)";

            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ssis", $name, $description, $quantity, $condition);

                if ($stmt->execute()) {
                    $message = "<div class='alert alert-success'>Equipment added successfully.</div>";

                    // Clear form
                    $name = $description = $condition = "";
                    $quantity = 0;
                } else {
                    $message = "<div class='alert alert-danger'>Error adding equipment.</div>";
                }

                $stmt->close();
            }
        }
    }
}

/* =======================
   DELETE — POST-only with CSRF (was GET: trivially CSRF-able)
======================= */
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["action"] ?? '') == "delete" && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {

    $equipment_id = (int) ($_POST["id"] ?? 0);

    $sql = "DELETE FROM equipment WHERE equipment_id = ?";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $equipment_id);

        if ($stmt->execute()) {
            $message = "<div class='alert alert-success'>Equipment deleted successfully.</div>";
        } else {
            $message = "<div class='alert alert-danger'>Error deleting equipment.</div>";
        }

        $stmt->close();
    }
}

/* =======================
   FETCH ALL
======================= */
$equipment_list = [];

$sql = "SELECT equipment_id, name, description, quantity, `condition` FROM equipment";

if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $equipment_list[] = $row;
    }
    $result->free();
}

$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Manage Equipment</h2>
            </div>
            <div class="card-body">

                <?php echo $message; ?>

                <h3><?php echo ($edit_mode) ? "Edit Equipment" : "Add New Equipment"; ?></h3>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">

                    <?php echo csrf_field('admin_csrf'); ?>

                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="equipment_id" value="<?php echo (int) $equipment_id_to_edit; ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label>Equipment Name</label>
                        <input type="text" name="name" class="form-control <?php echo $name_err ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($name); ?>">
                        <span class="invalid-feedback"><?php echo $name_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label>Description</label>
                        <textarea name="description" class="form-control <?php echo $description_err ? 'is-invalid' : ''; ?>"><?php echo htmlspecialchars($description); ?></textarea>
                        <span class="invalid-feedback"><?php echo $description_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label>Quantity</label>
                        <input type="number" name="quantity" class="form-control <?php echo $quantity_err ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($quantity); ?>">
                        <span class="invalid-feedback"><?php echo $quantity_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label>Condition</label>
                        <input type="text" name="condition" class="form-control <?php echo $condition_err ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($condition); ?>">
                        <span class="invalid-feedback"><?php echo $condition_err; ?></span>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <?php echo ($edit_mode) ? "Update Equipment" : "Add Equipment"; ?>
                    </button>

                    <?php if ($edit_mode): ?>
                        <a href="manage_equipment.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>

                </form>

                <hr>

                <h3>All Equipment</h3>

                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Quantity</th>
                            <th>Condition</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php if (!empty($equipment_list)): ?>
                            <?php foreach ($equipment_list as $equipment): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($equipment["equipment_id"]); ?></td>
                                    <td><?php echo htmlspecialchars($equipment["name"]); ?></td>
                                    <td><?php echo htmlspecialchars($equipment["description"]); ?></td>
                                    <td><?php echo htmlspecialchars($equipment["quantity"]); ?></td>
                                    <td><?php echo htmlspecialchars($equipment["condition"]); ?></td>
                                    <td>
                                        <a href="?action=edit&id=<?php echo (int) $equipment["equipment_id"]; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $equipment["equipment_id"]; ?>">
                                            <?php echo csrf_field('admin_csrf'); ?>
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">No equipment found.</td>
                            </tr>
                        <?php endif; ?>

                    </tbody>
                </table>

            </div>
        </div>
    </div>
</div>

<?php include_once("../includes/footer.php"); ?>