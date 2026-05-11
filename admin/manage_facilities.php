<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";

$message = "";
$name = $location = $type = "";
$capacity = 0;
$name_err = $location_err = $type_err = $capacity_err = "";
$edit_mode = false;
$facility_id_to_edit = null;

// Handle Edit form pre-fill
if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
    $edit_mode = true;
    $facility_id_to_edit = $_GET['id'];
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
if ($_SERVER["REQUEST_METHOD"] == "POST") {
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
            $facility_id = $_POST["facility_id"];
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

// Handle Delete operation
if (isset($_GET["action"]) && $_GET["action"] == "delete" && isset($_GET["id"])) {
    $facility_id = $_GET["id"];
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

// Fetch all facilities
$facilities = [];
$sql = "SELECT facility_id, name, location, type, capacity FROM facilities";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $facilities[] = $row;
    }
    $result->free();
}
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
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="facility_id" value="<?php echo $facility_id_to_edit; ?>">
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
                                        <a href="manage_facilities.php?action=edit&id=<?php echo $facility["facility_id"]; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <a href="manage_facilities.php?action=delete&id=<?php echo $facility["facility_id"]; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this facility?');">Delete</a>
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
