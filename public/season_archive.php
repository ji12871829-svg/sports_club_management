<?php
/**
 * public/season_archive.php
 * Browse past seasons — standings, results, top scorers.
 */
include_once '../includes/header.php';
require_once '../config/db_connect.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$season_id = (int)($_GET['season_id'] ?? 0);
$league_id = (int)($_GET['league_id'] ?? 0);

// 1. Fetch all seasons safely
$seasons = $conn->query("
    SELECT s.*, COUNT(DISTINCT l.league_id) AS league_count
    FROM seasons s
    LEFT JOIN leagues l ON l.season_id = s.season_id
    GROUP BY s.season_id
    ORDER BY s.start_date DESC
")->fetch_all(MYSQLI_ASSOC);

$use_league_fallback = empty($seasons);

if ($use_league_fallback) {
    // Fallback if no chronological seasons exist
    $all_leagues = $conn->query("
        SELECT l.league_id, l.name, l.sport_id,
               COUNT(DISTINCT f.fixture_id) AS fixture_count,
               COUNT(DISTINCT CASE WHEN f.status='Completed' THEN f.fixture_id END) AS completed,
               MIN(f.match_date) AS first_match,
               MAX(f.match_date) AS last_match
        FROM leagues l
        LEFT JOIN fixtures f ON f.league_id = l.league_id
        GROUP BY l.league_id
        ORDER BY last_match DESC
    ")->fetch_all(MYSQLI_ASSOC);
} else {
    // Establish default season context if none provided
    if ($season_id === 0 && !empty($seasons)) {
        $season_id = (int)$seasons[0]['season_id'];
    }
    
    // 2. Prepared Statement to safely fetch leagues within current season
    $stmt = $conn->prepare("
        SELECT l.league_id, l.name,
               COUNT(DISTINCT f.fixture_id) AS fixture_count,
               COUNT(DISTINCT CASE WHEN f.status='Completed' THEN f.fixture_id END) AS completed
        FROM leagues l
        LEFT JOIN fixtures f ON f.league_id = l.league_id
        WHERE l.season_id = ?
        GROUP BY l.league_id
        ORDER BY l.name ASC
    ");
    $stmt->bind_param('i', $season_id);
    $stmt->execute();
    $season_leagues = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// Data containers for selected league
$standings = $results_list = $top_scorers = [];

if ($league_id > 0) {
    // 3. Prepared Statement: Standings
    $stmt = $conn->prepare("
        SELECT s.*, t.name AS team_name
        FROM standings s
        JOIN teams t ON t.team_id = s.team_id
        WHERE s.league_id = ?
        ORDER BY s.points DESC, s.goal_diff DESC
    ");
    $stmt->bind_param('i', $league_id);
    $stmt->execute();
    $standings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 4. Prepared Statement: Results List
    $stmt = $conn->prepare("
        SELECT f.fixture_id, f.match_date, f.home_score, f.away_score, f.matchday,
               h.name AS home_team, a.name AS away_team
        FROM fixtures f
        JOIN teams h ON h.team_id = f.home_team_id
        JOIN teams a ON a.team_id = f.away_team_id
        WHERE f.league_id = ? AND f.status = 'Completed'
        ORDER BY f.match_date DESC
        LIMIT 30
    ");
    $stmt->bind_param('i', $league_id);
    $stmt->execute();
    $results_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // 5. Prepared Statement: Top Scorers
    $stmt = $conn->prepare("
        SELECT COALESCE(CONCAT(m.first_name,' ',m.last_name), me.player_name) AS player_name,
               t.name AS team_name,
               COUNT(*) AS goals
        FROM match_events me
        JOIN fixtures f ON f.fixture_id = me.fixture_id
        JOIN teams t ON t.team_id = me.team_id
        LEFT JOIN members m ON m.member_id = me.member_id
        WHERE f.league_id = ? AND me.event_type IN ('goal','penalty')
        GROUP BY me.member_id, me.player_name, t.name
        ORDER BY goals DESC
        LIMIT 10
    ");
    $stmt->bind_param('i', $league_id);
    $stmt->execute();
    $top_scorers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

$conn->close();
?>

<div class="container py-5">
    <h2 class="fw-bold mb-1">🗂️ Season Archive</h2>
    <p class="text-muted mb-4">Browse past leagues, standings, and results</p>

    <div class="row g-4">
        <!-- Left: Sidebar controls -->
        <div class="col-md-3">
            <?php if ($use_league_fallback): ?>
                <h6 class="fw-bold text-muted text-uppercase small mb-3">All Leagues</h6>
                <div class="list-group">
                    <?php foreach ($all_leagues as $l): ?>
                        <a href="?league_id=<?php echo e($l['league_id']); ?>"
                           class="list-group-item list-group-item-action <?php echo $league_id === (int)$l['league_id'] ? 'active' : ''; ?>">
                            <div class="fw-semibold"><?php echo e($l['name']); ?></div>
                            <small class="text-muted"><?php echo e($l['completed']); ?>/<?php echo e($l['fixture_count']); ?> matches played</small>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <h6 class="fw-bold text-muted text-uppercase small mb-3">Seasons</h6>
                <div class="list-group mb-4">
                    <?php foreach ($seasons as $s): ?>
                        <a href="?season_id=<?php echo e($s['season_id']); ?>"
                           class="list-group-item list-group-item-action <?php echo $season_id === (int)$s['season_id'] ? 'active' : ''; ?>">
                            <div class="fw-semibold"><?php echo e($s['name']); ?></div>
                            <small class="text-muted"><?php echo e($s['league_count']); ?> league(s)</small>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($season_leagues)): ?>
                    <h6 class="fw-bold text-muted text-uppercase small mb-3">Leagues in Selected Season</h6>
                    <div class="list-group">
                        <?php foreach ($season_leagues as $l): ?>
                            <!-- Fixed: Appends season_id properly to protect state active links -->
                            <a href="?season_id=<?php echo e($season_id); ?>&league_id=<?php echo e($l['league_id']); ?>"
                               class="list-group-item list-group-item-action <?php echo $league_id === (int)$l['league_id'] ? 'active' : ''; ?>">
                                <div class="fw-medium"><?php echo e($l['name']); ?></div>
                                <small class="text-muted d-block"><?php echo e($l['completed']); ?> / <?php echo e($l['fixture_count']); ?> results</small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Right: Data boards -->
        <div class="col-md-9">
            <?php if ($league_id === 0): ?>
                <div class="text-center py-5 text-muted border rounded-3 bg-light">
                    <i class="fas fa-arrow-left fa-2x mb-3 d-block text-secondary"></i>
                    Select a league from the sidebar to view historical archives.
                </div>
            <?php else: ?>
                <!-- Standings Table -->
                <?php if (!empty($standings)): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold py-3 border-bottom">
                        <i class="fas fa-list-ol me-2 text-primary"></i>Final Standings
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light text-uppercase fs-7">
                                    <tr>
                                        <th class="ps-3">#</th><th>Team</th><th>P</th><th>W</th>
                                        <th>D</th><th>L</th><th>GD</th><th class="pe-3">Pts</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($standings as $i => $s): ?>
                                    <tr>
                                        <td class="ps-3 text-muted"><?php echo $i+1; ?></td>
                                        <td class="fw-semibold text-dark"><?php echo e($s['team_name']); ?></td>
                                        <td><?php echo e($s['played']); ?></td>
                                        <td><?php echo e($s['won']); ?></td>
                                        <td><?php echo e($s['drawn']); ?></td>
                                        <td><?php echo e($s['lost']); ?></td>
                                        <td class="text-muted"><?php echo e($s['goal_diff'] > 0 ? '+'.$s['goal_diff'] : $s['goal_diff']); ?></td>
                                        <td class="pe-3"><strong><?php echo e($s['points']); ?></strong></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Top Scorers Table -->
                <?php if (!empty($top_scorers)): ?>
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white fw-bold py-3 border-bottom">
                        <i class="fas fa-futbol me-2 text-success"></i>Top Scorers
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light text-uppercase fs-7">
                                <tr><th class="ps-3">#</th><th>Player</th><th>Team</th><th class="pe-3">Goals</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_scorers as $i => $ts): ?>
                                <tr>
                                    <td class="ps-3 text-muted"><?php echo $i+1; ?></td>
                                    <td class="fw-semibold"><?php echo e($ts['player_name']); ?></td>
                                    <td><span class="badge bg-light text-secondary border"><?php echo e($ts['team_name']); ?></span></td>
                                    <td class="pe-3"><strong class="text-success"><?php echo e($ts['goals']); ?></strong></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Results Table -->
                <?php if (!empty($results_list)): ?>
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-bold py-3 border-bottom">
                        <i class="fas fa-check-circle me-2 text-info"></i>Fixture Results
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead class="table-light text-uppercase fs-7">
                                    <tr><th class="ps-3">Date</th><th>Home Team</th><th class="text-center">Score</th><th>Away Team</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($results_list as $r): ?>
                                    <tr>
                                        <td class="ps-3"><small class="text-muted fw-medium"><?php echo e(date('d M Y', strtotime($r['match_date']))); ?></small></td>
                                        <td class="fw-semibold text-end text-truncate" style="max-width: 180px;"><?php echo e($r['home_team']); ?></td>
                                        <td class="text-center">
                                            <span class="px-3 py-1 bg-dark text-white rounded font-monospace small fw-bold">
                                                <?php echo e($r['home_score'].' – '.$r['away_score']); ?>
                                            </span>
                                        </td>
                                        <td class="text-start text-truncate" style="max-width: 180px;"><?php echo e($r['away_team']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>