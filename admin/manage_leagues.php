<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['league_csrf_token'])) {
    $_SESSION['league_csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['league_csrf_token'];

$message = '';

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $posted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $posted_token)) {
        $message = '<div class="alert alert-danger">Security check failed. Please refresh and try again.</div>';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_team') {
            $league_id = (int) ($_POST['league_id'] ?? 0);
            $team_name = trim($_POST['team_name'] ?? '');
            $short_name = trim($_POST['short_name'] ?? '');
            $home_ground = trim($_POST['home_ground'] ?? '');

            if ($league_id <= 0 || $team_name === '') {
                $message = '<div class="alert alert-danger">Please select a league and enter a team name.</div>';
            } else {
                $league = null;
                $sql = "SELECT l.sport_id, l.team_limit, COUNT(t.team_id) AS team_count
                        FROM leagues l
                        LEFT JOIN teams t ON t.league_id = l.league_id
                        WHERE l.league_id = ?
                        GROUP BY l.league_id, l.sport_id, l.team_limit";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("i", $league_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $league = $result->fetch_assoc();
                    $stmt->close();
                }

                if (!$league) {
                    $message = '<div class="alert alert-danger">Selected league was not found.</div>';
                } elseif ((int) $league['team_count'] >= (int) $league['team_limit']) {
                    $message = '<div class="alert alert-danger">This league has reached its team limit.</div>';
                } else {
                    $sql = "INSERT INTO teams (league_id, sport_id, name, short_name, home_ground)
                            VALUES (?, ?, ?, ?, ?)";
                    if ($stmt = $conn->prepare($sql)) {
                        $sport_id = (int) $league['sport_id'];
                        $stmt->bind_param("iisss", $league_id, $sport_id, $team_name, $short_name, $home_ground);
                        if ($stmt->execute()) {
                            $message = '<div class="alert alert-success">Team added successfully.</div>';
                        } else {
                            $message = '<div class="alert alert-danger">Could not add team. It may already exist in this league.</div>';
                        }
                        $stmt->close();
                    }
                }
            }
        }

        if ($action === 'register_member') {
            $team_id = (int) ($_POST['team_id'] ?? 0);
            $member_id = (int) ($_POST['member_id'] ?? 0);
            $role = trim($_POST['role'] ?? 'Player');
            $allowed_roles = ['Player', 'Captain', 'Manager'];
            if (!in_array($role, $allowed_roles, true)) {
                $role = 'Player';
            }

            if ($team_id <= 0 || $member_id <= 0) {
                $message = '<div class="alert alert-danger">Please select both a member and a team.</div>';
            } else {
                $team = null;
                $sql = "SELECT t.team_id, t.league_id, l.max_players_per_team,
                               SUM(CASE WHEN tm.status = 'Active' THEN 1 ELSE 0 END) AS active_members
                        FROM teams t
                        JOIN leagues l ON l.league_id = t.league_id
                        LEFT JOIN team_memberships tm ON tm.team_id = t.team_id
                        WHERE t.team_id = ?
                        GROUP BY t.team_id, t.league_id, l.max_players_per_team";
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
                    $message = '<div class="alert alert-danger">This team roster is full.</div>';
                } else {
                    $existing_membership = null;
                    $sql = "SELECT membership_id FROM team_memberships WHERE league_id = ? AND member_id = ? LIMIT 1";
                    if ($stmt = $conn->prepare($sql)) {
                        $league_id = (int) $team['league_id'];
                        $stmt->bind_param("ii", $league_id, $member_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        $existing_membership = $result->fetch_assoc();
                        $stmt->close();
                    }

                    if ($existing_membership) {
                        $message = '<div class="alert alert-warning">This member is already registered in a team for that league.</div>';
                    } else {
                        $sql = "INSERT INTO team_memberships (league_id, team_id, member_id, role, status)
                                VALUES (?, ?, ?, ?, 'Active')";
                        if ($stmt = $conn->prepare($sql)) {
                            $league_id = (int) $team['league_id'];
                            $stmt->bind_param("iiis", $league_id, $team_id, $member_id, $role);
                            if ($stmt->execute()) {
                                $message = '<div class="alert alert-success">Member registered to team successfully.</div>';
                            } else {
                                $message = '<div class="alert alert-danger">Could not register member to the team.</div>';
                            }
                            $stmt->close();
                        }
                    }
                }
            }
        }

        if ($action === 'remove_registration') {
            $membership_id = (int) ($_POST['membership_id'] ?? 0);
            if ($membership_id <= 0) {
                $message = '<div class="alert alert-danger">Invalid registration selected.</div>';
            } else {
                $sql = "DELETE FROM team_memberships WHERE membership_id = ?";
                if ($stmt = $conn->prepare($sql)) {
                    $stmt->bind_param("i", $membership_id);
                    if ($stmt->execute()) {
                        $message = '<div class="alert alert-success">Team registration removed.</div>';
                    } else {
                        $message = '<div class="alert alert-danger">Could not remove registration.</div>';
                    }
                    $stmt->close();
                }
            }
        }
    }
}

$leagues = [];
$sql = "SELECT l.league_id, l.name, l.season, l.team_limit, l.team_format, l.max_players_per_team,
               l.status, s.name AS sport_name,
               COUNT(DISTINCT t.team_id) AS team_count,
               COUNT(DISTINCT tm.membership_id) AS member_count
        FROM leagues l
        JOIN sports s ON s.sport_id = l.sport_id
        LEFT JOIN teams t ON t.league_id = l.league_id
        LEFT JOIN team_memberships tm ON tm.league_id = l.league_id
        GROUP BY l.league_id, l.name, l.season, l.team_limit, l.team_format,
                 l.max_players_per_team, l.status, s.name
        ORDER BY s.name, l.name";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $leagues[] = $row;
    }
    $result->free();
}

$teams = [];
$sql = "SELECT t.team_id, t.name, t.short_name, t.home_ground, l.league_id, l.name AS league_name,
               l.team_limit, l.max_players_per_team, s.name AS sport_name,
               COUNT(tm.membership_id) AS player_count
        FROM teams t
        JOIN leagues l ON l.league_id = t.league_id
        JOIN sports s ON s.sport_id = t.sport_id
        LEFT JOIN team_memberships tm ON tm.team_id = t.team_id AND tm.status = 'Active'
        GROUP BY t.team_id, t.name, t.short_name, t.home_ground, l.league_id, l.name,
                 l.team_limit, l.max_players_per_team, s.name
        ORDER BY s.name, l.name, t.name";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $teams[] = $row;
    }
    $result->free();
}

$members = [];
$sql = "SELECT member_id, first_name, last_name, email FROM members ORDER BY first_name, last_name";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $result->free();
}

$registrations = [];
$sql = "SELECT tm.membership_id, tm.role, tm.status, tm.registered_at,
               m.first_name, m.last_name, m.email,
               t.name AS team_name, l.name AS league_name, s.name AS sport_name
        FROM team_memberships tm
        JOIN members m ON m.member_id = tm.member_id
        JOIN teams t ON t.team_id = tm.team_id
        JOIN leagues l ON l.league_id = tm.league_id
        JOIN sports s ON s.sport_id = l.sport_id
        ORDER BY tm.registered_at DESC";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $registrations[] = $row;
    }
    $result->free();
}

$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Manage Leagues and Teams</h2>
            </div>
            <div class="card-body">
                <?php echo $message; ?>

                <h4>League Structure</h4>
                <div class="table-responsive mb-4">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Sport</th>
                                <th>League</th>
                                <th>Season</th>
                                <th>Teams</th>
                                <th>Format</th>
                                <th>Roster Limit</th>
                                <th>Members</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($leagues) > 0): ?>
                                <?php foreach ($leagues as $league): ?>
                                    <tr>
                                        <td><?php echo e($league['sport_name']); ?></td>
                                        <td><?php echo e($league['name']); ?></td>
                                        <td><?php echo e($league['season']); ?></td>
                                        <td><?php echo e($league['team_count']); ?> / <?php echo e($league['team_limit']); ?></td>
                                        <td><?php echo e($league['team_format']); ?></td>
                                        <td><?php echo e($league['max_players_per_team']); ?></td>
                                        <td><?php echo e($league['member_count']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="7">No leagues found. Run <code>php scripts/migrate.php</code> to build and seed the league structure.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="row">
                    <div class="col-md-5 mb-4">
                        <h4>Add Team</h4>
                        <form action="<?php echo e($_SERVER["PHP_SELF"]); ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                            <input type="hidden" name="action" value="add_team">

                            <div class="mb-3">
                                <label for="league_id" class="form-label">League</label>
                                <select name="league_id" id="league_id" class="form-select" required>
                                    <option value="">Select league</option>
                                    <?php foreach ($leagues as $league): ?>
                                        <option value="<?php echo e($league['league_id']); ?>">
                                            <?php echo e($league['sport_name'] . ' - ' . $league['name'] . ' (' . $league['season'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="team_name" class="form-label">Team Name</label>
                                <input type="text" name="team_name" id="team_name" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label for="short_name" class="form-label">Short Name</label>
                                <input type="text" name="short_name" id="short_name" class="form-control" maxlength="30">
                            </div>

                            <div class="mb-3">
                                <label for="home_ground" class="form-label">Home Ground</label>
                                <input type="text" name="home_ground" id="home_ground" class="form-control">
                            </div>

                            <button type="submit" class="btn btn-primary">Add Team</button>
                        </form>
                    </div>

                    <div class="col-md-7 mb-4">
                        <h4>Register Member to Team</h4>
                        <form action="<?php echo e($_SERVER["PHP_SELF"]); ?>" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                            <input type="hidden" name="action" value="register_member">

                            <div class="mb-3">
                                <label for="member_id" class="form-label">Member</label>
                                <select name="member_id" id="member_id" class="form-select" required>
                                    <option value="">Select member</option>
                                    <?php foreach ($members as $member): ?>
                                        <option value="<?php echo e($member['member_id']); ?>">
                                            <?php echo e($member['first_name'] . ' ' . $member['last_name'] . ' - ' . $member['email']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="team_id" class="form-label">Team</label>
                                <select name="team_id" id="team_id" class="form-select" required>
                                    <option value="">Select team</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?php echo e($team['team_id']); ?>">
                                            <?php echo e($team['sport_name'] . ' - ' . $team['league_name'] . ' - ' . $team['name']); ?>
                                            (<?php echo e($team['player_count']); ?>/<?php echo e($team['max_players_per_team']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label for="role" class="form-label">Role</label>
                                <select name="role" id="role" class="form-select">
                                    <option value="Player">Player</option>
                                    <option value="Captain">Captain</option>
                                    <option value="Manager">Manager</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-success">Register Member</button>
                        </form>
                    </div>
                </div>

                <h4>Teams</h4>
                <div class="table-responsive mb-4">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Sport</th>
                                <th>League</th>
                                <th>Team</th>
                                <th>Short Name</th>
                                <th>Home Ground</th>
                                <th>Roster</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($teams) > 0): ?>
                                <?php foreach ($teams as $team): ?>
                                    <tr>
                                        <td><?php echo e($team['sport_name']); ?></td>
                                        <td><?php echo e($team['league_name']); ?></td>
                                        <td><?php echo e($team['name']); ?></td>
                                        <td><?php echo e($team['short_name']); ?></td>
                                        <td><?php echo e($team['home_ground']); ?></td>
                                        <td><?php echo e($team['player_count']); ?> / <?php echo e($team['max_players_per_team']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="6">No teams found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <h4>Team Registrations</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Member</th>
                                <th>Email</th>
                                <th>Sport</th>
                                <th>League</th>
                                <th>Team</th>
                                <th>Role</th>
                                <th>Registered</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($registrations) > 0): ?>
                                <?php foreach ($registrations as $registration): ?>
                                    <tr>
                                        <td><?php echo e($registration['first_name'] . ' ' . $registration['last_name']); ?></td>
                                        <td><?php echo e($registration['email']); ?></td>
                                        <td><?php echo e($registration['sport_name']); ?></td>
                                        <td><?php echo e($registration['league_name']); ?></td>
                                        <td><?php echo e($registration['team_name']); ?></td>
                                        <td><?php echo e($registration['role']); ?></td>
                                        <td><?php echo e(date('d M Y, H:i', strtotime($registration['registered_at']))); ?></td>
                                        <td>
                                            <form action="<?php echo e($_SERVER["PHP_SELF"]); ?>" method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf_token); ?>">
                                                <input type="hidden" name="action" value="remove_registration">
                                                <input type="hidden" name="membership_id" value="<?php echo e($registration['membership_id']); ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Remove this team registration?');">Remove</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8">No team registrations yet.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
