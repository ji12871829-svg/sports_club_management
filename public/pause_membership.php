<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php'); exit;
}

require_once '../config/db_connect.php';
require_once '../includes/url.php';
require_once '../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
require_once __DIR__ . '/../includes/csrf.php';

$member_id = (int)$_SESSION['member_id'];
$MAX_PAUSE_DAYS = 90;
$MIN_PAUSE_DAYS = 1;

// Get active membership
$membership = get_active_membership($conn, $member_id);

$message = '';
$messageType = '';

// ── Handle Pause / Resume ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
    $action = $_POST['pause_action'] ?? '';

    if ($action === 'pause' && $membership) {
        $pauseDays   = max($MIN_PAUSE_DAYS, min($MAX_PAUSE_DAYS, (int)($_POST['pause_days'] ?? 14)));
        $pauseReason = trim(substr((string)($_POST['pause_reason'] ?? ''), 0, 255));
        $pauseUntil  = date('Y-m-d', strtotime("+{$pauseDays} days"));
        $now         = date('Y-m-d H:i:s');

        // Check if already paused
        $chk = $conn->prepare("SELECT paused_at FROM member_memberships WHERE membership_id=?");
        $chk->bind_param('i', $membership['membership_id']);
        $chk->execute();
        $row = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!empty($row['paused_at'])) {
            $message = 'Your membership is already paused.'; $messageType = 'warning';
        } else {
            // Check if ever paused before (one pause per cycle)
            $pauseChk = $conn->prepare("SELECT pause_days_used FROM member_memberships WHERE membership_id=?");
            $pauseChk->bind_param('i', $membership['membership_id']);
            $pauseChk->execute();
            $pRow = $pauseChk->get_result()->fetch_assoc();
            $pauseChk->close();

            if (($pRow['pause_days_used'] ?? 0) > 0) {
                $message = 'You can only pause your membership once per active cycle.'; $messageType = 'danger';
            } else {
                $stmt = $conn->prepare("UPDATE member_memberships
                    SET paused_at=?, pause_reason=?, pause_until=?, pause_days_used=?, status='Paused'
                    WHERE membership_id=?");
                $stmt->bind_param('sssii', $now, $pauseReason, $pauseUntil, $pauseDays, $membership['membership_id']);
                $stmt->execute();
                $stmt->close();
                $message = "✅ Membership paused until {$pauseUntil}. Your end date will be extended by {$pauseDays} day(s) when you resume.";
                $messageType = 'success';
                $membership = get_active_membership($conn, $member_id); // Refresh
            }
        }
    }

    if ($action === 'resume' && $membership) {
        // Get pause info
        $stmt = $conn->prepare("SELECT pause_days_used, end_date FROM member_memberships WHERE membership_id=?");
        $stmt->bind_param('i', $membership['membership_id']);
        $stmt->execute();
        $pInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($pInfo && ($pInfo['pause_days_used'] ?? 0) > 0) {
            $newEnd = date('Y-m-d', strtotime($pInfo['end_date'] . ' +' . $pInfo['pause_days_used'] . ' days'));
            $stmt2  = $conn->prepare("UPDATE member_memberships
                SET paused_at=NULL, pause_reason=NULL, pause_until=NULL, status='Active', end_date=?
                WHERE membership_id=?");
            $stmt2->bind_param('si', $newEnd, $membership['membership_id']);
            $stmt2->execute();
            $stmt2->close();
            $message = "✅ Membership resumed! Your new end date is {$newEnd}.";
            $messageType = 'success';
            $membership = get_active_membership($conn, $member_id);
        }
    }
}

// ── Get full pause details ────────────────────────────────────────────────────
$pauseInfo = null;
if ($membership) {
    $stmt3 = $conn->prepare("SELECT paused_at, pause_reason, pause_until, pause_days_used, status FROM member_memberships WHERE membership_id=?");
    $stmt3->bind_param('i', $membership['membership_id']);
    $stmt3->execute();
    $pauseInfo = $stmt3->get_result()->fetch_assoc();
    $stmt3->close();
}

$conn->close();
include_once '../includes/header.php';

$isPaused = $pauseInfo && !empty($pauseInfo['paused_at']);
$alreadyPaused = $pauseInfo && ($pauseInfo['pause_days_used'] ?? 0) > 0 && empty($pauseInfo['paused_at']);
?>

<style>
.pause-hero { background:linear-gradient(135deg,#1e293b,#334155); border-radius:16px; color:#fff; padding:1.75rem 2rem; margin-bottom:1.5rem; }
.pause-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.75rem; box-shadow:0 4px 20px rgba(0,0,0,.05); }
.status-pill { display:inline-flex; align-items:center; gap:.5rem; padding:.4rem 1rem; border-radius:20px; font-weight:700; font-size:.9rem; }
.pill-active { background:#dcfce7; color:#16a34a; }
.pill-paused { background:#fef3c7; color:#d97706; }
.days-slider { width:100%; }
.days-display { font-size:2rem; font-weight:800; color:#14497a; }
.info-grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
.info-row { background:#f8fafc; border-radius:8px; padding:.75rem 1rem; }
.info-label { font-size:.72rem; text-transform:uppercase; font-weight:700; color:#64748b; margin-bottom:.2rem; }
.info-value { font-size:.95rem; font-weight:600; color:#1e293b; }
.btn-pause  { background:linear-gradient(135deg,#f97316,#ea580c); color:#fff; border:none; border-radius:10px; padding:.7rem 1.5rem; font-weight:700; }
.btn-resume { background:linear-gradient(135deg,#059669,#10b981); color:#fff; border:none; border-radius:10px; padding:.7rem 1.5rem; font-weight:700; }
</style>

<div class="container py-4" style="max-width:680px;">

<div class="pause-hero">
    <div class="d-flex align-items-center gap-3">
        <div style="font-size:2.5rem;">⏸️</div>
        <div>
            <h1 style="font-size:1.6rem;font-weight:800;margin:0;">Pause Membership</h1>
            <p style="color:rgba(255,255,255,.7);margin:.25rem 0 0;font-size:.9rem;">Take a break — your end date will be extended when you return</p>
        </div>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?php echo e($messageType); ?> mb-3"><?php echo $message; ?></div>
<?php endif; ?>

<?php if (!$membership): ?>
<div class="pause-card text-center py-5">
    <div style="font-size:3rem;">💳</div>
    <h5 class="mt-2 fw-bold">No Active Membership</h5>
    <p class="text-muted">You need an active membership to use the pause feature.</p>
    <a href="memberships.php" class="btn btn-primary">View Membership Plans</a>
</div>

<?php elseif ($isPaused): ?>
<!-- Currently Paused -->
<div class="pause-card">
    <div class="d-flex align-items-center gap-2 mb-4">
        <span class="status-pill pill-paused"><i class="fas fa-pause-circle"></i>Membership Paused</span>
    </div>
    <div class="info-grid mb-4">
        <div class="info-row"><div class="info-label">Plan</div><div class="info-value"><?php echo e($membership['plan_name']); ?></div></div>
        <div class="info-row"><div class="info-label">Paused Since</div><div class="info-value"><?php echo date('d M Y', strtotime($pauseInfo['paused_at'])); ?></div></div>
        <div class="info-row"><div class="info-label">Paused Until</div><div class="info-value"><?php echo $pauseInfo['pause_until'] ? date('d M Y', strtotime($pauseInfo['pause_until'])) : 'Manual'; ?></div></div>
        <div class="info-row"><div class="info-label">Pause Days Used</div><div class="info-value"><?php echo (int)$pauseInfo['pause_days_used']; ?> days</div></div>
        <?php if ($pauseInfo['pause_reason']): ?>
        <div class="info-row" style="grid-column:1/-1"><div class="info-label">Reason</div><div class="info-value"><?php echo e($pauseInfo['pause_reason']); ?></div></div>
        <?php endif; ?>
    </div>
    <p class="text-muted small mb-3">Resuming will extend your membership end date by <strong><?php echo (int)$pauseInfo['pause_days_used']; ?> day(s)</strong>.</p>
    <form method="POST">
        <?php echo csrf_field('member_csrf'); ?>
        <input type="hidden" name="pause_action" value="resume">
        <button type="submit" class="btn-resume w-100"><i class="fas fa-play me-2"></i>Resume Membership Now</button>
    </form>
</div>

<?php elseif ($alreadyPaused): ?>
<div class="pause-card">
    <div class="alert alert-warning">
        <i class="fas fa-info-circle me-2"></i>
        You have already used your one-time pause for this membership cycle. Renew your membership to get a new pause allowance.
    </div>
    <div class="info-grid">
        <div class="info-row"><div class="info-label">Plan</div><div class="info-value"><?php echo e($membership['plan_name']); ?></div></div>
        <div class="info-row"><div class="info-label">Valid Until</div><div class="info-value"><?php echo date('d M Y', strtotime($membership['end_date'])); ?></div></div>
    </div>
</div>

<?php else: ?>
<!-- Active — can pause -->
<div class="pause-card">
    <div class="d-flex align-items-center gap-2 mb-4">
        <span class="status-pill pill-active"><i class="fas fa-check-circle"></i>Active — <?php echo e($membership['plan_name']); ?></span>
    </div>

    <div class="info-grid mb-4">
        <div class="info-row"><div class="info-label">Current End Date</div><div class="info-value"><?php echo date('d M Y', strtotime($membership['end_date'])); ?></div></div>
        <div class="info-row"><div class="info-label">Max Pause Duration</div><div class="info-value"><?php echo $MAX_PAUSE_DAYS; ?> days</div></div>
    </div>

    <form method="POST">
        <?php echo csrf_field('member_csrf'); ?>
        <input type="hidden" name="pause_action" value="pause">

        <div class="mb-4">
            <label class="form-label fw-semibold">How many days do you need? <span class="days-display" id="daysDisplay">14</span></label>
            <input type="range" name="pause_days" id="pauseSlider" class="form-range days-slider"
                min="<?php echo $MIN_PAUSE_DAYS; ?>" max="<?php echo $MAX_PAUSE_DAYS; ?>" value="14"
                oninput="updateDays(this.value)">
            <div class="d-flex justify-content-between small text-muted">
                <span><?php echo $MIN_PAUSE_DAYS; ?> day</span>
                <span><?php echo $MAX_PAUSE_DAYS; ?> days</span>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-semibold">Reason for pause (optional)</label>
            <input type="text" name="pause_reason" class="form-control" placeholder="e.g. Injury, travel, work commitments…" maxlength="255">
        </div>

        <div class="alert alert-info mb-4">
            <i class="fas fa-info-circle me-2"></i>
            <strong>How it works:</strong> Your membership will be paused immediately. When you resume, your end date will be automatically extended by the number of paused days.
            You can only pause <strong>once</strong> per active membership cycle.
        </div>

        <button type="submit" class="btn-pause w-100" onclick="return confirm('Pause your membership for ' + document.getElementById(\'daysDisplay\').textContent + ' days?')">
            <i class="fas fa-pause me-2"></i>Pause for <span id="pauseBtnDays">14</span> Days
        </button>
    </form>
</div>
<?php endif; ?>

<div class="mt-3 text-center">
    <a href="dashboard.php" class="text-muted small"><i class="fas fa-arrow-left me-1"></i>Back to Dashboard</a>
</div>
</div>

<script>
function updateDays(v) {
    document.getElementById('daysDisplay').textContent = v;
    document.getElementById('pauseBtnDays').textContent = v;
}
</script>

<?php include_once '../includes/footer.php'; ?>
