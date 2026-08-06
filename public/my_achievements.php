<?php
// ============================================================
//  public/my_achievements.php
//  Member achievements & badges display page
// ============================================================
require_once '../includes/session_config.php';
asc_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('location: login.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/achievements.php';
require_once '../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int) $_SESSION['member_id'];

// Member name
$stmt = $conn->prepare("SELECT first_name, last_name FROM members WHERE member_id=? LIMIT 1");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Earned achievements
$earned = asc_member_achievements($conn, $member_id);
$earned_codes = array_column($earned, 'code');

// All possible achievements (to show locked ones too)
$all = [];
if (db_table_exists($conn, 'achievements')) {
    $result = $conn->query("SELECT code, name, icon, description FROM achievements ORDER BY name");
    while ($row = $result->fetch_assoc()) $all[] = $row;
}

// Stats for context
$goals = $appearances = $motm = 0;
if (db_table_exists($conn, 'match_events')) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM match_events WHERE member_id=? AND event_type IN ('goal','penalty')");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $stmt->bind_result($goals);
    $stmt->fetch();
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(DISTINCT fixture_id) FROM match_events WHERE member_id=?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $stmt->bind_result($appearances);
    $stmt->fetch();
    $stmt->close();
}
if (db_table_exists($conn, 'motm_votes')) {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM motm_votes WHERE member_id=?");
    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $stmt->bind_result($motm);
    $stmt->fetch();
    $stmt->close();
}

$conn->close();

include '../includes/header.php';
?>
<style>
    body { background: #f8fafc !important; }
    .ach-hero {
        background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
        border-radius: 16px;
        color: #fff;
        padding: 2rem;
        margin-bottom: 1.5rem;
    }
    .ach-hero .stat-pill {
        background: rgba(255,255,255,.1);
        border: 1px solid rgba(255,255,255,.15);
        border-radius: 20px;
        padding: .3rem .9rem;
        font-size: .82rem;
        display: inline-flex; align-items: center; gap: .4rem;
    }
    .section-label {
        font-size: .7rem; font-weight: 700; letter-spacing: .1em;
        text-transform: uppercase; color: #94a3b8; margin-bottom: 1rem;
    }
    /* Badge card */
    .badge-card {
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #fff;
        padding: 1.25rem 1rem;
        text-align: center;
        transition: transform .15s, box-shadow .15s;
        position: relative;
    }
    .badge-card:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(0,0,0,.07); }
    .badge-card.locked {
        opacity: .42; filter: grayscale(1);
    }
    .badge-icon {
        font-size: 2.2rem;
        margin-bottom: .5rem;
        display: block;
    }
    .badge-name {
        font-weight: 600; font-size: .88rem; color: #1e293b;
        margin-bottom: .2rem;
    }
    .badge-desc {
        font-size: .72rem; color: #64748b; line-height: 1.4;
    }
    .badge-date {
        font-size: .68rem; color: #94a3b8;
        margin-top: .4rem;
    }
    .earned-tick {
        position: absolute; top: .6rem; right: .6rem;
        width: 20px; height: 20px;
        background: #22c55e;
        border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        font-size: .6rem; color: #fff;
    }
    .lock-icon {
        position: absolute; top: .6rem; right: .6rem;
        font-size: .75rem; color: #cbd5e1;
    }
    /* Progress bar */
    .ach-progress-wrap {
        background: #f1f5f9; border-radius: 20px;
        height: 8px; overflow: hidden; margin: .5rem 0;
    }
    .ach-progress-bar {
        height: 100%; border-radius: 20px;
        background: linear-gradient(90deg, #14497a, #1d5c8f);
    }
    .empty-state {
        text-align: center; padding: 3rem 1rem; color: #94a3b8;
    }
    .empty-state i { font-size: 3rem; margin-bottom: 1rem; display: block; }
</style>

<div class="container py-4" style="max-width: 860px;">

    <!-- Back nav -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <div>
            <h4 class="mb-0 fw-bold">Achievements &amp; Badges</h4>
            <p class="text-muted small mb-0"><?php echo e($member['first_name'] . ' ' . $member['last_name']); ?></p>
        </div>
    </div>

    <!-- Hero stats -->
    <div class="ach-hero">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <div class="flex-grow-1">
                <h5 class="mb-1 fw-bold">Your Progress</h5>
                <p class="mb-0 small" style="opacity:.7;">
                    <?php echo count($earned); ?> of <?php echo count($all); ?> badges earned
                </p>
            </div>
            <div class="text-end">
                <span class="stat-pill"><i class="fas fa-trophy text-warning"></i> <?php echo count($earned); ?> Badges</span>
            </div>
        </div>

        <!-- Overall progress bar -->
        <?php $pct = count($all) > 0 ? round(count($earned) / count($all) * 100) : 0; ?>
        <div class="ach-progress-wrap">
            <div class="ach-progress-bar" style="width:<?php echo $pct; ?>%"></div>
        </div>
        <p class="mb-3 small" style="opacity:.6;"><?php echo $pct; ?>% complete</p>

        <!-- Quick stats -->
        <div class="d-flex flex-wrap gap-2">
            <span class="stat-pill"><i class="fas fa-futbol"></i> <?php echo $goals; ?> Goals</span>
            <span class="stat-pill"><i class="fas fa-running"></i> <?php echo $appearances; ?> Appearances</span>
            <span class="stat-pill"><i class="fas fa-star"></i> <?php echo $motm; ?> MOTM</span>
        </div>
    </div>

    <!-- Earned badges -->
    <?php if (!empty($earned)): ?>
    <p class="section-label"><i class="fas fa-check-circle me-1 text-success"></i> Earned (<?php echo count($earned); ?>)</p>
    <div class="row g-3 mb-5">
        <?php foreach ($earned as $ach): ?>
        <div class="col-6 col-sm-4 col-md-3">
            <div class="badge-card">
                <span class="earned-tick"><i class="fas fa-check"></i></span>
                <span class="badge-icon"><?php echo e($ach['icon'] ?? '🏅'); ?></span>
                <div class="badge-name"><?php echo e($ach['name']); ?></div>
                <div class="badge-desc"><?php echo e($ach['description'] ?? ''); ?></div>
                <?php if (!empty($ach['awarded_at'])): ?>
                    <div class="badge-date"><i class="fas fa-calendar-alt me-1"></i><?php echo e(date('d M Y', strtotime($ach['awarded_at']))); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Locked badges -->
    <?php
    $locked = array_filter($all, fn($a) => !in_array($a['code'], $earned_codes, true));
    ?>
    <?php if (!empty($locked)): ?>
    <p class="section-label"><i class="fas fa-lock me-1"></i> Locked (<?php echo count($locked); ?>)</p>
    <div class="row g-3 mb-4">
        <?php foreach ($locked as $ach): ?>
        <div class="col-6 col-sm-4 col-md-3">
            <div class="badge-card locked">
                <span class="lock-icon"><i class="fas fa-lock"></i></span>
                <span class="badge-icon"><?php echo e($ach['icon'] ?? '🔒'); ?></span>
                <div class="badge-name"><?php echo e($ach['name']); ?></div>
                <div class="badge-desc"><?php echo e($ach['description'] ?? ''); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($all)): ?>
    <div class="empty-state">
        <i class="fas fa-medal"></i>
        <p class="fw-600 mb-1">No achievements set up yet</p>
        <p class="small">Ask your admin to add achievements from the admin panel.</p>
    </div>
    <?php elseif (empty($earned)): ?>
    <div class="empty-state">
        <i class="fas fa-star"></i>
        <p class="fw-600 mb-1">No badges earned yet</p>
        <p class="small">Keep playing, booking sessions, and participating to earn badges!</p>
    </div>
    <?php endif; ?>

</div>

<?php include '../includes/footer.php'; ?>
