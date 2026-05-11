<?php
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['team_registration_csrf_token'])) {
    $_SESSION['team_registration_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['team_registration_csrf_token'];

$member_id = (int) $_SESSION["member_id"];
$message = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $posted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $posted_token)) {
        $message = '<div class="alert alert-danger">Security check failed. Please refresh and try again.</div>';
    } else {
        $team_id = (int) ($_POST['team_id'] ?? 0);

        if ($team_id <= 0) {
            $message = '<div class="alert alert-danger">Please select a valid team.</div>';
        } else {
            $team = null;
            $sql = "SELECT t.team_id, t.name AS team_name, t.league_id, l.name AS league_name,
                           l.max_players_per_team,
                           SUM(CASE WHEN tm.status = 'Active' THEN 1 ELSE 0 END) AS active_members
                    FROM teams t
                    JOIN leagues l ON l.league_id = t.league_id
                    LEFT JOIN team_memberships tm ON tm.team_id = t.team_id
                    WHERE t.team_id = ?
                    GROUP BY t.team_id, t.name, t.league_id, l.name, l.max_players_per_team";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("i", $team_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $team = $result->fetch_assoc();
                $stmt->close();
            }

            if (!$team) {
                $message = '<div class="alert alert-danger">Selected team was not found.</div>';
            } elseif ((int) $team['active_members'] >= (int) $team['max_players_per_team']) {
                $message = '<div class="alert alert-danger">That team roster is already full.</div>';
            } else {
                $existing = null;
                $sql = "SELECT tm.membership_id, t.name AS team_name
                        FROM team_memberships tm
                        JOIN teams t ON t.team_id = tm.team_id
                        WHERE tm.league_id = ? AND tm.member_id = ?
                        LIMIT 1";
                if ($stmt = $conn->prepare($sql)) {
                    $league_id = (int) $team['league_id'];
                    $stmt->bind_param("ii", $league_id, $member_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $existing = $result->fetch_assoc();
                    $stmt->close();
                }

                if ($existing) {
                    $message = '<div class="alert alert-warning">You are already registered in ' . e($existing['team_name']) . ' for this league.</div>';
                } else {
                    $sql = "INSERT INTO team_memberships (league_id, team_id, member_id, role, status)
                            VALUES (?, ?, ?, 'Player', 'Active')";
                    if ($stmt = $conn->prepare($sql)) {
                        $league_id = (int) $team['league_id'];
                        $stmt->bind_param("iii", $league_id, $team_id, $member_id);
                        if ($stmt->execute()) {
                            $message = '<div class="alert alert-success">You have joined ' . e($team['team_name']) . '.</div>';
                        } else {
                            $message = '<div class="alert alert-danger">Could not complete team registration.</div>';
                        }
                        $stmt->close();
                    }
                }
            }
        }
    }
}

$my_memberships = [];
$sql = "SELECT tm.league_id, tm.team_id, t.name AS team_name
        FROM team_memberships tm
        JOIN teams t ON t.team_id = tm.team_id
        WHERE tm.member_id = ?";
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $member_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $my_memberships[(int) $row['league_id']] = $row;
    }
    $stmt->close();
}

$leagues = [];
$sql = "SELECT l.league_id, l.name, l.season, l.team_limit, l.team_format, l.max_players_per_team,
               l.description, s.name AS sport_name,
               COUNT(t.team_id) AS team_count
        FROM leagues l
        JOIN sports s ON s.sport_id = l.sport_id
        LEFT JOIN teams t ON t.league_id = l.league_id
        WHERE l.status = 'Active'
        GROUP BY l.league_id, l.name, l.season, l.team_limit, l.team_format,
                 l.max_players_per_team, l.description, s.name
        ORDER BY s.name, l.name";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $leagues[] = $row;
    }
    $result->free();
}

$teams_by_league = [];
$sql = "SELECT t.team_id, t.league_id, t.name, t.short_name, t.home_ground,
               l.max_players_per_team,
               COUNT(tm.membership_id) AS player_count
        FROM teams t
        JOIN leagues l ON l.league_id = t.league_id
        LEFT JOIN team_memberships tm ON tm.team_id = t.team_id AND tm.status = 'Active'
        WHERE t.status = 'Active'
        GROUP BY t.team_id, t.league_id, t.name, t.short_name, t.home_ground, l.max_players_per_team
        ORDER BY t.name";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $teams_by_league[(int) $row['league_id']][] = $row;
    }
    $result->free();
}

$conn->close();
?>

<?php include '../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-11">
        <div class="card">
            <div class="card-header">
                <h2>Join a League Team</h2>
            </div>
            <div class="card-body">
                <?php echo $message; ?>

                <?php if (empty($leagues)): ?>
                    <div class="alert alert-info">No leagues are available yet.</div>
                <?php endif; ?>

                <?php foreach ($leagues as $league): ?>
                    <?php
                    $league_id = (int) $league['league_id'];
                    $registered = $my_memberships[$league_id] ?? null;
                    $league_teams = $teams_by_league[$league_id] ?? [];
                    ?>
                    <div class="mb-4">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                            <div>
                                <h4 class="mb-1"><?php echo e($league['sport_name'] . ' - ' . $league['name']); ?></h4>
                                <div class="text-muted small">
                                    Season <?php echo e($league['season']); ?> |
                                    <?php echo e($league['team_format']); ?> |
                                    <?php echo e($league['team_count']); ?> / <?php echo e($league['team_limit']); ?> teams
                                </div>
                            </div>
                            <?php if ($registered): ?>
                                <span class="badge bg-success">
                                    Registered: <?php echo e($registered['team_name']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Team</th>
                                        <th>Short Name</th>
                                        <th>Home Ground</th>
                                        <th>Roster</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($league_teams) > 0): ?>
                                        <?php foreach ($league_teams as $team): ?>
                                            <?php
                                            $team_is_full = (int) $team['player_count'] >= (int) $team['max_players_per_team'];
                                            $already_this_team = $registered && (int) $registered['team_id'] === (int) $team['team_id'];
                                            $already_this_league = $registered && !$already_this_team;
                                            ?>
                                            <tr>
                                                <td><?php echo e($team['name']); ?></td>
                                                <td><?php echo e($team['short_name']); ?></td>
                                                <td><?php echo e($team['home_ground']); ?></td>
                                                <td><?php echo e($team['player_count']); ?> / <?php echo e($team['max_players_per_team']); ?></td>
                                                <td>
                                                    <?php if ($already_this_team): ?>
                                                        <button class="btn btn-success btn-sm" disabled>Joined</button>
                                                    <?php elseif ($already_this_league): ?>
                                                        <button class="btn btn-secondary btn-sm" disabled>League Filled</button>
                                                    <?php elseif ($team_is_full): ?>
                                                        <button class="btn btn-secondary btn-sm" disabled>Team Full</button>
                                                    <?php else: ?>
                                                        <form action="<?php echo e($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                            <input type="hidden" name="team_id" value="<?php echo e($team['team_id']); ?>">
                                                            <button type="submit" class="btn btn-primary btn-sm">Join Team</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr><td colspan="5">No teams have been added to this league yet.</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
