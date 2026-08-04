<?php
require_once '../includes/admin_auth.php';
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once '../includes/admin_2fa.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$admin_id = (int) ($_SESSION['admin_id'] ?? 0);
$message = '';
$error = '';
$show_recovery = $_SESSION['admin_2fa_show_recovery'] ?? null;
unset($_SESSION['admin_2fa_show_recovery']);

if (!admin_2fa_schema_ready($conn)) {
    $error = 'Run migration 012_admin_two_factor.sql to enable two-factor authentication.';
}

$admin = $admin_id > 0 ? admin_2fa_fetch($conn, $admin_id) : null;
$enabled = admin_2fa_is_enabled($admin);

// POST handling before any HTML output (redirects require headers not yet sent)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && admin_2fa_schema_ready($conn) && csrf_verify($_POST['csrf_token'] ?? '', 'admin_2fa_setup_csrf')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'enable_confirm') {
        $secret = $_SESSION['admin_2fa_setup_secret'] ?? '';
        $code = trim($_POST['code'] ?? '');
        if ($secret === '' || !totp_verify($secret, $code)) {
            $error = 'Invalid code. Check your authenticator app and try again.';
        } else {
            $recovery = admin_2fa_generate_recovery_codes();
            if (admin_2fa_enable($conn, $admin_id, $secret, $recovery['hashed'])) {
                unset($_SESSION['admin_2fa_setup_secret']);
                $_SESSION['admin_2fa_show_recovery'] = $recovery['plain'];
                header('Location: admin_setup_2fa.php');
                exit;
            }
            $error = 'Could not enable 2FA. Please try again.';
        }
    } elseif ($action === 'disable' && $enabled) {
        $password = $_POST['password'] ?? '';
        $code = trim($_POST['code'] ?? '');
        $stmt = $conn->prepare('SELECT password FROM admins WHERE admin_id = ? LIMIT 1');
        $stmt->bind_param('i', $admin_id);
        $stmt->execute();
        $stmt->bind_result($hash);
        $stmt->fetch();
        $stmt->close();

        if (!$hash || !password_verify($password, $hash)) {
            $error = 'Incorrect password.';
        } elseif (!totp_verify($admin['totp_secret'], $code)) {
            $error = 'Invalid authenticator code.';
        } elseif (admin_2fa_disable($conn, $admin_id)) {
            $message = 'Two-factor authentication has been disabled.';
            $admin = admin_2fa_fetch($conn, $admin_id);
            $enabled = false;
        } else {
            $error = 'Could not disable 2FA.';
        }
    } elseif ($action === 'regenerate_recovery' && $enabled) {
        $code = trim($_POST['code'] ?? '');
        if (!totp_verify($admin['totp_secret'], $code)) {
            $error = 'Invalid authenticator code.';
        } else {
            $recovery = admin_2fa_generate_recovery_codes();
            if (admin_2fa_save_recovery_codes($conn, $admin_id, $recovery['hashed'])) {
                $_SESSION['admin_2fa_show_recovery'] = $recovery['plain'];
                header('Location: admin_setup_2fa.php');
                exit;
            }
            $error = 'Could not regenerate recovery codes.';
        }
    }
}

if (!$enabled && admin_2fa_schema_ready($conn) && empty($_SESSION['admin_2fa_setup_secret'])) {
    $_SESSION['admin_2fa_setup_secret'] = totp_generate_secret();
}

$setup_secret = $_SESSION['admin_2fa_setup_secret'] ?? '';
$otpauth = $setup_secret !== '' ? totp_provisioning_uri($setup_secret, $admin['email'] ?? $_SESSION['admin_email'] ?? 'admin') : '';
$qr_url = $otpauth !== '' ? totp_qr_image_url($otpauth) : '';

include_once '../includes/admin_header.php';
?>

<div class="container-fluid py-4">
    <h2 class="mb-3"><i class="fas fa-shield-halved me-2 text-danger"></i>Two-factor authentication</h2>

    <?php if ($message): ?><div class="alert alert-success"><?php echo e($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo e($error); ?></div><?php endif; ?>

    <?php if ($show_recovery && is_array($show_recovery)): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <h5 class="alert-heading">Save your recovery codes</h5>
            <p class="small mb-2">Each code works once if you lose your phone. Store them securely.</p>
            <div class="row g-2">
                <?php foreach ($show_recovery as $rc): ?>
                    <div class="col-md-3"><code class="d-block p-2 bg-white rounded"><?php echo e($rc); ?></code></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!admin_2fa_schema_ready($conn)): ?>
        <p class="text-muted">Database migration required.</p>
    <?php elseif ($enabled): ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <span class="badge bg-success mb-2">Enabled</span>
                <p class="mb-0">Your account requires a code from your authenticator app when signing in.</p>
                <?php if (!empty($admin['totp_confirmed_at'])): ?>
                    <small class="text-muted">Active since <?php echo e($admin['totp_confirmed_at']); ?></small>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header">Regenerate recovery codes</div>
                    <div class="card-body">
                        <form method="post">
                            <?php echo csrf_field('admin_2fa_setup_csrf'); ?>
                            <input type="hidden" name="action" value="regenerate_recovery">
                            <div class="mb-3">
                                <label class="form-label">Current 6-digit code</label>
                                <input type="text" name="code" class="form-control" inputmode="numeric" maxlength="6" required>
                            </div>
                            <button type="submit" class="btn btn-outline-primary btn-sm">Generate new codes</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100 border-danger">
                    <div class="card-header text-danger">Disable 2FA</div>
                    <div class="card-body">
                        <form method="post" onsubmit="return confirm('Disable two-factor authentication?');">
                            <?php echo csrf_field('admin_2fa_setup_csrf'); ?>
                            <input type="hidden" name="action" value="disable">
                            <div class="mb-2">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" required>
                                <div class="form-check mt-2">
                                    <input class="form-check-input password-show-toggle" type="checkbox" id="show_2fa_password">
                                    <label class="form-check-label" for="show_2fa_password">Show password</label>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Authenticator code</label>
                                <input type="text" name="code" class="form-control" inputmode="numeric" maxlength="6" required>
                            </div>
                            <button type="submit" class="btn btn-outline-danger btn-sm">Disable 2FA</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="mb-3">Set up authenticator app</h5>
                <ol class="mb-4">
                    <li>Install <strong>Google Authenticator</strong>, <strong>Microsoft Authenticator</strong>, or similar.</li>
                    <li>Scan the QR code or enter the secret manually.</li>
                    <li>Enter the 6-digit code to confirm.</li>
                </ol>
                <div class="row align-items-center g-4">
                    <div class="col-md-4 text-center">
                        <?php if ($qr_url): ?>
                            <img src="<?php echo e($qr_url); ?>" alt="QR code" class="img-fluid border rounded" width="200" height="200">
                        <?php endif; ?>
                    </div>
                    <div class="col-md-8">
                        <p class="small text-muted mb-1">Manual entry secret:</p>
                        <code class="d-block p-2 bg-light rounded mb-3 user-select-all"><?php echo e($setup_secret); ?></code>
                        <form method="post">
                            <?php echo csrf_field('admin_2fa_setup_csrf'); ?>
                            <input type="hidden" name="action" value="enable_confirm">
                            <label class="form-label">6-digit verification code</label>
                            <div class="input-group mb-3" style="max-width:220px">
                                <input type="text" name="code" class="form-control" inputmode="numeric" maxlength="6" required>
                                <button type="submit" class="btn btn-primary">Enable</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php $conn->close(); ?>
