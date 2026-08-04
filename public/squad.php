<?php
include_once '../includes/header.php';
require_once '../config/db_connect.php';
require_once '../includes/url.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$league_id = (int)($_GET['league_id'] ?? 0);
$leagues = $conn->query("SELECT league_id, name, season FROM leagues ORDER BY name")->fetch_all(MYSQLI_ASSOC);
if ($league_id <= 0 && !empty($leagues)) {
    $league_id = (int)$leagues[0]['league_id'];
}

$squads = [];
if ($league_id > 0) {
    $stmt = $conn->prepare("
        SELECT t.team_id, t.name AS team_name,
               m.member_id, m.first_name, m.last_name, tm.role
        FROM teams t
        LEFT JOIN team_memberships tm ON tm.team_id = t.team_id
        LEFT JOIN members m ON m.member_id = tm.member_id
        WHERE t.league_id = ?
        ORDER BY t.name, m.first_name
    ");
    $stmt->bind_param('i', $league_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $tid = (int)$row['team_id'];
        if (!isset($squads[$tid])) {
            $squads[$tid] = ['name' => $row['team_name'], 'players' => []];
        }
        if ($row['member_id']) {
            $squads[$tid]['players'][] = $row;
        }
    }
    $stmt->close();
}
$conn->close();
?>
<div class="container py-4">
    <h1 class="fw-bold mb-3">Squads &amp; players</h1>
    <form method="get" class="mb-4">
        <select name="league_id" class="form-select" style="max-width:320px;" onchange="this.form.submit()">
            <?php foreach ($leagues as $l): ?>
                <option value="<?php echo (int)$l['league_id']; ?>" <?php echo $league_id === (int)$l['league_id'] ? 'selected' : ''; ?>>
                    <?php echo e($l['name'] . ' (' . $l['season'] . ')'); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
    <div class="row g-4">
        <?php foreach ($squads as $team): ?>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-white fw-bold"><?php echo e($team['name']); ?></div>
                <ul class="list-group list-group-flush">
                    <?php if (empty($team['players'])): ?>
                        <li class="list-group-item text-muted small">No players registered.</li>
                    <?php else: foreach ($team['players'] as $p): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <a href="<?php echo e(app_url('public/member_profile.php?id=' . $p['member_id'])); ?>">
                                <?php echo e($p['first_name'] . ' ' . $p['last_name']); ?>
                            </a>
                            <span class="text-muted small"><?php echo e($p['role'] ?? 'Player'); ?></span>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
