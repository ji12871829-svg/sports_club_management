<?php
require_once '../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limiter.php';
require_once '../includes/admin_2fa.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

if (!empty($_SESSION['admin_loggedin'])) {
    header('Location: admin_dashboard.php');
    exit;
}

if (!admin_2fa_pending_valid()) {
    header('Location: admin_login.php');
    exit;
}

$pending = $_SESSION['admin_2fa_pending'];
$admin_id = (int) $pending['admin_id'];
$email = (string) $pending['email'];
$error = '';
$lockout_seconds = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_2fa_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $rate = check_login_attempts($conn, '2fa:' . $email);
        if (!$rate['allowed']) {
            $lockout_seconds = (int) ($rate['retry_after'] ?? 900);
            $error = 'Too many attempts. Please wait for the countdown before trying again.';
        } else {
            $code = trim($_POST['code'] ?? '');
            $admin = admin_2fa_fetch($conn, $admin_id);
            $verified = false;

            if ($admin && admin_2fa_is_enabled($admin)) {
                if (preg_match('/^\d{6}$/', preg_replace('/\s+/', '', $code))) {
                    $verified = totp_verify($admin['totp_secret'], $code);
                } elseif ($code !== '') {
                    $verified = admin_2fa_verify_recovery_code($conn, $admin_id, $code);
                }
            }

            if ($verified) {
                clear_login_attempts($conn, '2fa:' . $email);
                admin_2fa_complete_login($conn, $admin_id, $email);
                $conn->close();
                header('Location: admin_dashboard.php');
                exit;
            }

            register_login_attempt($conn, '2fa:' . $email);
            $error = 'Invalid authentication code. Try the 6-digit app code or a recovery code.';
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Two-Factor Verification — Apex Sports Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(rgba(15, 23, 42, 0.85), rgba(15, 23, 42, 0.9)), url('../sports.jpeg') center/cover fixed;
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: system-ui, sans-serif;
        }
        .card { border: none; border-radius: 12px; box-shadow: 0 20px 40px rgba(0,0,0,.25); }
        .accent { height: 6px; background: linear-gradient(90deg, #dc2626, #ef4444); }
        .code-input { font-size: 1.5rem; letter-spacing: .35em; text-align: center; font-weight: 600; }
    </style>
</head>
<body>
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card">
                <div class="accent"></div>
                <div class="card-body p-4 p-sm-5">
                    <div class="text-center mb-4">
                        <i class="fas fa-mobile-screen-button fa-2x text-danger mb-2"></i>
                        <h2 class="h4 fw-bold mb-1">Two-factor authentication</h2>
                        <p class="text-muted small mb-0">Enter the 6-digit code from your authenticator app for<br><strong><?php echo e($email); ?></strong></p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger small"><?php echo e($error); ?>
                            <?php if ($lockout_seconds > 0): ?>
                                <div class="mt-1" id="lockoutCountdownWrap">
                                    <i class="fas fa-hourglass-half me-1"></i>Retry in <strong id="lockoutCountdown">--:--</strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($lockout_seconds > 0): ?>
                    <script>
                        (function() {
                            var lockoutSeconds = <?php echo (int) $lockout_seconds; ?>;
                            var form = document.querySelector('form');
                            var counter = document.getElementById('lockoutCountdown');
                            var btn = form ? form.querySelector('button[type="submit"]') : null;
                            if (btn) btn.disabled = true;
                            var render = function() {
                                var m = Math.floor(lockoutSeconds / 60);
                                var s = lockoutSeconds % 60;
                                if (counter) counter.textContent = m + ':' + (s < 10 ? '0' : '') + s;
                            };
                            render(); // paint immediately, no 1s "--:--" flash
                            var timer = setInterval(function() {
                                lockoutSeconds--;
                                if (lockoutSeconds <= 0) {
                                    clearInterval(timer);
                                    if (btn) btn.disabled = false;
                                    var wrap = document.getElementById('lockoutCountdownWrap');
                                    if (wrap) wrap.innerHTML = '<i class="fas fa-check-circle me-1"></i>You can try again now.';
                                    return;
                                }
                                render();
                            }, 1000);
                        })();
                    </script>
                    <?php endif; ?>

                    <form method="post" autocomplete="off">
                        <?php echo csrf_field('admin_2fa_csrf'); ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Authentication code</label>
                            <input type="text" name="code" class="form-control code-input" inputmode="numeric"
                                   pattern="[0-9A-Za-z]{6,16}" maxlength="16" placeholder="000000" required autofocus>
                        </div>
                        <button type="submit" class="btn btn-dark w-100 py-2 mb-2">Verify</button>
                    </form>

                    <p class="text-muted small text-center mb-0 mt-3">
                        Lost your phone? Enter a <strong>recovery code</strong> in the same field (one-time use).
                    </p>
                    <div class="text-center mt-3">
                        <a href="admin_login.php" class="small text-decoration-none">← Back to login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
require_once __DIR__ . '/../includes/whatsapp.php';
echo wa_render_floating_widget();
?>
</body>
</html>
