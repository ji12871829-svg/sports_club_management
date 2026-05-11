<?php
session_start();

// Check if the user is logged in, if not then redirect to login page
if(!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true){
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';

$member_id = $_SESSION["member_id"];
$first_name = $last_name = $email = $phone_number = $address = '';
$first_name_err = $last_name_err = $email_err = '';
$update_success = $update_error = '';

// Fetch current member data to pre-fill the form
$sql_fetch = "SELECT first_name, last_name, email, phone_number, address FROM members WHERE member_id = ?";
if ($stmt_fetch = $conn->prepare($sql_fetch)) {
    $stmt_fetch->bind_param("i", $member_id);
    if ($stmt_fetch->execute()) {
        $result_fetch = $stmt_fetch->get_result();
        if ($result_fetch->num_rows == 1) {
            $member = $result_fetch->fetch_assoc();
            $first_name = $member['first_name'];
            $last_name = $member['last_name'];
            $email = $member['email'];
            $phone_number = $member['phone_number'];
            $address = $member['address'];
        } else {
            $update_error = "Member data not found.";
        }
    } else {
        $update_error = "Oops! Something went wrong. Please try again later.";
    }
    $stmt_fetch->close();
}

// Processing form data when form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Validate first name
    if (empty(trim($_POST["first_name"]))) {
        $first_name_err = "Please enter your first name.";
    } else {
        $first_name = trim($_POST["first_name"]);
    }

    // Validate last name
    if (empty(trim($_POST["last_name"]))) {
        $last_name_err = "Please enter your last name.";
    } else {
        $last_name = trim($_POST["last_name"]);
    }

    // Validate email
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter your email.";
    } else {
        // Check if email has changed and if the new email is already taken by another user
        if (trim($_POST["email"]) != $email) {
            $sql_check_email = "SELECT member_id FROM members WHERE email = ? AND member_id != ?";
            if ($stmt_check_email = $conn->prepare($sql_check_email)) {
                $stmt_check_email->bind_param("si", $param_email, $member_id);
                $param_email = trim($_POST["email"]);
                if ($stmt_check_email->execute()) {
                    $stmt_check_email->store_result();
                    if ($stmt_check_email->num_rows == 1) {
                        $email_err = "This email is already taken.";
                    } else {
                        $email = trim($_POST["email"]);
                    }
                } else {
                    $update_error = "Oops! Something went wrong. Please try again later.";
                }
                $stmt_check_email->close();
            }
        } else {
            $email = trim($_POST["email"]);
        }
    }

    // Optional fields
    $phone_number = trim($_POST["phone_number"]);
    $address = trim($_POST["address"]);

    // Check input errors before updating in database
    if (empty($first_name_err) && empty($last_name_err) && empty($email_err)) {
        $sql_update = "UPDATE members SET first_name = ?, last_name = ?, email = ?, phone_number = ?, address = ? WHERE member_id = ?";

        if ($stmt_update = $conn->prepare($sql_update)) {
            $stmt_update->bind_param("sssssi", $param_first_name, $param_last_name, $param_email, $param_phone_number, $param_address, $member_id);

            // Set parameters
            $param_first_name = $first_name;
            $param_last_name = $last_name;
            $param_email = $email;
            $param_phone_number = $phone_number;
            $param_address = $address;

            // Attempt to execute the prepared statement
            if ($stmt_update->execute()) {
                $update_success = "Profile updated successfully!";
                // Update session variables if email or name changed
                $_SESSION["first_name"] = $first_name;
                $_SESSION["email"] = $email;
            } else {
                $update_error = "Something went wrong. Please try again later. " . $stmt_update->error;
            }
            $stmt_update->close();
        }
    }
}

$conn->close();
?>

<?php include '../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header"><h2>Update Profile</h2></div>
            <div class="card-body">
                <?php
                if (!empty($update_success)) {
                    echo '<div class="alert alert-success">' . $update_success . '</div>';
                }
                if (!empty($update_error)) {
                    echo '<div class="alert alert-danger">' . $update_error . '</div>';
                }
                ?>
                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
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
                        <input type="text" name="phone_number" id="phone_number" class="form-control" value="<?php echo htmlspecialchars($phone_number); ?>">
                    </div>
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <input type="text" name="address" id="address" class="form-control" value="<?php echo htmlspecialchars($address); ?>">
                    </div>
                    <div class="mb-3">
                        <input type="submit" class="btn btn-primary" value="Update Profile">
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
