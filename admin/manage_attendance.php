<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';
require_once '../includes/csrf.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';
$fixture_id = (int)($_GET['fixture_id'] ?? $_POST['fixture_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
    $fixture_id = (int)($_POST['fixture_id'] ?? 0);
    $statuses = $_POST['status'] ?? [];
    if ($fixture_id > 0 && is_array($statuses)) {
        foreach ($statuses as $member_id => $status) {
            $member_id = (int)$member_id;
            $status = in_array($status, ['Present', 'Absent', 'Excused'], true) ? $status : 'Absent';
            if ($member_id <= 0) continue;
            $stmt = $conn->prepare(
                "INSERT INTO match_attendance (fixture_id, member_id, status) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status), marked_at = CURRENT_TIMESTAMP"
            );
            $stmt->bind_param('iis', $fixture_id, $member_id, $status);
            $stmt->execute();
            $stmt->close();
        }
        log_activity($conn, 'Updated match attendance', 'Attendance', $fixture_id);
        $message = '<div class="alert alert-success">Attendance saved.</div>';
    }
}

$fixtures = $conn->query("
    SELECT f.fixture_id, f.match_date, f.match_time, h.name AS home, a.name AS away
    FROM fixtures f
    JOIN teams h ON h.team_id = f.home_team_id
    JOIN teams a ON a.team_id = f.away_team_id
    ORDER BY f.match_date DESC LIMIT 40
")->fetch_all(MYSQLI_ASSOC);

$members = [];
$attendance = [];
if ($fixture_id > 0) {
    $members = $conn->query("
        SELECT member_id, first_name, last_name FROM members ORDER BY first_name, last_name
    ")->fetch_all(MYSQLI_ASSOC);
    $res = $conn->query("SELECT member_id, status FROM match_attendance WHERE fixture_id = $fixture_id");
    while ($row = $res->fetch_assoc()) {
        $attendance[(int)$row['member_id']] = $row['status'];
    }
}
$conn->close();
?>
<div class="container-fluid py-4">
    <h1 class="fw-bold fs-4 mb-3"><i class="fas fa-clipboard-check me-2"></i>Match &amp; event attendance</h1>
    <?php echo $message; ?>
    <form method="get" class="card border-0 shadow-sm mb-4 p-3">
        <label class="form-label">Select fixture</label>
        <div class="d-flex gap-2 flex-wrap">
            <select name="fixture_id" class="form-select" style="max-width:480px;" required>
                <option value="">— Choose —</option>
                <?php foreach ($fixtures as $f): ?>
                    <option value="<?php echo (int)$f['fixture_id']; ?>" <?php echo $fixture_id === (int)$f['fixture_id'] ? 'selected' : ''; ?>>
                        <?php echo e($f['home'] . ' vs ' . $f['away'] . ' · ' . $f['match_date']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary">Load</button>
        </div>
    </form>
    <?php if ($fixture_id > 0): ?>
    <form method="post" class="card border-0 shadow-sm">
        <?php echo csrf_field('admin_csrf'); ?>
        <input type="hidden" name="fixture_id" value="<?php echo $fixture_id; ?>">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Member</th><th>Present</th><th>Absent</th><th>Excused</th></tr></thead>
                <tbody>
                <?php foreach ($members as $m):
                    $mid = (int)$m['member_id'];
                    $cur = $attendance[$mid] ?? 'Absent';
                ?>
                    <tr>
                        <td><?php echo e($m['first_name'] . ' ' . $m['last_name']); ?></td>
                        <?php foreach (['Present','Absent','Excused'] as $st): ?>
                        <td><input type="radio" name="status[<?php echo $mid; ?>]" value="<?php echo $st; ?>" <?php echo $cur === $st ? 'checked' : ''; ?>></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="p-3"><button type="submit" class="btn btn-success">Save attendance</button></div>
    </form>
    <?php endif; ?>
</div>
<?php include_once '../includes/footer.php'; ?>
