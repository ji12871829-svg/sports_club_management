<?php
require_once '../includes/session_config.php';
asc_session_start();

if (!empty($_SESSION['loggedin'])) {
    header('Location: dashboard.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once '../includes/password_policy.php';
require_once '../includes/password_reset.php';
require_once '../includes/rate_limiter.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$password = '';
$confirm_password = '';
$password_err = $confirm_password_err = '';
$error = '';

$resetUser = ($token !== '' && asc_password_reset_ready($conn))
    ? asc_verify_password_reset_token($conn, $token)
    : null;

if ($token === '' || !$resetUser) {
    $error = 'This reset link is invalid or has expired. Request a new one from the forgot password page.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $resetUser && empty($error)) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'reset_password_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $rate = check_login_attempts($conn, 'reset-pwd:' . $resetUser['email']);
        if (!$rate['allowed']) {
            $error = 'Too many attempts. Please try again in 15 minutes or request a new reset link.';
        } else {
            if (empty($_POST['password'])) {
                $password_err = 'Please enter a new password.';
            } else {
                $policy = asc_validate_password_strength($_POST['password']);
                if (!$policy['ok']) {
                    $password_err = $policy['message'];
                } else {
                    $password = $_POST['password'];
                }
            }

            if (empty($_POST['confirm_password'])) {
                $confirm_password_err = 'Please confirm your password.';
            } elseif ($password !== '' && $_POST['confirm_password'] !== $password) {
                $confirm_password_err = 'Passwords do not match.';
            }

            if (empty($password_err) && empty($confirm_password_err) && empty($error)) {
                if (asc_complete_password_reset($conn, $resetUser['token_id'], $resetUser['member_id'], $password)) {
                    clear_login_attempts($conn, 'reset-pwd:' . $resetUser['email']);
                    clear_login_attempts($conn, $resetUser['email']);
                    $conn->close();
                    header('Location: login.php?reset=1');
                    exit;
                } else {
                    register_login_attempt($conn, 'reset-pwd:' . $resetUser['email']);
                    $error = 'Could not update your password. Please try again or request a new link.';
                }
            } else {
                register_login_attempt($conn, 'reset-pwd:' . $resetUser['email']);
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
                    <h2 class="fw-bold mb-1">Set new password</h2>

                    <?php if ($error && !$resetUser): ?>
                        <div class="alert alert-warning"><?php echo e($error); ?></div>
                        <a href="forgot_password.php" class="btn btn-outline-primary w-100">Request new link</a>
                    <?php else: ?>
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo e($error); ?></div>
                        <?php endif; ?>
                        <p class="text-muted small">Hi <?php echo e($resetUser['first_name']); ?>, choose a new password for <?php echo e($resetUser['email']); ?>.</p>
                        <form method="post">
                            <?php echo csrf_field('reset_password_csrf'); ?>
                            <input type="hidden" name="token" value="<?php echo e($token); ?>">
                            <div class="mb-3">
                                <label for="password" class="form-label">New password</label>
                                <input type="password" name="password" id="password"
                                       class="form-control <?php echo $password_err ? 'is-invalid' : ''; ?>"
                                       autocomplete="new-password" required>
                                <div class="invalid-feedback"><?php echo e($password_err); ?></div>
                                <div class="form-text"><?php echo e(asc_password_strength_hint()); ?></div>
                            </div>
                            <div class="mb-4">
                                <label for="confirm_password" class="form-label">Confirm password</label>
                                <input type="password" name="confirm_password" id="confirm_password"
                                       class="form-control <?php echo $confirm_password_err ? 'is-invalid' : ''; ?>"
                                       autocomplete="new-password" required>
                                <div class="invalid-feedback"><?php echo e($confirm_password_err); ?></div>
                            </div>
                            <div class="form-check mb-4">
                                <input class="form-check-input password-show-toggle" type="checkbox" id="show_new_password">
                                <label class="form-check-label" for="show_new_password">Show password</label>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 py-2">Update password</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>
