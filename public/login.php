<?php
// ============================================================
//  public/login.php
//  APIs Added:
//    ✅ Cloudflare Turnstile — Free CAPTCHA (no reCAPTCHA needed)
// ============================================================
session_start();

if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: dashboard.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/turnstile.php';

$email = $password = '';
$email_err = $password_err = $login_err = $captcha_err = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ── Cloudflare Turnstile Verification ─────────────────────
    $token = $_POST['cf-turnstile-response'] ?? '';
    if (empty($token)) {
        $captcha_err = 'Please complete the security check.';
    } elseif (!verifyTurnstile($token)) {
        $captcha_err = 'Security check failed. Please try again.';
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

    // ── Authenticate ──────────────────────────────────────────
    if (empty($email_err) && empty($password_err) && empty($captcha_err)) {
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
                            session_regenerate_id(true);
                            $_SESSION["loggedin"]   = true;
                            $_SESSION["member_id"]  = $member_id;
                            $_SESSION["first_name"] = $first_name;
                            $_SESSION["email"]      = $email;
                            header("location: dashboard.php");
                            exit;
                        } else {
                            $login_err = "Invalid email or password.";
                        }
                    }
                } else {
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

                    <!-- ✅ Cloudflare Turnstile Widget -->
                    <div class="mb-3">
                        <div class="cf-turnstile"
                             data-sitekey="<?php echo CF_TURNSTILE_SITE_KEY; ?>"
                             data-theme="light">
                        </div>
                        <?php if (!empty($captcha_err)): ?>
                            <div class="text-danger small mt-1">
                                <i class="fas fa-exclamation-circle me-1"></i><?php echo $captcha_err; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-sign-in-alt me-1"></i>Login
                        </button>
                    </div>

                    <p class="text-center">
                        Don't have an account? <a href="register.php">Sign up now</a>.
                    </p>

                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>