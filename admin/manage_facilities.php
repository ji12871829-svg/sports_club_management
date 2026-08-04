<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once __DIR__ . '/../includes/cache.php';
require_once __DIR__ . '/../includes/csrf.php';

// Invalidate the facilities list cache on any POST/action that mutates data.
if (($_SERVER['REQUEST_METHOD'] === 'POST') || (isset($_GET['action']) && $_GET['action'] === 'edit')) {
    cache_delete('mg_facilities');
}

$message = "";
$name = $location = $type = "";
$capacity = 0;
$name_err = $location_err = $type_err = $capacity_err = "";
$edit_mode = false;
$facility_id_to_edit = null;

// Handle Edit form pre-fill
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $edit_mode = true;
    $facility_id_to_edit = (int) $_GET['id'];
    $sql = "SELECT name, location, type, capacity FROM facilities WHERE facility_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $facility_id_to_edit);
        if ($stmt->execute()) {
            $stmt->store_result();
            if ($stmt->num_rows == 1) {
                $stmt->bind_result($name, $location, $type, $capacity);
                $stmt->fetch();
            }
        }
        $stmt->close();
    }
}

// Handle Add/Edit operation
if ($_SERVER["REQUEST_METHOD"] == "POST" && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
    // Validate name
    if (empty(trim($_POST["name"]))) {
        $name_err = "Please enter a facility name.";
    } else {
        $name = trim($_POST["name"]);
    }

    // Validate location
    if (empty(trim($_POST["location"]))) {
        $location_err = "Please enter a location.";
    } else {
        $location = trim($_POST["location"]);
    }

    // Validate type
    if (empty(trim($_POST["type"]))) {
        $type_err = "Please enter a type.";
    } else {
        $type = trim($_POST["type"]);
    }

    // Validate capacity
    if (empty(trim($_POST["capacity"]))) {
        $capacity_err = "Please enter capacity.";
    } elseif (!filter_var(trim($_POST["capacity"]), FILTER_VALIDATE_INT)) {
        $capacity_err = "Capacity must be an integer.";
    } else {
        $capacity = trim($_POST["capacity"]);
    }

    // Check input errors before inserting/updating in database
    if (empty($name_err) && empty($location_err) && empty($type_err) && empty($capacity_err)) {
        if (isset($_POST["facility_id"]) && !empty($_POST["facility_id"])) {
            // Update operation
            $facility_id = (int) $_POST["facility_id"];
            $sql = "UPDATE facilities SET name = ?, location = ?, type = ?, capacity = ? WHERE facility_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("sssii", $name, $location, $type, $capacity, $facility_id);
                if ($stmt->execute()) {
                    $message = "<div class='alert alert-success'>Facility updated successfully.</div>";
                } else {
                    $message = "<div class='alert alert-danger'>Error updating facility: " . $stmt->error . "</div>";
                }
                $stmt->close();
            }
        } else {
            // Insert operation
            $sql = "INSERT INTO facilities (name, location, type, capacity) VALUES (?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("sssi", $name, $location, $type, $capacity);
                if ($stmt->execute()) {
                    $message = "<div class='alert alert-success'>Facility added successfully.</div>";
                    $name = $location = $type = "";
                    $capacity = 0; // Clear form fields
                } else {
                    $message = "<div class='alert alert-danger'>Error adding facility: " . $stmt->error . "</div>";
                }
                $stmt->close();
            }
        }
    }
}

// Handle Delete operation — POST-only with CSRF (was GET: trivially CSRF-able)
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["action"] ?? '') == "delete" && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
    $facility_id = (int) ($_POST["id"] ?? 0);
    $sql = "DELETE FROM facilities WHERE facility_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $facility_id);
        if ($stmt->execute()) {
            $message = "<div class='alert alert-success'>Facility deleted successfully.</div>";
        } else {
            $message = "<div class='alert alert-danger'>Error deleting facility: " . $stmt->error . "</div>";
        }
        $stmt->close();
    }
}

// Fetch all facilities — cached 60s, invalidated on any add/edit/delete above.
$facilities = cache_remember('mg_facilities', 60, function () use ($conn) {
    $rows = [];
    $sql = "SELECT facility_id, name, location, type, capacity FROM facilities";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }
    return $rows;
});
$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Manage Facilities</h2>
            </div>
            <div class="card-body">
                <?php echo $message; ?>

                <h3><?php echo ($edit_mode) ? "Edit Facility" : "Add New Facility"; ?></h3>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <?php echo csrf_field('admin_csrf'); ?>
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="facility_id" value="<?php echo (int) $facility_id_to_edit; ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="name" class="form-label">Facility Name</label>
                        <input type="text" name="name" id="name" class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($name); ?>">
                        <span class="invalid-feedback"><?php echo $name_err; ?></span>
                    </div>
                    <div class="mb-3">
                        <label for="location" class="form-label">Location</label>
                        <input type="text" name="location" id="location" class="form-control <?php echo (!empty($location_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($location); ?>">
                        <span class="invalid-feedback"><?php echo $location_err; ?></span>
                    </div>
                    <div class="mb-3">
                        <label for="type" class="form-label">Type</label>
                        <input type="text" name="type" id="type" class="form-control <?php echo (!empty($type_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($type); ?>">
                        <span class="invalid-feedback"><?php echo $type_err; ?></span>
                    </div>
                    <div class="mb-3">
                        <label for="capacity" class="form-label">Capacity</label>
                        <input type="number" name="capacity" id="capacity" class="form-control <?php echo (!empty($capacity_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($capacity); ?>">
                        <span class="invalid-feedback"><?php echo $capacity_err; ?></span>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo ($edit_mode) ? "Update Facility" : "Add Facility"; ?></button>
                    <?php if ($edit_mode): ?>
                        <a href="manage_facilities.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </form>

                <hr>

                <h3>All Facilities</h3>
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Location</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($facilities) > 0): ?>
                            <?php foreach ($facilities as $facility): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($facility["facility_id"]); ?></td>
                                    <td><?php echo htmlspecialchars($facility["name"]); ?></td>
                                    <td><?php echo htmlspecialchars($facility["location"]); ?></td>
                                    <td><?php echo htmlspecialchars($facility["type"]); ?></td>
                                    <td><?php echo htmlspecialchars($facility["capacity"]); ?></td>
                                    <td>
                                        <a href="manage_facilities.php?action=edit&id=<?php echo (int) $facility["facility_id"]; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this facility?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $facility["facility_id"]; ?>">
                                            <?php echo csrf_field('admin_csrf'); ?>
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6">No facilities found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
