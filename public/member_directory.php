<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('Location: login.php'); exit;
}

require_once '../config/db_connect.php';
require_once '../includes/url.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int)$_SESSION['member_id'];

// Filters
$search   = trim((string)($_GET['q'] ?? ''));
$sportFil = (int)($_GET['sport'] ?? 0);
$teamFil  = (int)($_GET['team'] ?? 0);

// Fetch sports & teams for filters
$sports = [];
$qs = $conn->query("SELECT sport_id, name FROM sports ORDER BY name");
if ($qs) while ($r = $qs->fetch_assoc()) $sports[] = $r;

$teams = [];
$qt = $conn->query("SELECT team_id, name FROM teams ORDER BY name");
if ($qt) while ($r = $qt->fetch_assoc()) $teams[] = $r;

// ── Build query ───────────────────────────────────────────────────────────────
$conditions = ["m.show_in_directory = 1", "m.member_id != $member_id"];
$params     = [];
$types      = '';

if ($search !== '') {
    $like = '%' . $conn->real_escape_string($search) . '%';
    $conditions[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.position LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}

if ($sportFil) {
    $conditions[] = "m.sport_id = ?";
    $params[] = $sportFil; $types .= 'i';
}

if ($teamFil) {
    $conditions[] = "EXISTS (SELECT 1 FROM team_memberships tm WHERE tm.member_id=m.member_id AND tm.team_id=?)";
    $params[] = $teamFil; $types .= 'i';
}

$where = 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT m.member_id, m.first_name, m.last_name, m.position,
               s.name AS sport_name,
               GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS teams
        FROM members m
        LEFT JOIN sports s ON s.sport_id = m.sport_id
        LEFT JOIN team_memberships tp ON tp.member_id = m.member_id
        LEFT JOIN teams t ON t.team_id = tp.team_id
        $where
        GROUP BY m.member_id
        ORDER BY m.first_name, m.last_name
        LIMIT 200";

$members = [];
if ($params) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $members = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $res = $conn->query($sql);
    if ($res) while ($r = $res->fetch_assoc()) $members[] = $r;
}

$conn->close();
include_once '../includes/header.php';
?>
<style>
.dir-hero { background:linear-gradient(135deg,#0f766e 0%,#0d9488 100%); border-radius:16px; color:#fff; padding:1.75rem 2rem; margin-bottom:1.5rem; }
.member-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:1.25rem; transition:box-shadow .15s,transform .15s; text-decoration:none; display:block; color:inherit; }
.member-card:hover { box-shadow:0 8px 25px rgba(0,0,0,.1); transform:translateY(-2px); color:inherit; }
.avatar-circle { width:52px; height:52px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:1.2rem; font-weight:800; color:#fff; flex-shrink:0; }
.member-name { font-weight:700; font-size:.95rem; color:#0f172a; }
.member-sub  { font-size:.78rem; color:#64748b; margin-top:.15rem; }
.member-tags { display:flex; flex-wrap:wrap; gap:.35rem; margin-top:.6rem; }
.tag { background:#f1f5f9; color:#475569; font-size:.72rem; font-weight:600; padding:.2rem .6rem; border-radius:10px; }
.search-bar { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:.5rem 1rem; display:flex; align-items:center; gap:.75rem; box-shadow:0 2px 8px rgba(0,0,0,.04); }
.search-bar input { border:none; outline:none; flex:1; font-size:.92rem; }
.count-badge { background:rgba(255,255,255,.15); border-radius:8px; padding:.25rem .75rem; font-size:.85rem; font-weight:600; }
</style>

<div class="container py-4">

<div class="dir-hero">
    <div class="d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3">
            <div style="font-size:2.5rem;">👥</div>
            <div>
                <h1 style="font-size:1.6rem;font-weight:800;margin:0;">Member Directory</h1>
                <p style="color:rgba(255,255,255,.75);margin:.25rem 0 0;font-size:.9rem;">Connect with fellow club members</p>
            </div>
        </div>
        <span class="count-badge"><?php echo count($members); ?> members</span>
    </div>
</div>

<!-- Search & Filters -->
<form method="GET" class="mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <div class="search-bar">
                <i class="fas fa-search text-muted"></i>
                <input type="text" name="q" value="<?php echo e($search); ?>" placeholder="Search by name or position…">
            </div>
        </div>
        <div class="col-md-3">
            <select name="sport" class="form-select">
                <option value="">All Sports</option>
                <?php foreach ($sports as $s): ?>
                <option value="<?php echo $s['sport_id']; ?>" <?php echo $sportFil==$s['sport_id']?'selected':''; ?>>
                    <?php echo e($s['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <select name="team" class="form-select">
                <option value="">All Teams</option>
                <?php foreach ($teams as $t): ?>
                <option value="<?php echo $t['team_id']; ?>" <?php echo $teamFil==$t['team_id']?'selected':''; ?>>
                    <?php echo e($t['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
        </div>
    </div>
</form>

<?php if (empty($members)): ?>
<div class="text-center py-5 text-muted">
    <div style="font-size:3rem;">🔍</div>
    <p class="mt-2">No members found<?php echo $search ? ' for "' . e($search) . '"' : ''; ?>.</p>
    <?php if (!$search && !$sportFil): ?>
    <p class="small">Members can opt into the directory from their <a href="edit_profile.php">profile settings</a>.</p>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($members as $m):
        // Generate avatar color from name
        $hue = crc32($m['first_name']) % 360;
        $initial = strtoupper(substr($m['first_name'],0,1));
    ?>
    <div class="col-lg-3 col-md-4 col-sm-6">
        <a class="member-card" href="member_profile.php?id=<?php echo $m['member_id']; ?>">
            <div class="d-flex align-items-center gap-3 mb-2">
                <div class="avatar-circle" style="background:hsl(<?php echo $hue; ?>,60%,48%);">
                    <?php echo $initial; ?>
                </div>
                <div>
                    <div class="member-name"><?php echo e($m['first_name'].' '.$m['last_name']); ?></div>
                    <div class="member-sub"><?php echo e($m['position'] ?: 'Member'); ?></div>
                </div>
            </div>
            <div class="member-tags">
                <?php if ($m['sport_name']): ?><span class="tag"><i class="fas fa-futbol me-1"></i><?php echo e($m['sport_name']); ?></span><?php endif; ?>
                <?php if ($m['teams']): foreach (explode(', ', $m['teams']) as $t): ?><span class="tag"><?php echo e($t); ?></span><?php endforeach; endif; ?>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="alert alert-info mt-4">
    <i class="fas fa-lock me-2"></i>
    <strong>Privacy:</strong> Only members who opted in to the directory are shown.
    <a href="edit_profile.php" class="alert-link ms-1">Update your visibility settings →</a>
</div>
</div>

<?php include_once '../includes/footer.php'; ?>
