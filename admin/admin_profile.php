<?php
// ============================================================
//  admin/admin_profile.php
//  Admin profile page — view account info, change password, 2FA
// ============================================================
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once "../includes/csrf.php";
require_once "../includes/admin_2fa.php";
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/roles.php';

$admin_id   = (int) ($_SESSION['admin_id'] ?? 0);
$admin_email = $_SESSION['admin_email'] ?? '';
$message    = '';
$error      = '';

// Fetch current admin info
$stmt = $conn->prepare("SELECT admin_id, email, password FROM admins WHERE admin_id = ? LIMIT 1");
$stmt->bind_param("i", $admin_id);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    $error = 'Admin account not found.';
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_profile_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (!$admin) {
        $error = 'Admin account not found.';
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($current_password)) {
            $error = 'Please enter your current password.';
        } elseif (empty($new_password)) {
            $error = 'Please enter a new password.';
        } elseif (strlen($new_password) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new_password !== $confirm_password) {
            $error = 'New passwords do not match.';
        } elseif (!password_verify($current_password, $admin['password'])) {
            $error = 'Current password is incorrect.';
        } else {
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE admins SET password = ? WHERE admin_id = ?");
            $stmt->bind_param("si", $new_hash, $admin_id);
            if ($stmt->execute()) {
                $message = 'Password changed successfully.';
                $admin['password'] = $new_hash;

                require_once "../includes/activity_log.php";
                log_activity($conn, 'Admin changed password', 'Auth', $admin_id);
            } else {
                $error = 'Failed to update password. Please try again.';
            }
            $stmt->close();
        }
    }
}

// Handle email change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_email') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_profile_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (!$admin) {
        $error = 'Admin account not found.';
    } else {
        $new_email = trim($_POST['email'] ?? '');
        $password  = $_POST['password'] ?? '';

        if (empty($new_email) || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (empty($password)) {
            $error = 'Please enter your current password to change email.';
        } elseif (!password_verify($password, $admin['password'])) {
            $error = 'Password is incorrect.';
        } else {
            // Check if email is already taken by another admin
            $stmt = $conn->prepare("SELECT admin_id FROM admins WHERE email = ? AND admin_id != ? LIMIT 1");
            $stmt->bind_param("si", $new_email, $admin_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $error = 'This email is already in use by another admin account.';
            } else {
                $stmt->close();
                $stmt = $conn->prepare("UPDATE admins SET email = ? WHERE admin_id = ?");
                $stmt->bind_param("si", $new_email, $admin_id);
                if ($stmt->execute()) {
                    $_SESSION['admin_email'] = $new_email;
                    $admin['email'] = $new_email;
                    $message = 'Email address updated successfully.';

                    require_once "../includes/activity_log.php";
                    log_activity($conn, 'Admin changed email', 'Auth', $admin_id);
                } else {
                    $error = 'Failed to update email. Please try again.';
                }
            }
            $stmt->close();
        }
    }
}

// Fetch admin role
$admin_role = asc_get_user_role($conn, 'admin', $admin_id);

// Fetch all roles (for change form, if permitted)
$can_manage_roles = asc_has_permission($conn, 'roles.manage', 'admin', $admin_id);
$all_roles = $can_manage_roles ? asc_get_all_roles($conn) : [];

// Handle force-logout of all other sessions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'force_logout_others') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_profile_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (!$admin) {
        $error = 'Admin account not found.';
    } else {
        if (admin_auth_epoch_column_exists($conn)) {
            $stmt = $conn->prepare('UPDATE admins SET auth_epoch = auth_epoch + 1 WHERE admin_id = ?');
            $stmt->bind_param('i', $admin_id);
            if ($stmt->execute()) {
                $stmt->close();
                // Re-store the current session's epoch so THIS session survives.
                admin_auth_epoch_store($conn, $admin_id);
                $message = 'All other sessions have been logged out. This session stays active.';
                require_once "../includes/activity_log.php";
                log_activity($conn, 'Admin logged out all other sessions', 'Auth', $admin_id);
            } else {
                $stmt->close();
                $error = 'Failed to log out other sessions. Please try again.';
            }
        } else {
            $error = 'Run migration 062_admin_auth_epoch.sql to enable this feature.';
        }
    }
}

// Handle per-session revoke from the Active Sessions panel
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_session') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_profile_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (!$admin) {
        $error = 'Admin account not found.';
    } else {
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        if ($sessionId < 1) {
            $error = 'Invalid session.';
        } elseif (admin_sessions_revoke($conn, $admin_id, $sessionId)) {
            $message = 'Session revoked. The device will be signed out on its next request.';
            require_once "../includes/activity_log.php";
            log_activity($conn, 'Admin revoked a specific session', 'Auth', $admin_id);
        } else {
            $error = 'Could not revoke that session. It may already be inactive.';
        }
    }
}

// Handle role change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_role') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_profile_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (!$can_manage_roles) {
        $error = 'You do not have permission to change roles.';
    } else {
        $new_role_id = (int)($_POST['role_id'] ?? 0);
        if ($new_role_id < 1) {
            $error = 'Please select a valid role.';
        } elseif ($new_role_id === (int)($admin_role['role_id'] ?? 0)) {
            $error = 'This is already your current role.';
        } else {
            $stmt = $conn->prepare("SELECT role_id FROM roles WHERE role_id = ? LIMIT 1");
            $stmt->bind_param('i', $new_role_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                $error = 'Selected role does not exist.';
            } else {
                $stmt->close();
                $stmt = $conn->prepare("UPDATE admins SET role_id = ? WHERE admin_id = ?");
                $stmt->bind_param('ii', $new_role_id, $admin_id);
                if ($stmt->execute()) {
                    $message = 'Role updated successfully.';
                    $admin_role = asc_get_user_role($conn, 'admin', $admin_id);
                    require_once "../includes/activity_log.php";
                    log_activity($conn, 'Changed own role to ' . ($admin_role['name'] ?? 'Unknown'), 'Auth', $admin_id);
                } else {
                    $error = 'Failed to update role. Please try again.';
                }
                $stmt->close();
            }
        }
    }
}

$twofa_enabled = admin_2fa_schema_ready($conn) && admin_2fa_is_enabled(admin_2fa_fetch($conn, $admin_id));

// Fetch active sessions before the connection closes (rendered further down).
$active_sessions = admin_sessions_list($conn, $admin_id);

$conn->close();
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

    .corporate-block-header {
        background-color: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.25rem 1.5rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 0.65rem;
    }

    .corporate-block-body {
        padding: 1.5rem;
    }

    .form-label-corporate {
        font-size: 0.85rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.375rem;
    }

    .form-control-corporate {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.9rem;
        padding: 0.55rem 0.85rem;
        transition: all 0.15s ease-in-out;
        background-color: #ffffff;
    }

    .form-control-corporate:focus {
        border-color: #1d5c8f;
        box-shadow: 0 0 0 3px rgba(20, 73, 122, 0.1);
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
    }

    .btn-corporate-outline:hover {
        background: #f8fafc;
        border-color: #94a3b8;
        color: #0f172a;
    }

    .detail-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.85rem 0;
        border-bottom: 1px solid #f1f5f9;
    }

    .detail-row:last-child {
        border-bottom: none;
    }

    .detail-label {
        font-size: 0.85rem;
        color: #64748b;
        font-weight: 600;
    }

    .detail-value {
        font-size: 0.9rem;
        color: #0f172a;
        font-weight: 600;
    }
</style>

<div class="container-fluid py-5 px-4" style="max-width: 900px;">

    <div class="row page-header-corporate align-items-end">
        <div class="col-md-12">
            <div class="brand-accent-line"></div>
            <h1 class="corporate-title mb-2">Admin Profile</h1>
            <p class="text-muted mb-0">Manage your administrator account credentials and security settings.</p>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center gap-2">
            <i class="fas fa-check-circle me-1"></i> <?php echo e($message); ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-3 mb-4 d-flex align-items-center gap-2">
            <i class="fas fa-exclamation-circle me-1"></i> <?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <!-- Account Details -->
    <div class="corporate-block-wrapper mb-4">
        <div class="corporate-block-header">
            <i class="fas fa-id-card text-primary"></i> Account Details
        </div>
        <div class="corporate-block-body">
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-envelope me-2 text-muted" style="width: 16px;"></i>Email Address</span>
                <span class="detail-value"><?php echo e($admin['email'] ?? $admin_email); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-shield-halved me-2 text-muted" style="width: 16px;"></i>Two-Factor Authentication</span>
                <span class="detail-value">
                    <?php if ($twofa_enabled): ?>
                        <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i>Enabled</span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><i class="fas fa-times-circle me-1"></i>Not Configured</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-users-cog me-2 text-muted" style="width: 16px;"></i>Role</span>
                <span class="detail-value">
                    <?php if ($admin_role): ?>
                        <span class="badge" style="background: linear-gradient(135deg, #1d5c8f, #2a6ba8); color:#fff;">
                            <i class="fas fa-tag me-1"></i><?php echo e($admin_role['name'] ?? 'Unassigned'); ?>
                        </span>
                        <?php if (!empty($admin_role['description'])): ?>
                            <small class="text-muted ms-2" style="font-weight: 400;"><?php echo e($admin_role['description']); ?></small>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge bg-secondary"><i class="fas fa-times-circle me-1"></i>No Role Assigned</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-label"><i class="fas fa-key me-2 text-muted" style="width: 16px;"></i>Password</span>
                <span class="detail-value text-muted" style="font-weight: 400;">••••••••••</span>
            </div>
        </div>
    </div>

    <!-- Active Sessions -->
    <div class="corporate-block-wrapper mb-4">
        <div class="corporate-block-header">
            <i class="fas fa-desktop text-primary"></i> Active Sessions
        </div>
        <div class="corporate-block-body">
            <?php if (empty($active_sessions)): ?>
                <p class="text-muted mb-0" style="font-size: 0.9rem;">No active sessions recorded. <span class="text-muted" style="font-size: 0.8rem;">(Run migration 063 to enable session tracking.)</span></p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="font-size: 0.88rem;">
                        <thead>
                            <tr style="color:#64748b;font-size:0.75rem;text-transform:uppercase;letter-spacing:.04em;">
                                <th>Device</th>
                                <th>IP Address</th>
                                <th>Last Activity</th>
                                <th>Age</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($active_sessions as $s): ?>
                                <tr>
                                    <td>
                                        <?php echo e(admin_session_ua_label($s['user_agent'] ?? '')); ?>
                                        <?php if (!empty($s['is_current'])): ?>
                                            <span class="badge bg-success ms-1" style="font-size:0.65rem;"><i class="fas fa-check-circle me-1"></i>This device</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo e($s['ip_address'] ?? '—'); ?></code></td>
                                    <td>
                                        <?php echo e(admin_session_time_ago($s['last_activity'] ?? '')); ?>
                                    </td>
                                    <td><?php echo e(admin_session_age($s['created_at'] ?? '')); ?></td>
                                    <td class="text-end">
                                        <?php if (empty($s['is_current'])): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Sign out this device?');">
                                                <?php echo csrf_field('admin_profile_csrf'); ?>
                                                <input type="hidden" name="action" value="revoke_session">
                                                <input type="hidden" name="session_id" value="<?php echo (int) $s['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:0.78rem;">
                                                    <i class="fas fa-sign-out-alt me-1"></i>Sign Out
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted" style="font-size:0.78rem;">Current</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="text-muted mt-3 mb-0" style="font-size: 0.78rem;">
                    <i class="fas fa-info-circle me-1"></i> Session tracking refreshes every minute. To sign out everything at once, use <strong>Log Out Other Sessions</strong> below.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Update Email -->
    <div class="corporate-block-wrapper mb-4">
        <div class="corporate-block-header">
            <i class="fas fa-envelope-open-text text-primary"></i> Update Email Address
        </div>
        <div class="corporate-block-body">
            <form method="post">
                <?php echo csrf_field('admin_profile_csrf'); ?>
                <input type="hidden" name="action" value="change_email">

                <div class="mb-3">
                    <label class="form-label-corporate" for="email">New Email Address</label>
                    <input type="email" name="email" id="email"
                           class="form-control form-control-corporate"
                           value="<?php echo e($admin['email'] ?? $admin_email); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label-corporate" for="email_password">Current Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" id="email_password"
                           class="form-control form-control-corporate"
                           placeholder="Enter your current password to confirm changes" required autocomplete="off">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="show_email_password">
                        <label class="form-check-label" for="show_email_password" style="font-size: 0.85rem;">Show password</label>
                    </div>
                </div>

                <button type="submit" class="btn-corporate-primary">
                    <i class="fas fa-save"></i> Update Email
                </button>
            </form>
        </div>
    </div>

    <!-- Change Password -->
    <div class="corporate-block-wrapper mb-4">
        <div class="corporate-block-header">
            <i class="fas fa-lock text-danger"></i> Change Password
        </div>
        <div class="corporate-block-body">
            <form method="post">
                <?php echo csrf_field('admin_profile_csrf'); ?>
                <input type="hidden" name="action" value="change_password">

                <div class="mb-3">
                    <label class="form-label-corporate" for="current_password">Current Password <span class="text-danger">*</span></label>
                    <input type="password" name="current_password" id="current_password"
                           class="form-control form-control-corporate"
                           placeholder="Enter your current password" required autocomplete="off">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label-corporate" for="new_password">New Password <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" id="new_password"
                               class="form-control form-control-corporate"
                               placeholder="At least 8 characters" required minlength="8" autocomplete="new-password">
                        <div class="form-text" style="font-size: 0.8rem;">Minimum 8 characters</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label-corporate" for="confirm_password">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" id="confirm_password"
                               class="form-control form-control-corporate"
                               placeholder="Re-enter new password" required autocomplete="new-password">
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="show_password_fields">
                    <label class="form-check-label" for="show_password_fields" style="font-size: 0.85rem;">Show passwords</label>
                </div>

                <div class="d-flex flex-wrap gap-2 mb-3">
                    <button type="button" class="btn-corporate-outline" id="generatePasswordBtn">
                        <i class="fas fa-dice"></i> Generate Strong Password
                    </button>
                    <button type="button" class="btn-corporate-outline" id="copyGeneratedPwBtn" style="display:none;">
                        <i class="fas fa-copy"></i> Copy Password
                    </button>
                    <span id="generatedPwHint" class="align-self-center text-muted" style="font-size: 0.8rem;"></span>
                </div>

                <button type="submit" class="btn-corporate-primary">
                    <i class="fas fa-key"></i> Change Password
                </button>
            </form>
        </div>
    </div>

    <?php if ($can_manage_roles && !empty($all_roles)): ?>
    <!-- Change Role -->
    <div class="corporate-block-wrapper mb-4">
        <div class="corporate-block-header">
            <i class="fas fa-users-cog text-primary"></i> Change Role
        </div>
        <div class="corporate-block-body">
            <p class="text-muted mb-3" style="font-size: 0.9rem;">
                <i class="fas fa-info-circle me-1"></i> Changing your role modifies what you can access in the admin panel.
            </p>
            <form method="post">
                <?php echo csrf_field('admin_profile_csrf'); ?>
                <input type="hidden" name="action" value="change_role">

                <div class="row g-3 align-items-end">
                    <div class="col-md-8">
                        <label class="form-label-corporate" for="role_id">Select New Role</label>
                        <select name="role_id" id="role_id" class="form-control form-control-corporate">
                            <option value="">— Choose a role —</option>
                            <?php foreach ($all_roles as $r): ?>
                                <option value="<?php echo (int)$r['role_id']; ?>"
                                    <?php echo ((int)($admin_role['role_id'] ?? 0) === (int)$r['role_id']) ? 'selected' : ''; ?>>
                                    <?php echo e($r['name']); ?>
                                    <?php if (!empty($r['description'])): ?>— <?php echo e($r['description']); ?><?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn-corporate-primary w-100">
                            <i class="fas fa-save"></i> Update Role
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Security Actions -->
    <div class="corporate-block-wrapper">
        <div class="corporate-block-header">
            <i class="fas fa-shield-alt text-success"></i> Security Actions
        </div>
        <div class="corporate-block-body">
            <div class="d-flex flex-wrap gap-3">
                <a href="admin_setup_2fa.php" class="btn-corporate-primary" style="background: linear-gradient(135deg, #059669, #10b981);">
                    <i class="fas fa-qrcode"></i>
                    <?php echo $twofa_enabled ? 'Manage 2FA' : 'Set Up Two-Factor Authentication'; ?>
                </a>
                <a href="activity_log.php" class="btn-corporate-outline">
                    <i class="fas fa-clock-rotate-left"></i> View Activity Log
                </a>
                <form method="post" class="d-inline" onsubmit="return confirm('Log out every other active admin session? This session stays active.');">
                    <?php echo csrf_field('admin_profile_csrf'); ?>
                    <input type="hidden" name="action" value="force_logout_others">
                    <button type="submit" class="btn-corporate-outline" style="border-color: #fca5a5; color: #b91c1c;">
                        <i class="fas fa-user-shield"></i> Log Out Other Sessions
                    </button>
                </form>
                <a href="admin_dashboard.php" class="btn-corporate-outline">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>
    </div>

</div>

<script>
    // Toggle password visibility for change password form
    document.getElementById('show_password_fields')?.addEventListener('change', function() {
        const fields = ['current_password', 'new_password', 'confirm_password'];
        fields.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.type = this.checked ? 'text' : 'password';
        });
    });

    // Toggle password visibility for email change form
    document.getElementById('show_email_password')?.addEventListener('change', function() {
        const el = document.getElementById('email_password');
        if (el) el.type = this.checked ? 'text' : 'password';
    });

    // ── Generate Strong Password + Copy to Clipboard ────────────────────────
    (function() {
        const genBtn = document.getElementById('generatePasswordBtn');
        const copyBtn = document.getElementById('copyGeneratedPwBtn');
        const hint = document.getElementById('generatedPwHint');
        let generated = '';

        function generatePassword(len) {
            // Cryptographically strong randomness via Web Crypto (crypto.getRandomValues),
            // with a Math.random fallback only for non-secure contexts.
            const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
            const lower = 'abcdefghijkmnpqrstuvwxyz';
            const digits = '23456789';
            const symbols = '!@#$%^&*';
            const all = upper + lower + digits + symbols;
            const rand = (max) => {
                if (window.crypto && crypto.getRandomValues) {
                    const buf = new Uint32Array(1);
                    crypto.getRandomValues(buf);
                    return buf[0] % max;
                }
                return Math.floor(Math.random() * max);
            };
            let pw = [
                upper[rand(upper.length)],
                lower[rand(lower.length)],
                digits[rand(digits.length)],
                symbols[rand(symbols.length)],
            ];
            for (let i = pw.length; i < len; i++) pw.push(all[rand(all.length)]);
            // Fisher-Yates shuffle
            for (let i = pw.length - 1; i > 0; i--) {
                const j = rand(i + 1);
                [pw[i], pw[j]] = [pw[j], pw[i]];
            }
            return pw.join('');
        }

        if (genBtn) genBtn.addEventListener('click', function() {
            generated = generatePassword(16);
            const nw = document.getElementById('new_password');
            const cf = document.getElementById('confirm_password');
            if (nw) nw.value = generated;
            if (cf) cf.value = generated;
            if (hint) hint.textContent = 'Generated password filled in below — copy it now, it won\'t be shown again.';
            if (copyBtn) copyBtn.style.display = 'inline-flex';
        });

        if (copyBtn) copyBtn.addEventListener('click', function() {
            if (!generated) return;
            const ok = function() {
                if (copyBtn) {
                    copyBtn.innerHTML = '<i class="fas fa-check"></i> Copied!';
                    setTimeout(function() {
                        copyBtn.innerHTML = '<i class="fas fa-copy"></i> Copy Password';
                    }, 1800);
                }
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(generated).then(ok).catch(function() { fallbackCopy(generated, ok); });
            } else {
                fallbackCopy(generated, ok);
            }
        });

        function fallbackCopy(text, done) {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            done();
        }
    })();
</script>

<?php include_once("../includes/footer.php"); ?>
