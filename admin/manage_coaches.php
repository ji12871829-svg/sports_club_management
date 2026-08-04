<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once __DIR__ . '/../includes/csrf.php';

$message = "";
$first_name = $last_name = $email = $phone_number = $specialization = "";
$first_name_err = $last_name_err = $email_err = $phone_number_err = $specialization_err = "";

// Handle Add/Edit operation
if ($_SERVER["REQUEST_METHOD"] == "POST" && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
    // Validate first name
    if (empty(trim($_POST["first_name"]))) {
        $first_name_err = "Please enter a first name.";
    } else {
        $first_name = trim($_POST["first_name"]);
    }

    // Validate last name
    if (empty(trim($_POST["last_name"]))) {
        $last_name_err = "Please enter a last name.";
    } else {
        $last_name = trim($_POST["last_name"]);
    }

    // Validate email
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter an email.";
    } else {
        $email = trim($_POST["email"]);
    }

    // Validate phone number
    $phone_number = trim($_POST["phone_number"]);

    // Validate specialization
    if (empty(trim($_POST["specialization"]))) {
        $specialization_err = "Please enter a specialization.";
    } else {
        $specialization = trim($_POST["specialization"]);
    }

    // Check input errors before inserting/updating in database
    if (empty($first_name_err) && empty($last_name_err) && empty($email_err) && empty($specialization_err)) {
        if (isset($_POST["coach_id"]) && !empty($_POST["coach_id"])) {
            // Update operation
            $coach_id = (int) $_POST["coach_id"];
            $sql = "UPDATE coaches SET first_name = ?, last_name = ?, email = ?, phone_number = ?, specialization = ? WHERE coach_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("sssssi", $first_name, $last_name, $email, $phone_number, $specialization, $coach_id);
                if ($stmt->execute()) {
                    $message = "<div class='alert alert-success'>Coach updated successfully.</div>";
                } else {
                    $message = "<div class='alert alert-danger'>Error updating coach: " . $stmt->error . "</div>";
                }
                $stmt->close();
            }
        } else {
            // Insert operation
            $sql = "INSERT INTO coaches (first_name, last_name, email, phone_number, specialization) VALUES (?, ?, ?, ?, ?)";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("sssss", $first_name, $last_name, $email, $phone_number, $specialization);
                if ($stmt->execute()) {
                    $message = "<div class='alert alert-success'>Coach added successfully.</div>";
                    $first_name = $last_name = $email = $phone_number = $specialization = ""; // Clear form fields
                } else {
                    $message = "<div class='alert alert-danger'>Error adding coach: " . $stmt->error . "</div>";
                }
                $stmt->close();
            }
        }
    }
}

// Handle Delete operation — POST-only with CSRF (was GET: trivially CSRF-able)
if ($_SERVER["REQUEST_METHOD"] == "POST" && ($_POST["action"] ?? '') == "delete" && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
    $coach_id = (int) ($_POST["id"] ?? 0);
    $sql = "DELETE FROM coaches WHERE coach_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $coach_id);
        if ($stmt->execute()) {
            $message = "<div class='alert alert-success'>Coach deleted successfully.</div>";
        } else {
            $message = "<div class='alert alert-danger'>Error deleting coach: " . $stmt->error . "</div>";
        }
        $stmt->close();
    }
}

// Handle Edit form pre-fill
if (isset($_GET["action"]) && $_GET["action"] == "edit" && isset($_GET["id"])) {
    $coach_id = (int) $_GET["id"];
    $sql = "SELECT first_name, last_name, email, phone_number, specialization FROM coaches WHERE coach_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $coach_id);
        if ($stmt->execute()) {
            $stmt->store_result();
            if ($stmt->num_rows == 1) {
                $stmt->bind_result($first_name, $last_name, $email, $phone_number, $specialization);
                $stmt->fetch();
            }
        }
        $stmt->close();
    }
}

// Fetch all coaches
$coaches = [];
$sql = "SELECT coach_id, first_name, last_name, email, phone_number, specialization FROM coaches";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $coaches[] = $row;
    }
    $result->free();
}
$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Manage Coaches</h2>
            </div>
            <div class="card-body">
                <?php echo $message; ?>

                <h3><?php echo (isset($_GET["action"]) && $_GET["action"] == "edit") ? "Edit Coach" : "Add New Coach"; ?></h3>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <?php echo csrf_field('admin_csrf'); ?>
                    <?php if (isset($coach_id)): ?>
                        <input type="hidden" name="coach_id" value="<?php echo (int) $coach_id; ?>">
                    <?php endif; ?>
                    <div class="mb-3">
                        <label for="first_name" class="form-label">First Name</label>
                        <input type="text" name="first_name" id="first_name" class="form-control <?php echo (!empty($first_name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($first_name); ?>">
                        <span class="invalid-feedback"><?php echo $first_name_err; ?></span>
                    </div>
                    <div class="mb-3">
                        <label for="last_name" class="form-label">Last Name</label>
                        <input type="text" name="last_name" id="last_name" class="form-control <?php echo (!empty($last_name_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($last_name); ?>">
                        <span class="invalid-feedback"><?php echo $last_name_err; ?></span>
                    </div>
                    <div class="mb-3">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" name="email" id="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>">
                        <span class="invalid-feedback"><?php echo $email_err; ?></span>
                    </div>
                    <div class="mb-3">
                        <label for="phone_number" class="form-label">Phone Number</label>
                        <input type="text" name="phone_number" id="phone_number" class="form-control <?php echo (!empty($phone_number_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($phone_number); ?>">
                        <span class="invalid-feedback"><?php echo $phone_number_err; ?></span>
                    </div>
                    <div class="mb-3">
                        <label for="specialization" class="form-label">Specialization</label>
                        <input type="text" name="specialization" id="specialization" class="form-control <?php echo (!empty($specialization_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($specialization); ?>">
                        <span class="invalid-feedback"><?php echo $specialization_err; ?></span>
                    </div>
                    <button type="submit" class="btn btn-primary"><?php echo (isset($_GET["action"]) && $_GET["action"] == "edit") ? "Update Coach" : "Add Coach"; ?></button>
                    <?php if (isset($_GET["action"]) && $_GET["action"] == "edit"): ?>
                        <a href="manage_coaches.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </form>

                <hr>

                <h3>All Coaches</h3>
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Specialization</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($coaches) > 0): ?>
                            <?php foreach ($coaches as $coach): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($coach["coach_id"]); ?></td>
                                    <td><?php echo htmlspecialchars($coach["first_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($coach["last_name"]); ?></td>
                                    <td><?php echo htmlspecialchars($coach["email"]); ?></td>
                                    <td><?php echo htmlspecialchars($coach["phone_number"]); ?></td>
                                    <td><?php echo htmlspecialchars($coach["specialization"]); ?></td>
                                    <td>
                                        <a href="manage_coaches.php?action=edit&id=<?php echo (int) $coach["coach_id"]; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this coach?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $coach["coach_id"]; ?>">
                                            <?php echo csrf_field('admin_csrf'); ?>
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7">No coaches found.</td>
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
