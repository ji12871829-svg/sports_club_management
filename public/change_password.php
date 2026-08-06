<?php
// ============================================================
//  public/change_password.php
//  Member password change form (not password reset via email)
// ============================================================
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
asc_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('location: login.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/password_policy.php';

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'change_password_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $member_id = (int) $_SESSION['member_id'];
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Fetch current password hash
        $current_hash = '';
        $stmt = $conn->prepare("SELECT password FROM members WHERE member_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $member_id);
            $stmt->execute();
            $stmt->bind_result($current_hash);
            $stmt->fetch();
            $stmt->close();
        }

        if (empty($current_password)) {
            $error = 'Please enter your current password.';
        } elseif (empty($current_hash)) {
            $error = 'Account not found. Please contact support.';
        } elseif (!password_verify($current_password, $current_hash)) {
            $error = 'Current password is incorrect.';
        } elseif (empty($new_password)) {
            $error = 'Please enter a new password.';            } elseif ($new_password === $current_password) {
                $error = 'New password must be different from your current password.';
        } else {
            $policy = asc_validate_password_strength($new_password);
            if (!$policy['ok']) {
                $error = $policy['message'];
            } elseif ($new_password !== $confirm_password) {
                $error = 'New passwords do not match.';
            } else {
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE members SET password = ? WHERE member_id = ?");
                $stmt->bind_param('si', $new_hash, $member_id);
                if ($stmt->execute()) {
                    $message = 'Password changed successfully!';
                } else {
                    $error = 'Failed to update password. Please try again.';
                }
                $stmt->close();
            }
        }
    }
}

$conn->close();
include '../includes/header.php';
?>

<style>
    body {
        background-color: #f8fafc !important;
        color: #334155 !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    .page-header-corporate {
        border-bottom: 1px solid #e2e8f0;
        margin-bottom: 2.5rem;
        padding-bottom: 1.25rem;
    }

    .corporate-title {
        color: #0f172a;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .brand-accent-line {
        width: 40px;
        height: 4px;
        background-color: #1d5c8f;
        border-radius: 2px;
        margin-bottom: 1rem;
    }

    .corporate-block-wrapper {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }

    .corporate-block-body {
        padding: 2rem;
    }

    .form-label-corporate {
        font-size: 0.85rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.5rem;
    }

    .form-control-corporate {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.9rem;
        padding: 0.55rem 0.85rem;
        transition: all 0.15s ease;
        background-color: #ffffff;
        width: 100%;
    }

    .form-control-corporate:focus {
        border-color: #1d5c8f;
        box-shadow: 0 0 0 3px rgba(20, 73, 122, 0.1);
        outline: 0;
    }

    .btn-corporate-primary {
        background: linear-gradient(135deg, #1d5c8f, #2a6ba8);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 0.55rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        transition: opacity 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
    }

    .btn-corporate-primary:hover {
        opacity: 0.9;
        color: #fff;
    }

    .btn-corporate-outline {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        color: #475569;
        border-radius: 8px;
        padding: 0.55rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        transition: all 0.15s ease;
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        text-decoration: none;
    }

    .btn-corporate-outline:hover {
        background: #f8fafc;
        border-color: #94a3b8;
        color: #0f172a;
    }

    .password-hint {
        font-size: 0.8rem;
        color: #64748b;
        margin-top: 0.25rem;
    }
</style>

<div class="container py-5" style="max-width: 600px;">
    <div class="row page-header-corporate">
        <div class="col-12">
            <div class="brand-accent-line"></div>
            <h1 class="corporate-title mb-2">Change Password</h1>
            <p class="text-muted mb-0">Update your account password. You'll need to know your current password.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center gap-2">
            <i class="fas fa-check-circle me-1"></i> <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center gap-2">
            <i class="fas fa-exclamation-circle me-1"></i> <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="corporate-block-wrapper">
        <div class="corporate-block-body">
            <form method="post">
                <?php echo csrf_field('change_password_csrf'); ?>

                <div class="mb-3">
                    <label for="current_password" class="form-label-corporate">Current Password <span class="text-danger">*</span></label>
                    <input type="password" name="current_password" id="current_password"
                           class="form-control form-control-corporate"
                           placeholder="Enter your current password" required autocomplete="current-password">
                </div>

                <div class="mb-3">
                    <label for="new_password" class="form-label-corporate">New Password <span class="text-danger">*</span></label>
                    <input type="password" name="new_password" id="new_password"
                           class="form-control form-control-corporate"
                           placeholder="At least 8 characters with uppercase, lowercase, number, and special character"
                           required minlength="8" autocomplete="new-password">
                    <div class="password-hint">
                        <i class="fas fa-info-circle me-1"></i>
                        Use 8+ characters with uppercase, lowercase, a number, and a special character.
                    </div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label-corporate">Confirm New Password <span class="text-danger">*</span></label>
                    <input type="password" name="confirm_password" id="confirm_password"
                           class="form-control form-control-corporate"
                           placeholder="Re-enter your new password" required autocomplete="new-password">
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn-corporate-primary">
                        <i class="fas fa-key"></i> Change Password
                    </button>
                    <a href="dashboard.php" class="btn-corporate-outline">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
