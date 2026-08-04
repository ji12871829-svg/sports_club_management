<?php
// ============================================================
//  public/login.php
//  Enhanced with rate limiting (5 attempts per 15 min per email/IP).
// ============================================================
session_start();

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: dashboard.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/rate_limiter.php';
require_once '../includes/csrf.php';

$email = $password = '';
$email_err = $password_err = $login_err = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ── CSRF Protection (login CSRF / session-fixation defense) ──
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
        $login_err = 'Your session has expired. Please reload the page and try again.';
    }

    // ── Validate Email ────────────────────────────────────────
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter your email.";
    } else {
        $email = trim($_POST["email"]);
    }

    // ── Validate Password ─────────────────────────────────────
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }

    // ── Rate Limiting Check ───────────────────────────────────
    if (empty($email_err)) {
        $rate_check = check_login_attempts($conn, $email);
        if (!$rate_check['allowed']) {
            $login_err = 'Too many failed login attempts. Please try again in 15 minutes.';
        }
    }

    // ── Authenticate ──────────────────────────────────────────
    if (empty($email_err) && empty($password_err) && empty($login_err)) {
        $sql = "SELECT member_id, first_name, email, password FROM members WHERE email = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $param_email);
            $param_email = $email;
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($member_id, $first_name, $email, $hashed_password);
                    if ($stmt->fetch()) {
                        if (password_verify($password, $hashed_password)) {
                            clear_login_attempts($conn, $email);
                            session_regenerate_id(true);
                            $_SESSION["loggedin"]   = true;
                            $_SESSION["member_id"]  = $member_id;
                            $_SESSION["first_name"] = $first_name;
                            $_SESSION["email"]      = $email;
                            header("location: dashboard.php");
                            exit;
                        } else {
                            register_login_attempt($conn, $email);
                            $login_err = "Invalid email or password.";
                        }
                    }
                } else {
                    register_login_attempt($conn, $email);
                    $login_err = "Invalid email or password.";
                }
            }
            $stmt->close();
        }
    }
    $conn->close();
}
?>

<?php include '../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-5">

        <?php if (isset($_GET['registered'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle me-2"></i>
                Registration successful! Check your email for a welcome message, then log in.
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-sign-in-alt me-2"></i>Member Login</h2>
            </div>
            <div class="card-body">

                <?php if (!empty($login_err)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $login_err; ?>
                    </div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">

                    <?php echo csrf_field('member_csrf'); ?>

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
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-sign-in-alt me-1"></i>Login
                        </button>
                    </div>

                    <p class="text-center">
                        Don't have an account? <a href="register.php">Sign up now</a>.
                    </p>
                    <p class="text-center text-muted small">
                        <a href="privacy.php">Privacy Policy</a> &middot; <a href="forgot_password.php">Forgot password?</a>
                    </p>

                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>