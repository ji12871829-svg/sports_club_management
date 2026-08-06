<?php
require_once '../includes/session_config.php';
asc_session_start();

if (!empty($_SESSION['loggedin'])) {
    header('Location: dashboard.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limiter.php';
require_once '../includes/password_reset.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$email = trim($_POST['email'] ?? '');
$email_err = '';
$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'forgot_password_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        if (empty(trim($email))) {
            $email_err = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email_err = 'Please enter a valid email address.';
        }

        if (empty($email_err) && empty($error)) {
            $rateKey = 'reset-req:' . strtolower($email);
            $rate = check_login_attempts($conn, $rateKey);
            if (!$rate['allowed']) {
                $error = 'Too many reset requests. Please try again in 15 minutes.';
            } else {
                register_login_attempt($conn, $rateKey);
                if (!asc_password_reset_ready($conn)) {
                    $error = 'Password reset is not available yet. Please contact the club.';
                } else {
                    asc_request_password_reset($conn, $email);
                    $success = true;
                    $email = '';
                }
            }
        }
    }
}

$conn->close();

include_once '../includes/header.php';
?>

<style>
    body {
        background: linear-gradient(rgba(15, 23, 42, 0.8), rgba(15, 23, 42, 0.85)), url('../sports.jpeg') no-repeat center center fixed;
        background-size: cover;
        min-height: 100vh;
        display: flex;
        align-items: center;
    }
    .auth-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.15);
        overflow: hidden;
    }
    .auth-accent { height: 6px; background: linear-gradient(90deg, #1d5c8f, #2a6ba8); }
</style>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-6 col-lg-5">
            <div class="auth-card">
                <div class="auth-accent"></div>
                <div class="card-body p-4 p-sm-5">
                    <h2 class="fw-bold mb-1">Forgot password?</h2>
                    <p class="text-muted small mb-4">Enter your member email and we will send a reset link if an account exists.</p>

                    <?php if ($success): ?>
                        <div class="alert alert-success">
                            If an account exists for that email, we sent a reset link. Check your inbox (and spam folder). The link expires in 1 hour.
                            <?php if (str_contains($_SERVER['HTTP_HOST'] ?? '', 'ngrok')): ?>
                                <hr class="my-2">
                                <span class="small">Using ngrok? On the first open you may need to click <strong>Visit Site</strong> on the ngrok warning page, then the reset form will load.</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo e($error); ?></div>
                    <?php endif; ?>

                    <?php if (!$success): ?>
                    <form method="post">
                        <?php echo csrf_field('forgot_password_csrf'); ?>
                        <div class="mb-3">
                            <label for="email" class="form-label">Email address</label>
                            <input type="email" name="email" id="email" class="form-control <?php echo $email_err ? 'is-invalid' : ''; ?>"
                                   value="<?php echo e($email); ?>" required autocomplete="email">
                            <div class="invalid-feedback"><?php echo e($email_err); ?></div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2">Send reset link</button>
                    </form>
                    <?php endif; ?>

                    <p class="text-center mt-4 mb-0 small">
                        <a href="login.php">← Back to sign in</a>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>
