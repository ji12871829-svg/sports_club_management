<?php
// ============================================================
//  public/register.php
//  APIs Added:
//    ✅ Cloudflare Turnstile — Free CAPTCHA (replaces reCAPTCHA)
//    ✅ Brevo Email API      — welcome email on registration
// ============================================================
session_start();
require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/send_email.php';
require_once '../includes/turnstile.php';

$first_name = $last_name = $email = $password = $confirm_password = $phone_number = $address = '';
$first_name_err = $last_name_err = $email_err = $password_err = $confirm_password_err = $captcha_err = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ── Cloudflare Turnstile Verification ─────────────────────
    $token = $_POST['cf-turnstile-response'] ?? '';
    if (empty($token)) {
        $captcha_err = 'Please complete the security check.';
    } elseif (!verifyTurnstile($token)) {
        $captcha_err = 'Security check failed. Please try again.';
    }

    // ── Validate First Name ───────────────────────────────────
    if (empty(trim($_POST['first_name']))) {
        $first_name_err = 'Please enter your first name.';
    } else {
        $first_name = trim($_POST['first_name']);
    }

    // ── Validate Last Name ────────────────────────────────────
    if (empty(trim($_POST['last_name']))) {
        $last_name_err = 'Please enter your last name.';
    } else {
        $last_name = trim($_POST['last_name']);
    }

    // ── Validate Email ────────────────────────────────────────
    if (empty(trim($_POST['email']))) {
        $email_err = 'Please enter your email.';
    } else {
        $sql = 'SELECT member_id FROM members WHERE email = ?';
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('s', $param_email);
            $param_email = trim($_POST['email']);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $email_err = 'This email is already taken.';
                } else {
                    $email = trim($_POST['email']);
                }
            }
            $stmt->close();
        }
    }

    // ── Validate Password ─────────────────────────────────────
    if (empty(trim($_POST['password']))) {
        $password_err = 'Please enter a password.';
    } elseif (strlen(trim($_POST['password'])) < 6) {
        $password_err = 'Password must have at least 6 characters.';
    } else {
        $password = trim($_POST['password']);
    }

    // ── Validate Confirm Password ─────────────────────────────
    if (empty(trim($_POST['confirm_password']))) {
        $confirm_password_err = 'Please confirm password.';
    } else {
        $confirm_password = trim($_POST['confirm_password']);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = 'Passwords did not match.';
        }
    }

    $phone_number = trim($_POST['phone_number']);
    $address      = trim($_POST['address']);

    // ── Insert Member if All Validations Pass ─────────────────
    if (empty($first_name_err) && empty($last_name_err) && empty($email_err)
        && empty($password_err) && empty($confirm_password_err) && empty($captcha_err)) {

        $sql = 'INSERT INTO members (first_name, last_name, email, password, phone_number, address)
                VALUES (?, ?, ?, ?, ?, ?)';

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('ssssss',
                $param_first_name, $param_last_name, $param_email,
                $param_password, $param_phone_number, $param_address
            );
            $param_first_name   = $first_name;
            $param_last_name    = $last_name;
            $param_email        = $email;
            $param_password     = password_hash($password, PASSWORD_DEFAULT);
            $param_phone_number = $phone_number;
            $param_address      = $address;

            if ($stmt->execute()) {
                // ── Send Welcome Email via Brevo ──────────────
                sendEmail(
                    $email,
                    $first_name . ' ' . $last_name,
                    'Welcome to Sports Club! 🏆',
                    emailWelcome($first_name)
                );

                $conn->close();
                header('location: login.php?registered=1');
                exit;
            } else {
                echo 'Something went wrong. Please try again later.';
            }
            $stmt->close();
        }
    }

    $conn->close();
}
?>

<?php include '../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-user-plus me-2"></i>Register New Member</h2>
            </div>
            <div class="card-body">
                <p class="text-muted">Please fill in this form to create your account.</p>

                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" name="first_name" id="first_name"
                                   class="form-control <?php echo (!empty($first_name_err)) ? 'is-invalid' : ''; ?>"
                                   value="<?php echo htmlspecialchars($first_name); ?>">
                            <span class="invalid-feedback"><?php echo $first_name_err; ?></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" name="last_name" id="last_name"
                                   class="form-control <?php echo (!empty($last_name_err)) ? 'is-invalid' : ''; ?>"
                                   value="<?php echo htmlspecialchars($last_name); ?>">
                            <span class="invalid-feedback"><?php echo $last_name_err; ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" name="email" id="email"
                               class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>"
                               value="<?php echo htmlspecialchars($email); ?>">
                        <span class="invalid-feedback"><?php echo $email_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" name="password" id="password"
                               class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>">
                        <span class="invalid-feedback"><?php echo $password_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password"
                               class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>">
                        <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="phone_number" class="form-label">
                            Phone Number <span class="text-muted">(Optional)</span>
                        </label>
                        <input type="text" name="phone_number" id="phone_number"
                               class="form-control" placeholder="e.g. 0712345678"
                               value="<?php echo htmlspecialchars($phone_number); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">
                            Address <span class="text-muted">(Optional)</span>
                        </label>
                        <input type="text" name="address" id="address"
                               class="form-control"
                               value="<?php echo htmlspecialchars($address); ?>">
                    </div>

                    <!-- ✅ Cloudflare Turnstile Widget -->
                    <div class="mb-3">
                        <div class="cf-turnstile"
                             data-sitekey="<?php echo CF_TURNSTILE_SITE_KEY; ?>"
                             data-theme="light">
                        </div>
                        <?php if (!empty($captcha_err)): ?>
                            <div class="text-danger small mt-1">
                                <i class="fas fa-exclamation-circle me-1"></i>
                                <?php echo $captcha_err; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i>Register
                        </button>
                        <button type="reset" class="btn btn-secondary">Reset</button>
                    </div>

                    <p>Already have an account? <a href="login.php">Login here</a>.</p>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>