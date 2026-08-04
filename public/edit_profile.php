<?php
// ============================================================
//  public/edit_profile.php
//  Enhanced member profile editor — photo, bio, emergency contact
// ============================================================
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
asc_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('location: login.php');
    exit;
}

require_once '../config/db_connect.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int) $_SESSION['member_id'];
$success = $error = '';


// ── Extra columns (bio, emergency_name, emergency_phone, emergency_relation,
//    profile_photo, date_of_birth) are now managed by migrations
//    046_add_profile_photo_to_members.sql and 047_add_emergency_columns_to_members.sql.
//    Run `php scripts/migrate.php` if they are missing.
// ── Fetch current data ────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT first_name, last_name, email, phone_number, address,
    bio, emergency_name, emergency_phone, emergency_relation, profile_photo, date_of_birth, show_in_directory
    FROM members WHERE member_id = ? LIMIT 1");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$m = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$m) { header('location: dashboard.php'); exit; }

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'edit_profile_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $first_name       = trim($_POST['first_name'] ?? '');
        $last_name        = trim($_POST['last_name'] ?? '');
        $phone_number     = trim($_POST['phone_number'] ?? '');
        $address          = trim($_POST['address'] ?? '');
        $bio              = trim($_POST['bio'] ?? '');
        $dob              = trim($_POST['date_of_birth'] ?? '');
        $emg_name         = trim($_POST['emergency_name'] ?? '');
        $emg_phone        = trim($_POST['emergency_phone'] ?? '');
        $emg_relation     = trim($_POST['emergency_relation'] ?? '');

        if (empty($first_name) || empty($last_name)) {
            $error = 'First and last name are required.';
        }

        // ── Photo upload ──────────────────────────────────────────────────────
        $photo_path = $m['profile_photo']; // keep existing by default
        if (!empty($_FILES['profile_photo']['name'])) {
            $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            $finfo   = finfo_open(FILEINFO_MIME_TYPE);
            $mime    = finfo_file($finfo, $_FILES['profile_photo']['tmp_name']);
            finfo_close($finfo);

            if (!in_array($mime, $allowed, true)) {
                $error = 'Only JPG, PNG, WebP or GIF images are allowed.';
            } elseif ($_FILES['profile_photo']['size'] > 2 * 1024 * 1024) {
                $error = 'Image must be smaller than 2 MB.';
            } else {
                $upload_dir = __DIR__ . '/../uploads/profile_photos/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                // Derive the extension from the sniffed MIME type, never from the
                // client-supplied filename — an uploaded 'x.php' must not keep a
                // server-side script extension even though the bytes are an image.
                $ext      = match ($mime) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/gif'  => 'gif',
                    'image/webp' => 'webp',
                    default      => 'jpg',
                };
                $filename = 'member_' . $member_id . '_' . time() . '.' . $ext;
                if (move_uploaded_file($_FILES['profile_photo']['tmp_name'], $upload_dir . $filename)) {
                    // Delete old photo if it exists
                    if (!empty($m['profile_photo'])) {
                        $old = __DIR__ . '/../uploads/profile_photos/' . basename($m['profile_photo']);
                        if (file_exists($old)) @unlink($old);
                    }
                    $photo_path = 'uploads/profile_photos/' . $filename;
                } else {
                    $error = 'Photo upload failed. Please try again.';
                }
            }
        }

        $show_in_directory = isset($_POST['show_in_directory']) ? 1 : 0;

        if (empty($error)) {
            $stmt = $conn->prepare("UPDATE members
                SET first_name=?, last_name=?, phone_number=?, address=?, bio=?,
                    emergency_name=?, emergency_phone=?, emergency_relation=?,
                    profile_photo=?, date_of_birth=?, show_in_directory=?
                WHERE member_id=?");
            $dob_val = $dob ?: null;
            $stmt->bind_param('ssssssssssii',
                $first_name, $last_name, $phone_number, $address, $bio,
                $emg_name, $emg_phone, $emg_relation,
                $photo_path, $dob_val, $show_in_directory, $member_id
            );
            if ($stmt->execute()) {
                $_SESSION['first_name'] = $first_name;
                $success = 'Profile updated successfully!';
                // Refresh displayed data
                $m = array_merge($m, compact(
                    'first_name','last_name','phone_number','address','bio',
                    'emg_name','emg_phone','emg_relation','dob_val','show_in_directory'
                ));
                $m['profile_photo']      = $photo_path;
                $m['emergency_name']     = $emg_name;
                $m['emergency_phone']    = $emg_phone;
                $m['emergency_relation'] = $emg_relation;
                $m['date_of_birth']      = $dob_val;
                $m['show_in_directory']  = $show_in_directory;
            } else {
                $error = 'Update failed. Please try again.';
            }
            $stmt->close();
        }
    }
}

require_once '../includes/csrf.php';
include '../includes/header.php';
?>
<style>
    body { background: #f8fafc !important; }
    .ep-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 4px rgba(0,0,0,.05);
    }
    .ep-section-title {
        font-size: .7rem;
        font-weight: 700;
        letter-spacing: .1em;
        text-transform: uppercase;
        color: #94a3b8;
        margin-bottom: 1rem;
    }
    .avatar-wrap {
        position: relative;
        width: 100px;
        height: 100px;
    }
    .avatar-wrap img, .avatar-wrap .avatar-placeholder {
        width: 100px; height: 100px;
        border-radius: 50%;
        object-fit: cover;
        border: 3px solid #e2e8f0;
    }
    .avatar-placeholder {
        background: linear-gradient(135deg,#4f46e5,#6366f1);
        display: flex; align-items: center; justify-content: center;
        font-size: 2.2rem; color: #fff; font-weight: 700;
    }
    .avatar-edit-btn {
        position: absolute; bottom: 2px; right: 2px;
        background: #4f46e5; color: #fff;
        border-radius: 50%; width: 28px; height: 28px;
        display: flex; align-items: center; justify-content: center;
        font-size: .7rem; cursor: pointer; border: 2px solid #fff;
    }
    .form-label { font-size: .85rem; font-weight: 600; color: #475569; }
    .form-control, .form-select {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: .9rem;
        padding: .55rem .85rem;
    }
    .form-control:focus, .form-select:focus {
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79,70,229,.1);
    }
    .btn-save {
        background: linear-gradient(135deg,#4f46e5,#6366f1);
        color: #fff; border: none; border-radius: 9px;
        padding: .6rem 2rem; font-weight: 600;
        transition: opacity .15s;
    }
    .btn-save:hover { opacity: .88; color: #fff; }
    textarea.form-control { resize: vertical; min-height: 90px; }
</style>

<div class="container py-4" style="max-width:780px;">

    <!-- Page header -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="member_profile.php?id=<?php echo $member_id; ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <div>
            <h4 class="mb-0 fw-700">Edit Profile</h4>
            <p class="text-muted small mb-0">Update your personal information</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success border-0 rounded-3 mb-4">
            <i class="fas fa-check-circle me-2"></i><?php echo e($success); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger border-0 rounded-3 mb-4">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php echo csrf_field('edit_profile_csrf'); ?>

        <!-- Photo + Basic Info -->
        <div class="ep-card p-4 mb-4">
            <p class="ep-section-title"><i class="fas fa-user me-1"></i> Basic Information</p>

            <div class="d-flex align-items-center gap-4 mb-4">
                <div class="avatar-wrap flex-shrink-0">
                    <?php if (!empty($m['profile_photo']) && file_exists(__DIR__ . '/../' . $m['profile_photo'])): ?>
                        <img src="<?php echo e('../' . $m['profile_photo']); ?>" id="avatarPreview" alt="Profile photo">
                    <?php else: ?>
                        <div class="avatar-placeholder" id="avatarPlaceholder">
                            <?php echo e(strtoupper(substr($m['first_name'],0,1) . substr($m['last_name'],0,1))); ?>
                        </div>
                        <img src="" id="avatarPreview" alt="Profile photo" style="display:none;">
                    <?php endif; ?>
                    <label class="avatar-edit-btn" for="photoInput" title="Change photo">
                        <i class="fas fa-camera"></i>
                    </label>
                </div>
                <div class="flex-grow-1">
                    <p class="mb-1 small fw-600">Profile Photo</p>
                    <p class="text-muted small mb-2">JPG, PNG or WebP · max 2 MB</p>
                    <input type="file" name="profile_photo" id="photoInput" accept="image/*" class="d-none" onchange="previewPhoto(this)">
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">First Name <span class="text-danger">*</span></label>
                    <input type="text" name="first_name" class="form-control" value="<?php echo e($m['first_name']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Last Name <span class="text-danger">*</span></label>
                    <input type="text" name="last_name" class="form-control" value="<?php echo e($m['last_name']); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone_number" class="form-control" value="<?php echo e($m['phone_number'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="date_of_birth" class="form-control"
                        value="<?php echo e($m['date_of_birth'] ?? ''); ?>"
                        max="<?php echo date('Y-m-d', strtotime('-5 years')); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <input type="text" name="address" class="form-control" value="<?php echo e($m['address'] ?? ''); ?>">
                </div>
                <div class="col-12">
                    <label class="form-label">Bio <span class="text-muted fw-400">(optional — displayed on your public profile)</span></label>
                    <textarea name="bio" class="form-control"><?php echo e($m['bio'] ?? ''); ?></textarea>
                </div>
                <div class="col-12 mt-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="show_in_directory" id="showInDirectory" value="1" <?php echo (!empty($m['show_in_directory'])) ? 'checked' : ''; ?>>
                        <label class="form-check-label fw-bold" for="showInDirectory">
                            List me in the Member Directory
                        </label>
                        <div class="form-text mt-0">
                            Allow other club members to see your name, sports, and position in the directory. Your private details (phone, email, address) will never be shared.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Emergency Contact -->
        <div class="ep-card p-4 mb-4">
            <p class="ep-section-title"><i class="fas fa-phone-alt me-1"></i> Emergency Contact</p>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label">Contact Name</label>
                    <input type="text" name="emergency_name" class="form-control" value="<?php echo e($m['emergency_name'] ?? ''); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="emergency_phone" class="form-control" value="<?php echo e($m['emergency_phone'] ?? ''); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Relationship</label>
                    <select name="emergency_relation" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach (['Parent','Spouse','Sibling','Friend','Guardian','Other'] as $rel): ?>
                            <option value="<?php echo e($rel); ?>" <?php echo (($m['emergency_relation'] ?? '') === $rel) ? 'selected' : ''; ?>>
                                <?php echo e($rel); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="member_profile.php?id=<?php echo $member_id; ?>" class="btn btn-outline-secondary rounded-pill px-4">Cancel</a>
            <button type="submit" class="btn-save btn">
                <i class="fas fa-save me-1"></i> Save Changes
            </button>
        </div>
    </form>
</div>

<script>
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const preview = document.getElementById('avatarPreview');
            const placeholder = document.getElementById('avatarPlaceholder');
            preview.src = e.target.result;
            preview.style.display = 'block';
            if (placeholder) placeholder.style.display = 'none';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

<?php include '../includes/footer.php'; ?>
