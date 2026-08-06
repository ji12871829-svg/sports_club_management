<?php
// ============================================================
//  public/membership_card.php
//  Digital membership card with QR code — printable & shareable
// ============================================================
require_once '../includes/session_config.php';
asc_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('location: login.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/feature_helpers.php';
require_once '../includes/membership_gate.php';
require_once '../includes/url.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int) $_SESSION['member_id'];

// Member + membership data
$stmt = $conn->prepare("
    SELECT m.member_id, m.first_name, m.last_name, m.email, m.phone_number, m.date_joined, m.profile_photo,
           mm.start_date, mm.end_date, mm.status AS mem_status,
           mp.name AS plan_name, mp.price
    FROM members m
    LEFT JOIN member_memberships mm ON mm.member_id = m.member_id AND mm.status = 'Active'
    LEFT JOIN membership_plans mp ON mp.plan_id = mm.plan_id
    WHERE m.member_id = ? LIMIT 1
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$member) { header('location: dashboard.php'); exit; }

// QR encodes gate verification URL (scannable at club entrance)
$gate_url  = membership_gate_verify_url($member_id, (string) $member['email']);
$gate_code = 'ASC' . $member_id . '-' . membership_gate_token($member_id, (string) $member['email']);
$qr_url    = 'https://quickchart.io/qr?size=200&text=' . rawurlencode($gate_url);

// Card colour by plan
$plan = strtolower($member['plan_name'] ?? '');
if (str_contains($plan, 'gold') || str_contains($plan, 'premium') || str_contains($plan, 'vip')) {
    $card_bg    = 'linear-gradient(135deg,#92400e 0%,#d97706 100%)';
    $badge_text = 'GOLD';
} elseif (str_contains($plan, 'silver') || str_contains($plan, 'pro')) {
    $card_bg    = 'linear-gradient(135deg,#374151 0%,#6b7280 100%)';
    $badge_text = 'SILVER';
} else {
    $card_bg    = 'linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%)';
    $badge_text = 'MEMBER';
}

$is_expired = !empty($member['end_date']) && strtotime($member['end_date']) < time();
$days_left  = !empty($member['end_date']) ? (int) ceil((strtotime($member['end_date']) - time()) / 86400) : null;

include '../includes/header.php';
?>
<style>
    body { background: #f1f5f9 !important; }

    /* ── Card shell ─────────────────────────────── */
    .mc-wrap { max-width: 480px; margin: 0 auto; }

    .mc-card {
        background: <?php echo $card_bg; ?>;
        border-radius: 20px;
        color: #fff;
        padding: 2rem;
        position: relative;
        overflow: hidden;
        box-shadow: 0 20px 50px rgba(0,0,0,.25);
        user-select: none;
    }
    .mc-card::before {
        content: '';
        position: absolute; top: -60px; right: -60px;
        width: 220px; height: 220px;
        border-radius: 50%;
        background: rgba(255,255,255,.06);
        pointer-events: none;
    }
    .mc-card::after {
        content: '';
        position: absolute; bottom: -80px; left: -40px;
        width: 260px; height: 260px;
        border-radius: 50%;
        background: rgba(255,255,255,.04);
        pointer-events: none;
    }

    /* ── Club header ────────────────────────────── */
    .mc-club {
        display: flex; align-items: center; gap: .75rem;
        margin-bottom: 1.5rem;
    }
    .mc-club-icon {
        width: 40px; height: 40px;
        background: rgba(255,255,255,.15);
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        font-size: 1.1rem;
        overflow: hidden;
    }
    .mc-club-logo {
        width: 100%; height: 100%;
        object-fit: contain;
        padding: 4px;
        box-sizing: border-box;
        display: block;
    }
    .mc-club-name { font-weight: 700; font-size: 1rem; line-height: 1.2; }
    .mc-club-sub  { font-size: .72rem; opacity: .75; }

    /* ── Badge ──────────────────────────────────── */
    .mc-badge {
        position: absolute; top: 1.6rem; right: 2rem;
        background: rgba(255,255,255,.18);
        border: 1px solid rgba(255,255,255,.3);
        border-radius: 20px;
        font-size: .65rem; font-weight: 700;
        letter-spacing: .1em; padding: .2rem .7rem;
    }

    /* ── Avatar ─────────────────────────────────── */
    .mc-avatar {
        width: 70px; height: 70px;
        border-radius: 50%;
        border: 3px solid rgba(255,255,255,.4);
        object-fit: cover;
        background: rgba(255,255,255,.15);
        display: flex; align-items: center; justify-content: center;
        font-size: 1.6rem; font-weight: 700;
        overflow: hidden; flex-shrink: 0;
    }

    /* ── Name/details ───────────────────────────── */
    .mc-name    { font-size: 1.3rem; font-weight: 700; }
    .mc-plan    { font-size: .78rem; opacity: .8; margin-top: .2rem; }
    .mc-id      { font-size: .7rem; opacity: .6; font-family: monospace; margin-top: .15rem; }

    /* ── Bottom row ─────────────────────────────── */
    .mc-bottom {
        display: flex; align-items: flex-end; justify-content: space-between;
        margin-top: 1.8rem;
        gap: 1rem;
    }
    .mc-info-row { font-size: .72rem; opacity: .75; margin-bottom: .3rem; }
    .mc-info-val { font-size: .85rem; font-weight: 600; }

    /* QR box */
    .mc-qr {
        background: #fff;
        border-radius: 12px;
        padding: .5rem;
        flex-shrink: 0;
    }
    .mc-qr img { display: block; width: 90px; height: 90px; }

    /* Status badge on card */
    .mc-status-badge {
        display: inline-block;
        background: rgba(255,255,255,.15);
        border-radius: 6px;
        font-size: .7rem; padding: .15rem .5rem;
        margin-top: .4rem;
    }
    .mc-status-badge.expired { background: rgba(239,68,68,.35); }

    /* ── Info panel below card ───────────────────── */
    .mc-info-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #fff;
        padding: 1.25rem 1.5rem;
    }
    .mc-info-label { font-size: .72rem; color: #94a3b8; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; }
    .mc-info-value { font-size: .92rem; color: #1e293b; font-weight: 600; }

    /* ── Print ──────────────────────────────────── */
    @media print {
        body * { visibility: hidden; }
        .mc-card, .mc-card * { visibility: visible; }
        .mc-card { position: fixed; top: 20mm; left: 50%; transform: translateX(-50%); }
        .no-print { display: none !important; }
    }
</style>

<div class="container py-4">
    <div class="mc-wrap">

        <!-- Page header -->
        <div class="d-flex align-items-center gap-3 mb-4 no-print">
            <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
            <div class="flex-grow-1">
                <h4 class="mb-0 fw-bold">Digital Membership Card</h4>
                <p class="text-muted small mb-0">Your official Apex Sports Club ID</p>
            </div>
            <button onclick="window.print()" class="btn btn-sm btn-outline-primary rounded-pill px-3 no-print">
                <i class="fas fa-print me-1"></i> Print
            </button>
        </div>

        <!-- ══ THE CARD ══ -->
        <div class="mc-card mb-4">

            <!-- Club header -->
            <div class="mc-club">
                <div class="mc-club-icon">
                    <img src="<?php echo asc_asset(BASE_URL . '/public/assets/logo-light.svg', __DIR__ . '/../public/assets/logo-light.svg'); ?>" alt="Apex Sports Club logo" class="mc-club-logo">
                </div>
                <div>
                    <div class="mc-club-name">Apex Sports Club</div>
                    <div class="mc-club-sub">Official Membership Card</div>
                </div>
            </div>

            <!-- Plan badge top-right -->
            <span class="mc-badge"><?php echo e($badge_text); ?></span>

            <!-- Member identity -->
            <div class="d-flex align-items-center gap-3 mb-3">
                <?php if (!empty($member['profile_photo']) && file_exists(__DIR__ . '/../' . $member['profile_photo'])): ?>
                    <img src="<?php echo e('../' . $member['profile_photo']); ?>" class="mc-avatar" alt="Photo" loading="lazy" decoding="async">
                <?php else: ?>
                    <div class="mc-avatar">
                        <?php echo e(strtoupper(substr($member['first_name'],0,1).substr($member['last_name'],0,1))); ?>
                    </div>
                <?php endif; ?>
                <div>
                    <div class="mc-name"><?php echo e($member['first_name'] . ' ' . $member['last_name']); ?></div>
                    <div class="mc-plan"><?php echo e($member['plan_name'] ?? 'No Active Plan'); ?></div>
                    <div class="mc-id">ID #<?php echo str_pad($member['member_id'], 6, '0', STR_PAD_LEFT); ?></div>
                    <div class="mc-id" title="Gate scan code"><?php echo e($gate_code); ?></div>
                    <?php if ($is_expired): ?>
                        <span class="mc-status-badge expired"><i class="fas fa-exclamation-circle me-1"></i>EXPIRED</span>
                    <?php elseif ($days_left !== null): ?>
                        <span class="mc-status-badge"><i class="fas fa-check-circle me-1"></i>ACTIVE</span>
                    <?php else: ?>
                        <span class="mc-status-badge">NO ACTIVE PLAN</span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Bottom: validity + QR -->
            <div class="mc-bottom">
                <div>
                    <?php if (!empty($member['start_date'])): ?>
                        <div class="mc-info-row">VALID FROM</div>
                        <div class="mc-info-val mb-2"><?php echo e(date('d M Y', strtotime($member['start_date']))); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($member['end_date'])): ?>
                        <div class="mc-info-row">EXPIRES</div>
                        <div class="mc-info-val"><?php echo e(date('d M Y', strtotime($member['end_date']))); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($member['date_joined'])): ?>
                        <div class="mc-info-row mt-2">MEMBER SINCE</div>
                        <div class="mc-info-val"><?php echo e(date('M Y', strtotime($member['date_joined']))); ?></div>
                    <?php endif; ?>
                </div>
                <div class="mc-qr" title="Scan to view public profile">
                    <img src="<?php echo e($qr_url); ?>" alt="Member QR Code" loading="lazy" decoding="async">
                </div>
            </div>
        </div>

        <!-- Info panel -->
        <div class="mc-info-card no-print">
            <div class="row g-3">
                <div class="col-6">
                    <div class="mc-info-label">Email</div>
                    <div class="mc-info-value"><?php echo e($member['email']); ?></div>
                </div>
                <div class="col-6">
                    <div class="mc-info-label">Phone</div>
                    <div class="mc-info-value"><?php echo e($member['phone_number'] ?? '—'); ?></div>
                </div>
                <?php if ($days_left !== null && !$is_expired): ?>
                <div class="col-12">
                    <div class="mc-info-label mb-1">Membership Validity</div>
                    <div class="progress" style="height:8px; border-radius:10px;">
                        <?php
                        $total = strtotime($member['end_date']) - strtotime($member['start_date']);
                        $used  = time() - strtotime($member['start_date']);
                        $pct   = $total > 0 ? min(100, round($used / $total * 100)) : 0;
                        $colour = $pct > 80 ? 'bg-danger' : ($pct > 55 ? 'bg-warning' : 'bg-success');
                        ?>
                        <div class="progress-bar <?php echo $colour; ?>" style="width:<?php echo $pct; ?>%"></div>
                    </div>
                    <p class="text-muted small mt-1 mb-0"><?php echo $days_left; ?> day<?php echo $days_left !== 1 ? 's' : ''; ?> remaining</p>
                </div>
                <?php endif; ?>
                <?php if ($is_expired): ?>
                <div class="col-12">
                    <a href="memberships.php" class="btn btn-danger btn-sm rounded-pill px-3">
                        <i class="fas fa-refresh me-1"></i> Renew Membership
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <p class="text-muted text-center small mt-3 no-print">
            <i class="fas fa-qrcode me-1"></i> The QR code links to your public profile.
        </p>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
