<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once __DIR__ . '/../includes/csrf.php';

$message = "";
$name = $description = "";
$name_err = $description_err = "";

// Handle Add/Edit operation
if ($_SERVER["REQUEST_METHOD"] == "POST" && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
    // Validate name
    if (empty(trim($_POST["name"]))) {
        $name_err = "Please enter a sport name.";
    } else {
        $name = trim($_POST["name"]);
    }

    // Validate description
    if (empty(trim($_POST["description"]))) {
        $description_err = "Please enter a description.";
    } else {
        $description = trim($_POST["description"]);
    }

    // Check input errors before inserting/updating in database
    if (empty($name_err) && empty($description_err)) {
        if (isset($_POST["sport_id"]) && !empty($_POST["sport_id"])) {
            // Update operation
            $sport_id = (int) $_POST["sport_id"];
            $sql = "UPDATE sports SET name = ?, description = ? WHERE sport_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ssi", $name, $description, $sport_id);
                if ($stmt->execute()) {
                    $message = "<div class=\'alert alert-success\'>Sport updated successfully.</div>";
                } else {
                    $message = "<div class=\'alert alert-danger\'>Error updating sport: " . $stmt->error . "</div>";
                }
                $stmt->close();
            }
        } else {
            // Insert operation
            $sql = "INSERT INTO sports (name, description) VALUES (?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ss", $name, $description);
                if ($stmt->execute()) {
                    $message = "<div class=\'alert alert-success\'>Sport added successfully.</div>";
                    $name = $description = ""; // Clear form fields
                } else {
                    $message = "<div class=\'alert alert-danger\'>Error adding sport: " . $stmt->error . "</div>";
                }
                $stmt->close();
            }
        }
    }
}

// Handle Delete operation — POST-only with CSRF (was GET: trivially CSRF-able)
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["action"] ?? '') == "delete" && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
    $sport_id = (int) ($_POST["id"] ?? 0);
    $sql = "DELETE FROM sports WHERE sport_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $sport_id);
        if ($stmt->execute()) {
            $message = "<div class=\'alert alert-success\'>Sport deleted successfully.</div>";
        } else {
            $message = "<div class=\'alert alert-danger\'>Error deleting sport: " . $stmt->error . "</div>";
        }
        $stmt->close();
    }
}

// Handle Edit form pre-fill
if (isset($_GET["action"]) && $_GET["action"] == "edit" && isset($_GET["id"])) {
    $sport_id = (int) $_GET["id"];
    $sql = "SELECT name, description FROM sports WHERE sport_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $sport_id);
        if ($stmt->execute()) {
            $stmt->store_result();
            if ($stmt->num_rows == 1) {
                $stmt->bind_result($name, $description);
                $stmt->fetch();
            }
        }
        $stmt->close();
    }
}

// Fetch all sports
$sports = [];
$sql = "SELECT sport_id, name, description FROM sports";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $sports[] = $row;
    }
    $result->free();
}
$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Manage Sports</h2>
            </div>
            <div class="card-body">
                <?php echo $message; ?>

                <h3><?php echo (isset($_GET["action"]) && $_GET["action"] == "edit") ? "Edit Sport" : "Add New Sport"; ?></h3>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <?php echo csrf_field('admin_csrf'); ?>
                    <?php if (isset($sport_id)): ?>
                        <input type="hidden" name="sport_id" value="<?php echo (int) $sport_id; ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="name" class="form-label">Sport Name</label>
                        <input type="text" name="name" id="name" class="form-control <?php echo (!empty($name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($name); ?>">
                        <span class="invalid-feedback"><?php echo $name_err; ?></span>
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea name="description" id="description" class="form-control <?php echo (!empty($description_err)) ? 'is-invalid' : ''; ?>" rows="3"><?php echo htmlspecialchars($description); ?></textarea>
                        <span class="invalid-feedback"><?php echo $description_err; ?></span>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo (isset($_GET["action"]) && $_GET["action"] == "edit") ? "Update Sport" : "Add Sport"; ?></button>
                    <?php if (isset($_GET["action"]) && $_GET["action"] == "edit"): ?>
                        <a href="manage_sports.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </form>

                <hr>

                <h3>All Sports</h3>
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($sports) > 0): ?>
                            <?php foreach ($sports as $sport): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($sport["sport_id"]); ?></td>
                                    <td><?php echo htmlspecialchars($sport["name"]); ?></td>
                                    <td><?php echo htmlspecialchars($sport["description"]); ?></td>
                                    <td>
                                        <a href="manage_sports.php?action=edit&id=<?php echo (int) $sport["sport_id"]; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this sport?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $sport["sport_id"]; ?>">
                                            <?php echo csrf_field('admin_csrf'); ?>
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4">No sports found.</td>
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
