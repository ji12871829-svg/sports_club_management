<?php
/**
 * admin/manage_sponsors.php
 * Add, edit, and manage club sponsors with tier and logo.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['sponsor_id'] ?? 0);
        $name        = trim($_POST['name']        ?? '');
        $logo_url    = trim($_POST['logo_url']    ?? '');
        $website_url = trim($_POST['website_url'] ?? '');
        $tier        = $_POST['tier'] ?? 'Bronze';
        $is_active   = isset($_POST['is_active']) ? 1 : 0;

        if (!in_array($tier, ['Bronze','Silver','Gold','Platinum'], true)) $tier = 'Bronze';

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE sponsors SET name=?,logo_url=?,website_url=?,tier=?,is_active=? WHERE sponsor_id=?");
            $stmt->bind_param("ssssii", $name, $logo_url, $website_url, $tier, $is_active, $id);
            $stmt->execute(); $stmt->close();
            log_activity($conn, 'Updated sponsor', 'Sponsors', $id, $name);
            $message = '<div class="alert alert-success">Sponsor updated.</div>';
        } else {
            $stmt = $conn->prepare("INSERT INTO sponsors (name,logo_url,website_url,tier,is_active) VALUES (?,?,?,?,?)");
            $stmt->bind_param("ssssi", $name, $logo_url, $website_url, $tier, $is_active);
            $stmt->execute();
            log_activity($conn, 'Added sponsor', 'Sponsors', $conn->insert_id, $name);
            $stmt->close();
            $message = '<div class="alert alert-success">Sponsor added.</div>';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $conn->query("DELETE FROM sponsors WHERE sponsor_id = $id");
        log_activity($conn, 'Deleted sponsor', 'Sponsors', $id);
        $message = '<div class="alert alert-success">Sponsor removed.</div>';
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $eid  = (int)$_GET['edit'];
    $stmt = $conn->prepare("SELECT * FROM sponsors WHERE sponsor_id = ?");
    $stmt->bind_param("i", $eid);
    $stmt->execute();
    $edit = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$sponsors = $conn->query("SELECT * FROM sponsors ORDER BY FIELD(tier,'Platinum','Gold','Silver','Bronze'), name ASC")->fetch_all(MYSQLI_ASSOC);
$conn->close();

$tier_colors = ['Platinum'=>'#64748b','Gold'=>'#d97706','Silver'=>'#94a3b8','Bronze'=>'#b45309'];
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:#d97706;">
            <i class="fas fa-handshake text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">Sponsor Management</h1>
            <p class="text-muted mb-0 small">Manage club sponsors — logos appear on fixture pages and homepage</p>
        </div>
    </div>

    <?php if ($message) echo $message; ?>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-<?php echo $edit ? 'edit' : 'plus-circle'; ?> me-2 text-primary"></i>
                    <?php echo $edit ? 'Edit Sponsor' : 'Add Sponsor'; ?>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="sponsor_id" value="<?php echo e($edit['sponsor_id'] ?? 0); ?>">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Company Name</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?php echo e($edit['name'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Logo URL <small class="text-muted">(optional)</small></label>
                            <input type="url" name="logo_url" class="form-control"
                                   placeholder="https://example.com/logo.png"
                                   value="<?php echo e($edit['logo_url'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Website <small class="text-muted">(optional)</small></label>
                            <input type="url" name="website_url" class="form-control"
                                   placeholder="https://example.com"
                                   value="<?php echo e($edit['website_url'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Tier</label>
                            <select name="tier" class="form-select">
                                <?php foreach (['Platinum','Gold','Silver','Bronze'] as $t): ?>
                                    <option value="<?php echo $t; ?>"
                                        <?php echo ($edit['tier'] ?? 'Bronze') === $t ? 'selected' : ''; ?>>
                                        <?php echo $t; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" name="is_active" class="form-check-input" id="active"
                                   <?php echo ($edit['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="active">Active (show on site)</label>
                        </div>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-save me-2"></i><?php echo $edit ? 'Update' : 'Add Sponsor'; ?>
                        </button>
                        <?php if ($edit): ?>
                            <a href="manage_sponsors.php" class="btn btn-outline-secondary w-100 mt-2">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-list me-2"></i>Current Sponsors
                    <span class="badge bg-secondary ms-2"><?php echo count($sponsors); ?></span>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($sponsors)): ?>
                        <div class="text-center py-5 text-muted">No sponsors added yet.</div>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Sponsor</th><th>Tier</th><th>Status</th><th>Actions</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sponsors as $s): ?>
                                <tr>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <?php if ($s['logo_url']): ?>
                                                <img src="<?php echo e($s['logo_url']); ?>" alt=""
                                                     style="height:32px;width:auto;object-fit:contain;border-radius:4px;">
                                            <?php else: ?>
                                                <div class="rounded d-flex align-items-center justify-content-center"
                                                     style="width:32px;height:32px;background:#f1f5f9;font-size:.7rem;color:#94a3b8;">
                                                    LOGO
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <div class="fw-semibold"><?php echo e($s['name']); ?></div>
                                                <?php if ($s['website_url']): ?>
                                                    <a href="<?php echo e($s['website_url']); ?>" target="_blank"
                                                       class="text-muted small"><?php echo e($s['website_url']); ?></a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge" style="background:<?php echo $tier_colors[$s['tier']]; ?>;">
                                            <?php echo e($s['tier']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $s['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $s['is_active'] ? 'Active' : 'Hidden'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="?edit=<?php echo e($s['sponsor_id']); ?>"
                                           class="btn btn-sm btn-outline-primary me-1">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('Remove this sponsor?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo e($s['sponsor_id']); ?>">
                                            <button class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
