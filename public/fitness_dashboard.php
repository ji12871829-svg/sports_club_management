<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/wearable_integration.php';

// Check if user is logged in
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$wearable = new WearableIntegration($conn);
$member_id = $_SESSION['member_id'];

// Get member devices
$devices = $wearable->getMemberDevices($member_id);

// Get fitness stats
$stats = $wearable->getFitnessStats($member_id, 30);

// Get recent activities
$activities = $wearable->getMemberActivities($member_id, 30);

// Get leaderboards
$weekly_workouts = $wearable->getLeaderboard('weekly_workouts', 10);
$monthly_calories = $wearable->getLeaderboard('monthly_calories', 10);

?>

<?php include __DIR__ . '/../includes/header.php'; ?>

<style>
    /* Fitness page local styles (tabs + rank badges) */
    .md-tabs {
        display: flex;
        gap: 0.25rem;
        border-bottom: 1px solid #e5e7eb;
        margin-bottom: 1.25rem;
    }
    .md-tab {
        background: none;
        border: none;
        border-bottom: 3px solid transparent;
        padding: 0.7rem 1.1rem;
        font-weight: 700;
        font-size: 0.9rem;
        color: #6b7280;
        cursor: pointer;
        transition: color 0.15s ease, border-color 0.15s ease;
    }
    .md-tab:hover { color: #111827; }
    .md-tab.active { color: #4f46e5; border-bottom-color: #4f46e5; }
    .md-tab-content { display: none; }
    .md-tab-content.active { display: block; }

    .rank-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 30px; height: 30px;
        border-radius: 50%;
        background: #eef2ff; color: #4f46e5;
        font-weight: 800; font-size: 0.82rem;
    }
    .rank-1 { background: #fef9c3; color: #854d0e; }
    .rank-2 { background: #f3f4f6; color: #374151; }
    .rank-3 { background: #ffedd5; color: #9a3412; }

    .md-device {
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 1.1rem 1.25rem;
        height: 100%;
    }
    .md-device h5 { font-weight: 700; color: #111827; margin-bottom: 0.15rem; }
    .md-device .device-info { font-size: 0.85rem; color: #6b7280; margin: 0.2rem 0; }

    .md-activity-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #f1f3f5;
        transition: background 0.15s ease;
    }
    .md-activity-row:last-child { border-bottom: none; }
    .md-activity-row:hover { background: #fafbfc; }
    .md-activity-row h5 { font-size: 0.92rem; font-weight: 700; color: #111827; margin-bottom: 0.1rem; }
    .md-activity-row p { font-size: 0.8rem; color: #6b7280; margin: 0; }
    .md-activity-stats { display: flex; gap: 1rem; flex-shrink: 0; }
    .md-activity-stat { font-size: 0.8rem; font-weight: 700; color: #4f46e5; }
</style>

<div class="py-4">
    <!-- Welcome banner -->
    <div class="md-banner mb-4">
        <h1><i class="fas fa-heart-pulse me-2"></i>Fitness Dashboard</h1>
        <div class="md-banner-sub">Track your workouts, connect wearables, and compete on club leaderboards</div>
    </div>

    <!-- Stats overview -->
    <div class="row g-4 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="md-stat">
                <div class="md-stat-icon md-stat-icon-blue"><i class="fas fa-dumbbell"></i></div>
                <div>
                    <div class="md-stat-label">Workouts (30 days)</div>
                    <div class="md-stat-value"><?php echo (int) ($stats['total_activities'] ?? 0); ?></div>
                    <div class="md-stat-sub">sessions logged</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="md-stat">
                <div class="md-stat-icon md-stat-icon-amber"><i class="fas fa-clock"></i></div>
                <div>
                    <div class="md-stat-label">Total Duration</div>
                    <div class="md-stat-value"><?php echo number_format((float) ($stats['total_minutes'] ?? 0)); ?></div>
                    <div class="md-stat-sub">minutes</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="md-stat">
                <div class="md-stat-icon md-stat-icon-green"><i class="fas fa-map-location-dot"></i></div>
                <div>
                    <div class="md-stat-label">Distance Covered</div>
                    <div class="md-stat-value"><?php echo number_format((float) ($stats['total_distance'] ?? 0), 1); ?></div>
                    <div class="md-stat-sub">km</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="md-stat">
                <div class="md-stat-icon md-stat-icon-amber"><i class="fas fa-fire"></i></div>
                <div>
                    <div class="md-stat-label">Calories Burned</div>
                    <div class="md-stat-value"><?php echo number_format((float) ($stats['total_calories'] ?? 0)); ?></div>
                    <div class="md-stat-sub">kcal</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <!-- Connected devices -->
            <div class="md-card mb-4">
                <div class="md-card-head">
                    <div>
                        <h4 class="md-card-title"><i class="fas fa-mobile-screen"></i>Connected Devices</h4>
                        <small class="text-muted"><?php echo count($devices); ?> wearable<?php echo count($devices) === 1 ? '' : 's'; ?> linked to your profile</small>
                    </div>
                    <a href="connect_wearable.php" class="md-btn md-btn-dark"><i class="fas fa-plus"></i> Connect Device</a>
                </div>
                <?php if (empty($devices)): ?>
                    <div class="md-empty">
                        <i class="fas fa-link-slash"></i>
                        No wearable devices connected yet.<br>
                        <small>Connect Apple Health, Garmin, Fitbit, Strava, or WHOOP to sync your fitness data.</small>
                    </div>
                <?php else: ?>
                    <div class="md-card-body">
                        <div class="row g-3">
                            <?php foreach ($devices as $device): ?>
                                <div class="col-md-6">
                                    <div class="md-device">
                                        <div class="d-flex align-items-center justify-content-between">
                                            <h5><i class="fas fa-watch me-2"></i><?php echo ucfirst($device['device_type']); ?></h5>
                                            <span class="md-pill md-pill-green"><i class="fas fa-check me-1"></i>Connected</span>
                                        </div>
                                        <p class="device-info"><?php echo htmlspecialchars($device['device_name']); ?></p>
                                        <p class="device-info">
                                            Last sync: <?php echo $device['last_sync'] ? date('M d, Y H:i', strtotime($device['last_sync'])) : 'Never'; ?>
                                        </p>
                                        <div class="d-flex gap-2 mt-2">
                                            <button class="btn btn-sm btn-outline-primary" onclick="syncDevice(<?php echo (int) $device['device_id']; ?>)">
                                                Sync Now
                                            </button>
                                            <form method="POST" class="flex-grow-1">
                                                <input type="hidden" name="device_id" value="<?php echo (int) $device['device_id']; ?>">
                                                <button type="submit" name="disconnect_device" class="btn btn-sm btn-outline-danger w-100">
                                                    Disconnect
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Leaderboards -->
            <div class="md-card mb-4">
                <div class="md-card-head">
                    <div>
                        <h4 class="md-card-title"><i class="fas fa-trophy"></i>Leaderboards</h4>
                        <small class="text-muted">Club-wide rankings</small>
                    </div>
                </div>
                <div class="md-card-body">
                    <div class="md-tabs">
                        <button class="md-tab active" onclick="switchTab('weekly')">Weekly Workouts</button>
                        <button class="md-tab" onclick="switchTab('monthly')">Monthly Calories</button>
                    </div>

                    <div id="weekly" class="md-tab-content active">
                        <div class="table-responsive">
                            <table class="table md-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Member</th>
                                        <th>Workouts</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($weekly_workouts as $member): ?>
                                        <tr>
                                            <td><span class="rank-badge rank-<?php echo (int) $member['rank']; ?>"><?php echo (int) $member['rank']; ?></span></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                                            <td><?php echo (int) $member['score']; ?> sessions</td>
                                            <td><?php echo (int) $member['member_id'] === (int) $member_id ? '<span class="md-pill md-pill-blue">You</span>' : ''; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div id="monthly" class="md-tab-content">
                        <div class="table-responsive">
                            <table class="table md-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Rank</th>
                                        <th>Member</th>
                                        <th>Calories</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($monthly_calories as $member): ?>
                                        <tr>
                                            <td><span class="rank-badge rank-<?php echo (int) $member['rank']; ?>"><?php echo (int) $member['rank']; ?></span></td>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                                            <td><?php echo number_format((float) $member['score']); ?> kcal</td>
                                            <td><?php echo (int) $member['member_id'] === (int) $member_id ? '<span class="md-pill md-pill-blue">You</span>' : ''; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent activities -->
            <div class="md-card">
                <div class="md-card-head">
                    <div>
                        <h4 class="md-card-title"><i class="fas fa-chart-line"></i>Recent Activities</h4>
                        <small class="text-muted">Your latest workout log entries</small>
                    </div>
                </div>
                <?php if (empty($activities)): ?>
                    <div class="md-empty">
                        <i class="fas fa-person-running"></i>
                        No activities recorded yet.<br>
                        <small>Connect a wearable device to start tracking your fitness.</small>
                    </div>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                        <div class="md-activity-row">
                            <div>
                                <h5><?php echo ucfirst(str_replace('_', ' ', $activity['activity_type'])); ?></h5>
                                <p><?php echo date('M d, Y', strtotime($activity['activity_date'])); ?> &bull; <?php echo htmlspecialchars($activity['device_name'] ?? 'Manual Entry'); ?></p>
                            </div>
                            <div class="md-activity-stats">
                                <?php if (!empty($activity['duration_minutes'])): ?>
                                    <span class="md-activity-stat">&#9201; <?php echo (int) $activity['duration_minutes']; ?> min</span>
                                <?php endif; ?>
                                <?php if (!empty($activity['distance_km'])): ?>
                                    <span class="md-activity-stat">&#128205; <?php echo number_format((float) $activity['distance_km'], 1); ?> km</span>
                                <?php endif; ?>
                                <?php if (!empty($activity['calories_burned'])): ?>
                                    <span class="md-activity-stat">&#128293; <?php echo (int) $activity['calories_burned']; ?> kcal</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <?php include __DIR__ . '/../includes/member_quick_actions.php'; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
    function switchTab(tabName) {
        document.querySelectorAll('.md-tab-content').forEach(function (tab) {
            tab.classList.remove('active');
        });
        document.querySelectorAll('.md-tab').forEach(function (tab) {
            tab.classList.remove('active');
        });
        document.getElementById(tabName).classList.add('active');
        event.target.classList.add('active');
    }

    function syncDevice(deviceId) {
        alert('Syncing device ' + deviceId + '...');
        // In production, this would call an AJAX endpoint
    }
</script>
