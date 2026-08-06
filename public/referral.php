<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php'); exit;
}

require_once '../config/db_connect.php';
require_once '../includes/url.php';
require_once '../includes/send_email.php';
require_once '../includes/csrf.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int)$_SESSION['member_id'];
$REWARD_DAYS = 30; // Membership extension for successful referral

// ── Ensure member has a referral code ────────────────────────────────────────
$r = $conn->prepare("SELECT referral_code FROM members WHERE member_id=? LIMIT 1");
$r->bind_param('i', $member_id);
$r->execute();
$memberRow = $r->get_result()->fetch_assoc();
$r->close();

if (empty($memberRow['referral_code'])) {
    // Generate unique code (CSPRNG — 8 hex chars ≈ 32 bits entropy)
    do {
        $code = strtoupper(bin2hex(random_bytes(4)));
        $chk  = $conn->prepare("SELECT 1 FROM members WHERE referral_code=? LIMIT 1");
        $chk->bind_param('s', $code);
        $chk->execute();
        $exists = $chk->get_result()->num_rows > 0;
        $chk->close();
    } while ($exists);

    $upd = $conn->prepare("UPDATE members SET referral_code=? WHERE member_id=?");
    $upd->bind_param('si', $code, $member_id);
    $upd->execute();
    $upd->close();
    $memberRow['referral_code'] = $code;
}

$myCode = $memberRow['referral_code'];
$appUrl = defined('APP_URL') ? rtrim((string)APP_URL, '/') : '';
$referralLink = $appUrl . '/public/register.php?ref=' . urlencode($myCode);

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ref_action']) && csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
    header('Content-Type: application/json');
    $action = $_POST['ref_action'];

    if ($action === 'invite') {
        $email = trim(strtolower((string)($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['error'=>'Invalid email address.']); exit;
        }
        // Check not already invited / member
        $chk = $conn->prepare("SELECT 1 FROM member_referrals WHERE referrer_id=? AND referee_email=? LIMIT 1");
        $chk->bind_param('is', $member_id, $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            echo json_encode(['error'=>'You have already sent an invite to this email.']); exit;
        }
        $chk->close();

        // Save referral record
        $ins = $conn->prepare("INSERT INTO member_referrals (referrer_id,referee_email,code) VALUES (?,?,?)");
        $ins->bind_param('iss', $member_id, $email, $myCode);
        $ins->execute();
        $ins->close();

        // Send invite email
        $memberName = e($_SESSION['first_name'] . ' ' . ($_SESSION['last_name'] ?? ''));
        $subject  = "{$_SESSION['first_name']} invited you to join Apex Sports Club! 🏆";
        $body     = "
<p>Hi there!</p>
<p><strong>{$memberName}</strong> thinks you'd love <strong>Apex Sports Club</strong> and has personally invited you to join.</p>
<p>Click below to register and use their referral code:</p>
<div style='text-align:center;margin:1.5rem 0;'>
    <a href='{$referralLink}' style='background:#14497a;color:#fff;padding:.75rem 2rem;border-radius:10px;text-decoration:none;font-weight:700;font-size:1rem;'>
        Join Apex Sports Club →
    </a>
</div>
<p style='text-align:center;color:#64748b;'>Referral Code: <strong style='font-size:1.3rem;color:#14497a;letter-spacing:.15em;'>{$myCode}</strong></p>
<p>As a new member, you'll get access to world-class facilities, expert coaching, and an amazing community.</p>
<p>— The Apex Sports Club Team</p>
";
        $sent = function_exists('send_club_email') ? send_club_email($email, 'Friend', $subject, $body) : false;
        echo json_encode(['success'=>true,'sent'=>$sent]); exit;
    }

    echo json_encode(['error'=>'Unknown action.']); exit;
}

// ── Fetch referrals ───────────────────────────────────────────────────────────
$referrals = [];
$q2 = $conn->query("SELECT * FROM member_referrals WHERE referrer_id=$member_id ORDER BY created_at DESC");
if ($q2) while ($r2 = $q2->fetch_assoc()) $referrals[] = $r2;

$rewarded   = count(array_filter($referrals, fn($r) => $r['status'] === 'rewarded'));
$joined     = count(array_filter($referrals, fn($r) => $r['status'] === 'joined'));
$pending    = count(array_filter($referrals, fn($r) => $r['status'] === 'pending'));

$conn->close();
include_once '../includes/header.php';
?>

<style>
.ref-hero { background:linear-gradient(135deg,#14497a 0%,#1a5a8c 100%); border-radius:16px; color:#fff; padding:2rem; margin-bottom:1.5rem; }
.ref-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:1.5rem; box-shadow:0 4px 20px rgba(0,0,0,.05); }
.code-box { background:#e8f1f8; border:2px dashed #bfdbfe; border-radius:12px; padding:1.5rem; text-align:center; }
.referral-code { font-size:2.5rem; font-weight:900; letter-spacing:.2em; color:#14497a; font-family:monospace; }
.stat-tile { border:1px solid #e2e8f0; border-radius:12px; padding:1rem 1.5rem; text-align:center; flex:1; }
.stat-tile .num  { font-size:2rem; font-weight:800; }
.stat-tile .lbl  { font-size:.75rem; color:#64748b; text-transform:uppercase; font-weight:600; }
.ref-row { display:flex; align-items:center; justify-content:space-between; padding:.75rem 0; border-bottom:1px solid #f1f5f9; }
.ref-row:last-child { border-bottom:none; }
.status-badge { font-size:.75rem; font-weight:700; padding:.2rem .7rem; border-radius:12px; }
.s-pending  { background:#fef3c7; color:#d97706; }
.s-joined   { background:#d3e4f2; color:#1d5c8f; }
.s-rewarded { background:#dcfce7; color:#16a34a; }
.btn-copy { border:1.5px solid #14497a; color:#14497a; background:#fff; border-radius:8px; padding:.45rem .9rem; font-size:.85rem; font-weight:600; cursor:pointer; transition:all .15s; }
.btn-copy:hover { background:#14497a; color:#fff; }
.btn-whatsapp { background:#25d366; color:#fff; border:none; border-radius:8px; padding:.45rem .9rem; font-size:.85rem; font-weight:600; }
</style>

<div class="container py-4" style="max-width:820px;">

<div class="ref-hero">
    <div class="d-flex align-items-center gap-3">
        <div style="font-size:2.5rem;">🎁</div>
        <div>
            <h1 style="font-size:1.6rem;font-weight:800;margin:0;">Refer a Friend</h1>
            <p style="color:rgba(255,255,255,.75);margin:.25rem 0 0;">Share your code — you both win when they join and pay!</p>
        </div>
    </div>
</div>

<!-- Stats -->
<div class="d-flex gap-3 mb-3 flex-wrap">
    <div class="stat-tile"><div class="num text-warning"><?php echo $rewarded; ?></div><div class="lbl">Rewards Earned</div></div>
    <div class="stat-tile"><div class="num text-primary"><?php echo $joined; ?></div><div class="lbl">Friends Joined</div></div>
    <div class="stat-tile"><div class="num text-muted"><?php echo $pending; ?></div><div class="lbl">Invites Pending</div></div>
    <div class="stat-tile"><div class="num text-success"><?php echo $rewarded * $REWARD_DAYS; ?></div><div class="lbl">Days Earned</div></div>
</div>

<!-- Referral Code -->
<div class="ref-card mb-3">
    <h5 class="fw-bold mb-3">Your Referral Code</h5>
    <div class="code-box mb-3">
        <div class="referral-code" id="refCode"><?php echo e($myCode); ?></div>
        <div class="text-muted small mt-1">Share this code or the link below</div>
    </div>
    <div class="input-group mb-3">
        <input type="text" class="form-control" id="refLink" value="<?php echo e($referralLink); ?>" readonly>
        <button class="btn-copy" onclick="copyLink()"><i class="fas fa-copy me-1"></i>Copy</button>
    </div>
    <div class="d-flex gap-2">
        <a class="btn-whatsapp flex-grow-1 text-center" href="https://wa.me/?text=<?php echo urlencode("Hey! I'm inviting you to join Apex Sports Club 🏆 Use my referral code {$myCode} or sign up here: {$referralLink}"); ?>" target="_blank">
            <i class="fab fa-whatsapp me-1"></i>Share on WhatsApp
        </a>
    </div>
</div>

<!-- Invite by Email -->
<div class="ref-card mb-3">
    <h5 class="fw-bold mb-3">Invite by Email</h5>
    <div class="input-group">
        <input type="email" id="inviteEmail" class="form-control" placeholder="friend@example.com">
        <button class="btn btn-primary" onclick="sendInvite()" id="inviteBtn">
            <i class="fas fa-envelope me-1"></i>Send Invite
        </button>
    </div>
    <p class="text-muted small mt-2">Your friend will receive an email with your referral link.</p>
</div>

<!-- Referral History -->
<div class="ref-card">
    <h5 class="fw-bold mb-3">Referral History</h5>
    <?php if (empty($referrals)): ?>
    <div class="text-center py-4 text-muted"><div style="font-size:2rem;">📨</div><p class="mt-2">No invites sent yet. Share your code to start!</p></div>
    <?php else: ?>
    <?php foreach ($referrals as $ref): 
        $statusClass = ['pending'=>'s-pending','joined'=>'s-joined','rewarded'=>'s-rewarded'][$ref['status']] ?? 's-pending';
    ?>
    <div class="ref-row">
        <div>
            <div class="fw-semibold"><?php echo $ref['referee_member_id'] ? e('Member #'.$ref['referee_member_id']) : e($ref['referee_email'] ?? 'Invited'); ?></div>
            <div class="text-muted small"><?php echo date('d M Y', strtotime($ref['created_at'])); ?></div>
        </div>
        <span class="status-badge <?php echo $statusClass; ?>"><?php echo ucfirst($ref['status']); ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <div class="alert alert-info mt-3 mb-0">
        <i class="fas fa-gift me-2"></i>
        <strong>Reward:</strong> When a friend you referred joins and makes their first payment, you earn <strong><?php echo $REWARD_DAYS; ?> free days</strong> added to your membership!
    </div>
</div>
</div>

<script>
const SELF = '<?php echo e(app_url('public/referral.php')); ?>';
const CSRF_TOKEN = '<?php echo e(csrf_ensure('member_csrf')); ?>';

function copyLink() {
    navigator.clipboard.writeText(document.getElementById('refLink').value).then(() => {
        const btn = document.querySelector('.btn-copy');
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Copied!';
        setTimeout(() => btn.innerHTML = '<i class="fas fa-copy me-1"></i>Copy', 2000);
    });
}

async function sendInvite() {
    const email = document.getElementById('inviteEmail').value.trim();
    if (!email) { alert('Enter an email address.'); return; }
    const btn = document.getElementById('inviteBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending…';

    const fd = new FormData();
    fd.append('ref_action','invite');
    fd.append('email', email);
    fd.append('csrf_token', CSRF_TOKEN);
    const res = await fetch(SELF, {method:'POST',body:fd});
    const data = await res.json();
    btn.disabled = false; btn.innerHTML = '<i class="fas fa-envelope me-1"></i>Send Invite';

    if (data.success) {
        document.getElementById('inviteEmail').value = '';
        alert('✅ Invite sent! Your friend will receive an email shortly.');
        location.reload();
    } else { alert('❌ ' + (data.error||'Failed to send.')); }
}
</script>

<?php include_once '../includes/footer.php'; ?>
