<?php
session_start();
require_once '../config/api_config.php';
include_once("../includes/header.php");
require_once "../config/db_connect.php";

$sports = [];
$sql = "SELECT sport_id, name, description FROM sports ORDER BY name";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) $sports[] = $row;
    $result->free();
}
$conn->close();

$sportConfig = [
    'Football'     => ['icon' => '⚽', 'color' => '#28a745', 'hasApi' => true],
    'Rugby'        => ['icon' => '🏉', 'color' => '#007bff', 'hasApi' => true],
    'Volleyball'   => ['icon' => '🏐', 'color' => '#ffc107', 'hasApi' => true],
    'Hockey'       => ['icon' => '🏑', 'color' => '#17a2b8', 'hasApi' => true],
    'Badminton'    => ['icon' => '🏸', 'color' => '#e83e8c', 'hasApi' => false],
    'Chess'        => ['icon' => '♟️', 'color' => '#6c757d', 'hasApi' => false],
    'Horse Riding' => ['icon' => '🐎', 'color' => '#fd7e14', 'hasApi' => false],
];
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="mb-0">
                    <i class="fas fa-futbol me-2"></i>Available Sports
                </h2>
                <span class="badge bg-success">
                    <i class="fas fa-satellite-dish me-1"></i>Live Data Available
                </span>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($sports as $sport):
                        $cfg = $sportConfig[$sport['name']] ?? ['icon' => '🏅', 'color' => '#007bff', 'hasApi' => false];
                    ?>
                    <div class="col-md-4 mb-4">
                        <div class="card h-100" style="border-top: 4px solid <?php echo $cfg['color']; ?>">
                            <div class="card-body">
                                <h5 class="card-title">
                                    <?php echo $cfg['icon']; ?>
                                    <?php echo htmlspecialchars($sport['name']); ?>
                                </h5>
                                <p class="card-text text-muted small">
                                    <?php echo htmlspecialchars($sport['description']); ?>
                                </p>

                                <?php if ($cfg['hasApi']): ?>
                                <!-- Live Data Buttons -->
                                <div class="btn-group btn-group-sm mb-2" role="group">
                                    <button class="btn btn-outline-primary"
                                            onclick="loadSportData('<?php echo $sport['name']; ?>', 'fixtures')">
                                        <i class="fas fa-calendar me-1"></i>Fixtures
                                    </button>
                                    <button class="btn btn-outline-danger"
                                            onclick="loadSportData('<?php echo $sport['name']; ?>', 'live')">
                                        <i class="fas fa-circle me-1"></i>Live
                                    </button>
                                    <?php if ($sport['name'] === 'Football'): ?>
                                    <button class="btn btn-outline-success"
                                            onclick="loadSportData('<?php echo $sport['name']; ?>', 'standings')">
                                        <i class="fas fa-trophy me-1"></i>Standings
                                    </button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>

                                <div class="mt-2">
                                    <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
                                        <a href="booking.php?sport_id=<?php echo $sport['sport_id']; ?>"
                                           class="btn btn-primary btn-sm">
                                           <i class="fas fa-calendar-plus me-1"></i>Book Now
                                        </a>
                                    <?php else: ?>
                                        <a href="login.php" class="btn btn-secondary btn-sm">
                                            Login to Book
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Live Data Results Panel -->
<div class="row mt-3" id="livePanel" style="display:none">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0" id="livePanelTitle">
                    <i class="fas fa-satellite-dish me-2"></i>Live Data
                </h5>
                <button class="btn btn-sm btn-outline-light"
                        onclick="document.getElementById('livePanel').style.display='none'">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
            <div class="card-body" id="livePanelContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Loading...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function loadSportData(sport, type) {
    var panel   = document.getElementById('livePanel');
    var content = document.getElementById('livePanelContent');
    var title   = document.getElementById('livePanelTitle');

    // Show panel with loading spinner
    panel.style.display = 'block';
    title.innerHTML     = '<i class="fas fa-satellite-dish me-2"></i>' + sport + ' — ' + type.charAt(0).toUpperCase() + type.slice(1);
    content.innerHTML   = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Fetching live data...</p></div>';
    panel.scrollIntoView({behavior: 'smooth'});

    // Call our server-side PHP file (keeps API key safe)
    fetch('get_sport_data.php?sport=' + encodeURIComponent(sport) + '&type=' + type)
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.error) {
            content.innerHTML = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>' + data.error + '</div>';
            return;
        }

        var items = data.response || [];

        if (items.length === 0) {
            content.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>No ' + type + ' available right now for ' + sport + '. Try again during match season!</div>';
            return;
        }

        // ── Render based on type ──────────────────────────────
        if (type === 'standings') {
            renderStandings(items, content);
        } else {
            renderFixtures(items, content, sport, type);
        }
    })
    .catch(function(err) {
        content.innerHTML = '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>Could not load data. Make sure your API key is set correctly.</div>';
    });
}

function renderFixtures(items, content, sport, type) {
    var html = '<div class="row">';
    items.slice(0, 9).forEach(function(item) {
        // Handle both football and other sports structure
        var home    = item.teams  ? item.teams.home.name   : (item.teams?.home?.name  || 'Home Team');
        var away    = item.teams  ? item.teams.away.name   : (item.teams?.away?.name  || 'Away Team');
        var homeLogo = item.teams?.home?.logo ? '<img src="' + item.teams.home.logo + '" width="20" class="me-1">' : '';
        var awayLogo = item.teams?.away?.logo ? '<img src="' + item.teams.away.logo + '" width="20" class="me-1">' : '';
        var scoreH  = item.goals  ? item.goals.home  : (item.scores?.home  ?? '-');
        var scoreA  = item.goals  ? item.goals.away  : (item.scores?.away  ?? '-');
        var league  = item.league ? item.league.name : (item.league?.name  || sport);
        var date    = item.fixture ? new Date(item.fixture.date).toLocaleDateString('en-KE', {day:'numeric', month:'short', year:'numeric'}) : '';
        var status  = item.fixture ? item.fixture.status.short : (item.status?.short || '');
        var elapsed = item.fixture?.status?.elapsed ? item.fixture.status.elapsed + "'" : '';
        var isLive  = ['1H','2H','HT','ET','BT','P'].includes(status);

        html += '<div class="col-md-4 mb-3">' +
            '<div class="card text-center h-100 ' + (isLive ? 'border-danger' : '') + '">' +
            '<div class="card-header py-1 small text-truncate">' +
            (isLive ? '<span class="badge bg-danger me-1">LIVE</span>' : '') +
            league + '</div>' +
            '<div class="card-body py-2">' +
            '<div class="d-flex justify-content-between align-items-center px-2">' +
            '<div class="text-center" style="width:40%">' + homeLogo + '<div class="small fw-bold">' + home + '</div></div>' +
            '<div class="text-center" style="width:20%">' +
            '<div class="fs-4 fw-bold ' + (isLive ? 'text-danger' : 'text-primary') + '">' + scoreH + ' – ' + scoreA + '</div>' +
            (elapsed ? '<div class="badge bg-danger">' + elapsed + '</div>' : '<div class="badge bg-secondary">' + status + '</div>') +
            '</div>' +
            '<div class="text-center" style="width:40%">' + awayLogo + '<div class="small fw-bold">' + away + '</div></div>' +
            '</div>' +
            (date ? '<div class="small text-muted mt-1">' + date + '</div>' : '') +
            '</div></div></div>';
    });
    html += '</div>';
    if (items.length > 9) {
        html += '<p class="text-muted small text-center">Showing 9 of ' + items.length + ' results</p>';
    }
    content.innerHTML = html;
}

function renderStandings(items, content) {
    var standings = items[0]?.league?.standings?.[0] || [];
    if (standings.length === 0) {
        content.innerHTML = '<div class="alert alert-info">No standings available.</div>';
        return;
    }
    var html = '<div class="table-responsive"><table class="table table-striped table-sm">' +
        '<thead class="table-dark"><tr>' +
        '<th>#</th><th>Team</th><th>P</th><th>W</th><th>D</th><th>L</th><th>GF</th><th>GA</th><th>GD</th><th>Pts</th>' +
        '</tr></thead><tbody>';
    standings.forEach(function(team) {
        var logo = team.team?.logo ? '<img src="' + team.team.logo + '" width="20" class="me-2">' : '';
        html += '<tr>' +
            '<td>' + team.rank + '</td>' +
            '<td>' + logo + team.team.name + '</td>' +
            '<td>' + team.all.played + '</td>' +
            '<td class="text-success">' + team.all.win + '</td>' +
            '<td>' + team.all.draw + '</td>' +
            '<td class="text-danger">' + team.all.lose + '</td>' +
            '<td>' + team.all.goals.for + '</td>' +
            '<td>' + team.all.goals.against + '</td>' +
            '<td>' + team.goalsDiff + '</td>' +
            '<td><strong>' + team.points + '</strong></td>' +
            '</tr>';
    });
    html += '</tbody></table></div>';
    content.innerHTML = html;
}
</script>

<?php include_once("../includes/footer.php"); ?>