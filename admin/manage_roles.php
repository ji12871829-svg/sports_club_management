<?php
// ============================================================
//  admin/manage_roles.php
//  Role-Based Access Control management page
// ============================================================
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once "../includes/csrf.php";
require_once "../includes/activity_log.php";
require_once "../includes/roles.php";

$message = '';
$error = '';
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$edit_role_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;

// Handle role update (permissions)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_permissions') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $role_id = (int)($_POST['role_id'] ?? 0);
        $permissions = $_POST['permissions'] ?? [];

        $conn->begin_transaction();
        try {
            // Clear existing permissions
            $conn->query("DELETE FROM role_permissions WHERE role_id = {$role_id}");

            // Insert new permissions
            if (!empty($permissions)) {
                $stmt = $conn->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($permissions as $perm_id) {
                    $perm_id = (int)$perm_id;
                    $stmt->bind_param('ii', $role_id, $perm_id);
                    $stmt->execute();
                }
                $stmt->close();
            }

            $conn->commit();
            log_activity($conn, 'Updated role permissions', 'Roles', $role_id, 'Role ID ' . $role_id . ' permissions updated');
            $message = 'Role permissions updated successfully!';
        } catch (Throwable $e) {
            $conn->rollback();
            $error = 'Failed to update permissions: ' . $e->getMessage();
        }
    }
}

// Handle creating a new role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_role') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if (empty($name)) {
            $error = 'Role name is required.';
        } elseif (empty($slug)) {
            $slug = strtolower(preg_replace('/[^a-zA-Z0-9-]/', '-', $name));
        }

        if (empty($error)) {
            if (asc_role_exists($conn, $slug)) {
                $error = 'A role with slug "' . $slug . '" already exists.';
            } else {
                $stmt = $conn->prepare("INSERT INTO roles (name, slug, description, is_system) VALUES (?, ?, ?, 0)");
                $stmt->bind_param('sss', $name, $slug, $description);
                if ($stmt->execute()) {
                    $new_role_id = $stmt->insert_id;
                    log_activity($conn, 'Created role', 'Roles', $new_role_id, 'Created role: ' . $name);
                    $message = 'Role "' . htmlspecialchars($name) . '" created successfully!';
                } else {
                    $error = 'Failed to create role.';
                }
                $stmt->close();
            }
        }
    }
}    // Handle deleting a role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_role') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $role_id = (int)($_POST['role_id'] ?? 0);

        // Check if it's a system role
        $stmt = $conn->prepare("SELECT is_system, name FROM roles WHERE role_id = ?");
        $stmt->bind_param('i', $role_id);
        $stmt->execute();
        $role = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$role) {
            $error = 'Role not found.';
        } elseif ($role['is_system']) {
            $error = 'System roles cannot be deleted.';
        } else {
            // Check if role is assigned to any users
            $check = $conn->prepare("SELECT 
                (SELECT COUNT(*) FROM members WHERE role_id = ?) + 
                (SELECT COUNT(*) FROM admins WHERE role_id = ?) AS user_count");
            $check->bind_param('ii', $role_id, $role_id);
            $check->execute();
            $user_count = (int)$check->get_result()->fetch_assoc()['user_count'];
            $check->close();

            if ($user_count > 0) {
                $error = 'Cannot delete this role: ' . $user_count . ' user(s) are currently assigned to it. Reassign them first.';
            } else {
                $stmt = $conn->prepare("DELETE FROM roles WHERE role_id = ?");
                $stmt->bind_param('i', $role_id);
                if ($stmt->execute()) {
                    log_activity($conn, 'Deleted role', 'Roles', $role_id);
                    $message = 'Role deleted successfully.';
                } else {
                    $error = 'Failed to delete role.';
                }
                $stmt->close();
            }
        }
    }
}

$roles = asc_get_all_roles($conn);
$permissions = asc_get_all_permissions($conn);
$modules = asc_get_permission_modules($conn);
$role_counts = asc_get_role_counts($conn);
$edit_role = null;
$edit_role_perms = [];

if ($edit_role_id > 0) {
    foreach ($roles as $r) {
        if ((int)$r['role_id'] === $edit_role_id) {
            $edit_role = $r;
            break;
        }
    }
    if ($edit_role) {
        $edit_role_perms = asc_get_role_permissions($conn, $edit_role_id);
        $edit_role_perm_ids = array_map(fn($p) => $p['permission_id'], $edit_role_perms);
    }
}

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
        margin-bottom: 2rem;
    }
    .block-header {
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.25rem 1.5rem;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 0.65rem;
    }
    .block-body {
        padding: 1.5rem;
    }
    .form-label {
        font-size: 0.85rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.375rem;
    }
    .form-control, .form-select {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 0.9rem;
        padding: 0.55rem 0.85rem;
        transition: all 0.15s ease;
    }
    .form-control:focus, .form-select:focus {
        border-color: #1d5c8f;
        box-shadow: 0 0 0 3px rgba(20, 73, 122, 0.1);
        outline: 0;
    }
    .btn-primary-custom {
        background: linear-gradient(135deg, #1d5c8f, #2a6ba8);
        color: #fff;
        border: none;
        border-radius: 8px;
        padding: 0.55rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
        transition: opacity 0.15s ease;
    }
    .btn-primary-custom:hover { opacity: 0.9; color: #fff; }
    .btn-outline-custom {
        background: #fff;
        border: 1px solid #e2e8f0;
        color: #475569;
        border-radius: 8px;
        padding: 0.55rem 1.5rem;
        font-weight: 600;
        font-size: 0.9rem;
    }
    .btn-outline-custom:hover { background: #f8fafc; }
    .btn-danger-custom {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #dc2626;
        border-radius: 8px;
        padding: 0.35rem 0.75rem;
        font-weight: 600;
        font-size: 0.8rem;
    }
    .btn-danger-custom:hover { background: #fee2e2; }
    .role-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        transition: all 0.15s ease;
    }
    .role-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
    .perm-group {
        margin-bottom: 1.25rem;
    }
    .perm-group-title {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #64748b;
        margin-bottom: 0.5rem;
        padding-bottom: 0.25rem;
        border-bottom: 1px solid #f1f5f9;
    }
    .perm-check {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.85rem;
        cursor: pointer;
        transition: background 0.1s ease;
    }
    .perm-check:hover { background: #f1f5f9; }
    .perm-check input { margin: 0; }
    .badge-system {
        font-size: 0.6rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        background: #e2e8f0;
        color: #475569;
        padding: 0.15rem 0.4rem;
        border-radius: 4px;
    }
    .text-muted-sm { font-size: 0.8rem; color: #94a3b8; }
</style>

<div class="container-fluid py-4 px-4" style="max-width: 1400px;">
    <div class="row page-header-corporate">
        <div class="col-12 d-flex justify-content-between align-items-end flex-wrap gap-3">
            <div>
                <div class="brand-accent-line"></div>
                <h1 class="corporate-title mb-2">Roles & Permissions</h1>
                <p class="text-muted mb-0">Manage role-based access control for administrators and members.</p>
            </div>
            <div>
                <a href="admin_dashboard.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i> Dashboard
                </a>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success border-0 shadow-sm rounded-3 mb-4"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger border-0 shadow-sm rounded-3 mb-4"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Role Cards Grid -->
    <div class="row g-3 mb-4">
        <?php foreach ($roles as $role): 
            $count = null;
            foreach ($role_counts as $rc) {
                if ((int)$rc['role_id'] === (int)$role['role_id']) {
                    $count = $rc;
                    break;
                }
            }
        ?>
        <div class="col-md-4 col-lg-3">
            <div class="role-card p-3 h-100 d-flex flex-column">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <strong style="font-size:0.95rem;color:#0f172a;"><?php echo htmlspecialchars($role['name']); ?></strong>
                    <?php if ($role['is_system']): ?>
                        <span class="badge-system">System</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted-sm flex-grow-1 mb-2">
                    <?php echo htmlspecialchars($role['description'] ?: 'No description'); ?>
                </div>
                <div class="d-flex align-items-center justify-content-between pt-2 border-top">
                    <span class="text-muted small">
                        <i class="fas fa-users me-1"></i>
                        <?php echo ($count ? (int)$count['admin_count'] + (int)$count['member_count'] : 0); ?> users
                    </span>
                    <div class="d-flex gap-1">
                        <a href="?edit=<?php echo (int)$role['role_id']; ?>" class="btn btn-sm btn-outline-secondary" style="font-size:0.75rem;padding:0.2rem 0.5rem;">
                            <i class="fas fa-edit"></i>
                        </a>
                        <?php if (!$role['is_system']): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Delete this role?');">
                                <?php echo csrf_field('admin_csrf'); ?>
                                <input type="hidden" name="action" value="delete_role">
                                <input type="hidden" name="role_id" value="<?php echo (int)$role['role_id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger-custom" style="font-size:0.75rem;padding:0.2rem 0.5rem;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="row g-4">
        <!-- Permission Editor -->
        <div class="col-lg-8">
            <?php if ($edit_role): ?>
                <div class="corporate-block-wrapper">
                    <div class="block-header">
                        <i class="fas fa-shield-alt text-primary"></i>
                        Editing: <?php echo htmlspecialchars($edit_role['name']); ?>
                        <span class="badge bg-light text-dark ms-2 font-monospace" style="font-size:0.7rem;">
                            <?php echo count($edit_role_perms); ?> / <?php echo count($permissions); ?> permissions
                        </span>
                        <a href="manage_roles.php" class="ms-auto btn btn-sm btn-outline-secondary">Cancel</a>
                    </div>
                    <div class="block-body">
                        <form method="post">
                            <?php echo csrf_field('admin_csrf'); ?>
                            <input type="hidden" name="action" value="update_permissions">
                            <input type="hidden" name="role_id" value="<?php echo (int)$edit_role['role_id']; ?>">

                            <p class="text-muted small mb-3">
                                Select the permissions to assign to this role.
                            </p>

                            <?php $edit_role_perm_ids_set = array_flip($edit_role_perm_ids ?? []); ?>
                            <?php foreach ($modules as $module): ?>
                                <div class="perm-group">
                                    <div class="perm-group-title">
                                        <i class="fas fa-folder me-1"></i> <?php echo htmlspecialchars($module); ?>
                                    </div>
                                    <div class="d-flex flex-wrap gap-1">
                                        <?php foreach ($permissions as $perm): ?>
                                            <?php if ($perm['module'] === $module): ?>
                                                <label class="perm-check">
                                                    <input type="checkbox" name="permissions[]" 
                                                           value="<?php echo (int)$perm['permission_id']; ?>"
                                                           <?php echo isset($edit_role_perm_ids_set[(int)$perm['permission_id']]) ? 'checked' : ''; ?>>
                                                    <span title="<?php echo htmlspecialchars($perm['description']); ?>">
                                                        <?php echo htmlspecialchars($perm['name']); ?>
                                                    </span>
                                                </label>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <div class="d-flex gap-2 mt-3 pt-3 border-top">
                                <button type="submit" class="btn-primary-custom">
                                    <i class="fas fa-save me-1"></i> Save Permissions
                                </button>
                                <button type="button" class="btn-outline-custom" onclick="document.querySelectorAll('.perm-check input').forEach(c=>c.checked=true)">
                                    Select All
                                </button>
                                <button type="button" class="btn-outline-custom" onclick="document.querySelectorAll('.perm-check input').forEach(c=>c.checked=false)">
                                    Deselect All
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="corporate-block-wrapper">
                    <div class="block-header">
                        <i class="fas fa-info-circle text-primary"></i>
                        Role Permissions
                    </div>
                    <div class="block-body text-center py-5 text-muted">
                        <i class="fas fa-hand-pointer fa-2x mb-3 d-block" style="color:#cbd5e1;"></i>
                        <p>Select a role from the grid above to edit its permissions.</p>
                        <p class="small">System roles (Administrator, Super Admin, Member, etc.) are pre-configured with appropriate permissions.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Create Role -->
        <div class="col-lg-4">
            <div class="corporate-block-wrapper">
                <div class="block-header">
                    <i class="fas fa-plus-circle text-success"></i> Create New Role
                </div>
                <div class="block-body">
                    <form method="post">
                        <?php echo csrf_field('admin_csrf'); ?>
                        <input type="hidden" name="action" value="create_role">

                        <div class="mb-3">
                            <label class="form-label">Role Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Team Captain" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Slug <span class="text-muted fw-normal">(auto-generated if empty)</span></label>
                            <input type="text" name="slug" class="form-control" placeholder="e.g. team-captain">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="What can users in this role do?"></textarea>
                        </div>

                        <button type="submit" class="btn-primary-custom w-100">
                            <i class="fas fa-plus me-1"></i> Create Role
                        </button>
                    </form>
                </div>
            </div>

            <!-- Quick Info -->
            <div class="corporate-block-wrapper">
                <div class="block-header">
                    <i class="fas fa-lightbulb text-warning"></i> Tips
                </div>
                <div class="block-body">
                    <ul class="small text-muted mb-0" style="line-height:1.8;padding-left:1.2rem;">
                        <li><strong>Super Admin</strong> has unrestricted access to all features.</li>
                        <li><strong>Administrator</strong> also has full access by default.</li>
                        <li><strong>Staff</strong> have operational access but limited system settings.</li>
                        <li><strong>System roles</strong> (marked with badge) cannot be deleted.</li>
                        <li>Assign roles via the <a href="manage_members.php">Member Directory</a> or <a href="admin_profile.php">Admin Profile</a>.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include_once("../includes/footer.php"); ?>
