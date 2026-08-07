<?php
require_once '../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limiter.php';

if (!empty($_SESSION['admin_loggedin'])) {
    header('Location: admin_dashboard.php');
    exit;
}

if (!admin_device_pending_valid()) {
    header('Location: admin_login.php');
    exit;
}

$pending = $_SESSION['admin_device_pending'];
$email = (string) ($pending['email'] ?? '');
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_device_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($code === '') {
            $error = 'Please enter the code from your email.';
        } else {
            // Brute-force guard (mirrors the 2FA verify page): the 6-digit code
            // is the only thing protecting this login, so throttle attempts.
            $rate = check_login_attempts($conn, 'device:' . $email);
            if (!$rate['allowed']) {
                $mins = (int) ceil(((int) ($rate['retry_after'] ?? 900)) / 60);
                $error = 'Too many invalid attempts. Please wait about ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . ' before trying again.';
            } elseif (admin_device_challenge_verify($code)) {
                clear_login_attempts($conn, 'device:' . $email);
                admin_device_challenge_complete($conn, (int) ($pending['admin_id'] ?? 0), $email);
                $conn->close();
                header('Location: admin_dashboard.php');
                exit;
            } else {
                register_login_attempt($conn, 'device:' . $email);
                $error = 'That code is invalid or expired. Return to the login page to try again.';
                unset($_SESSION['admin_device_pending']);
            }
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
    <title>Confirm Sign-In — Apex Sports Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: radial-gradient(1200px 600px at 85% -10%, rgba(29, 92, 143, .35), transparent 60%),
                        radial-gradient(900px 500px at -10% 110%, rgba(14, 58, 95, .55), transparent 55%),
                        #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            font-family: system-ui, sans-serif;
        }
        .card { border: none; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,.35); }
        .accent { height: 6px; background: linear-gradient(90deg, #1d5c8f, #2a6ba8); }
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
                        <i class="fas fa-shield-halved fa-2x mb-2" style="color:#1d5c8f;"></i>
                        <h2 class="h4 fw-bold mb-1">Confirm this sign-in</h2>
                        <p class="text-muted small mb-0">We emailed a 6-digit code to<br><strong><?php echo htmlspecialchars($email); ?></strong></p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger small"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>

                    <form method="post" autocomplete="off">
                        <?php echo csrf_field('admin_device_csrf'); ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Email code</label>
                            <input type="text" name="code" class="form-control code-input" inputmode="numeric"
                                   pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus>
                        </div>
                        <button type="submit" class="btn w-100 py-2 mb-2" style="background:#14497a;color:#fff;">Verify &amp; Sign In</button>
                    </form>

                    <p class="text-muted small text-center mb-0 mt-3">
                        Code expires in 10 minutes. If you don't recognise this sign-in, change your password immediately.
                    </p>
                    <div class="text-center mt-3">
                        <a href="admin_login.php" class="small text-decoration-none">← Back to login</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>
