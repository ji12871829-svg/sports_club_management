<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once '../includes/lineups.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';
$fixtureId = (int) ($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? 0);
$teamId = (int) ($_GET['team_id'] ?? $_POST['team_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = '<div class="alert alert-danger border-0 shadow-sm">Security check failed. Please refresh and try again.</div>';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'save_lineup') {
            $fixtureId = (int) ($_POST['fixture_id'] ?? 0);
            $teamId = (int) ($_POST['team_id'] ?? 0);
            $formation = trim((string) ($_POST['formation'] ?? ''));
            $starters = $_POST['starters'] ?? [];
            $subs = $_POST['substitutes'] ?? [];
            $result = asc_save_fixture_lineup(
                $conn,
                $fixtureId,
                $teamId,
                $formation,
                is_array($starters) ? $starters : [],
                is_array($subs) ? $subs : [],
                (int) ($_SESSION['admin_id'] ?? 0)
            );
            $message = $result['success']
                ? '<div class="alert alert-success border-0 shadow-sm">' . e($result['message']) . '</div>'
                : '<div class="alert alert-danger border-0 shadow-sm">' . e($result['message']) . '</div>';
        }
    }
}

$fixtures = asc_fetch_recent_fixtures_for_lineups($conn, 120);
$selectedFixture = null;
foreach ($fixtures as $fixture) {
    if ((int) $fixture['fixture_id'] === $fixtureId) {
        $selectedFixture = $fixture;
        break;
    }
}
if (!$selectedFixture && count($fixtures) > 0) {
    $selectedFixture = $fixtures[0];
    $fixtureId = (int) $selectedFixture['fixture_id'];
}

$lineups = $fixtureId > 0 ? asc_fetch_fixture_lineups($conn, $fixtureId) : [];
$members = $teamId > 0 ? asc_fetch_team_members_for_lineup($conn, $teamId) : [];
$conn->close();
?>

<style>
    body { background-color: #f8fafc !important; }
    .workspace-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px rgba(0,0,0,.05);
    }
    .pill {
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        padding: .15rem .5rem;
        font-size: .75rem;
        background: #f8fafc;
    }
</style>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <h2 class="mb-0">Squad / Lineup Builder</h2>
        <a href="manage_fixtures.php" class="btn btn-outline-secondary btn-sm">Back to fixtures</a>
    </div>

    <?php if ($message !== ''): ?>
        <div class="mb-3"><?php echo $message; ?></div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="workspace-card p-3">
                <h6 class="mb-3">Choose Fixture</h6>
                <div class="list-group" style="max-height: 520px; overflow:auto;">
                    <?php foreach ($fixtures as $f): ?>
                        <a class="list-group-item list-group-item-action <?php echo (int) $f['fixture_id'] === $fixtureId ? 'active' : ''; ?>"
                           href="?fixture_id=<?php echo (int) $f['fixture_id']; ?>">
                            <div class="fw-semibold"><?php echo e($f['home_team'] . ' vs ' . $f['away_team']); ?></div>
                            <small class="<?php echo (int) $f['fixture_id'] === $fixtureId ? 'text-white-50' : 'text-muted'; ?>">
                                <?php echo e(date('d M Y', strtotime($f['match_date'])) . ' · ' . $f['league_name'] . ' · ' . $f['status']); ?>
                            </small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <?php if ($selectedFixture): ?>
                <div class="workspace-card p-3 mb-3">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <div>
                            <div class="fw-bold"><?php echo e($selectedFixture['home_team'] . ' vs ' . $selectedFixture['away_team']); ?></div>
                            <small class="text-muted"><?php echo e($selectedFixture['league_name'] . ' · ' . date('d M Y', strtotime($selectedFixture['match_date']))); ?></small>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-sm <?php echo $teamId === (int) $selectedFixture['home_team_id'] ? 'btn-primary' : 'btn-outline-primary'; ?>"
                               href="?fixture_id=<?php echo $fixtureId; ?>&team_id=<?php echo (int) $selectedFixture['home_team_id']; ?>">
                                <?php echo e($selectedFixture['home_team']); ?>
                            </a>
                            <a class="btn btn-sm <?php echo $teamId === (int) $selectedFixture['away_team_id'] ? 'btn-primary' : 'btn-outline-primary'; ?>"
                               href="?fixture_id=<?php echo $fixtureId; ?>&team_id=<?php echo (int) $selectedFixture['away_team_id']; ?>">
                                <?php echo e($selectedFixture['away_team']); ?>
                            </a>
                        </div>
                    </div>
                </div>

                <?php if ($teamId > 0): ?>
                    <div class="workspace-card p-3 mb-3">
                        <h6 class="mb-3">Publish Lineup</h6>
                        <form method="post">
                            <?php echo csrf_field('admin_csrf'); ?>
                            <input type="hidden" name="action" value="save_lineup">
                            <input type="hidden" name="fixture_id" value="<?php echo (int) $fixtureId; ?>">
                            <input type="hidden" name="team_id" value="<?php echo (int) $teamId; ?>">

                            <div class="mb-3">
                                <label class="form-label">Formation (optional)</label>
                                <input type="text" class="form-control" name="formation" placeholder="e.g. 4-3-3">
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Starting XI (max 11)</label>
                                    <select name="starters[]" class="form-select" multiple size="10">
                                        <?php foreach ($members as $m): ?>
                                            <option value="<?php echo (int) $m['member_id']; ?>">
                                                <?php echo e($m['first_name'] . ' ' . $m['last_name'] . ' · ' . $m['role']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Substitutes (max 12)</label>
                                    <select name="substitutes[]" class="form-select" multiple size="10">
                                        <?php foreach ($members as $m): ?>
                                            <option value="<?php echo (int) $m['member_id']; ?>">
                                                <?php echo e($m['first_name'] . ' ' . $m['last_name'] . ' · ' . $m['role']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-text mt-2">Hold Ctrl/Cmd to multi-select players.</div>
                            <button class="btn btn-primary mt-3">Save and Publish Lineup</button>
                        </form>
                    </div>
                <?php endif; ?>

                <div class="workspace-card p-3">
                    <h6 class="mb-3">Published Lineups</h6>
                    <?php if (empty($lineups)): ?>
                        <p class="text-muted small mb-0">No lineups published for this fixture yet.</p>
                    <?php else: ?>
                        <?php foreach ($lineups as $lineup): ?>
                            <div class="border rounded p-2 mb-2">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong><?php echo e($lineup['team_name']); ?></strong>
                                    <span class="pill"><?php echo e($lineup['formation'] ?: 'No formation'); ?></span>
                                </div>
                                <div><small class="text-muted">Starting XI</small></div>
                                <div class="mb-2">
                                    <?php foreach ($lineup['starters'] as $p): ?>
                                        <span class="badge bg-light text-dark border me-1 mb-1"><?php echo e($p); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <div><small class="text-muted">Bench</small></div>
                                <div>
                                    <?php foreach ($lineup['substitutes'] as $p): ?>
                                        <span class="badge bg-light text-dark border me-1 mb-1"><?php echo e($p); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>
