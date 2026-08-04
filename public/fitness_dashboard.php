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
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fitness Dashboard - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .fitness-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 40px;
        }

        .header h1 {
            margin: 0 0 10px 0;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .stat-label {
            font-size: 12px;
            color: #999;
            text-transform: uppercase;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
            margin-bottom: 5px;
        }

        .stat-unit {
            font-size: 14px;
            color: #666;
        }

        .section {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
        }

        .section h2 {
            margin-top: 0;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 15px;
        }

        .devices-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .device-card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
        }

        .device-card h3 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .device-info {
            font-size: 14px;
            color: #666;
            margin: 10px 0;
        }

        .device-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
            background: #d4edda;
            color: #155724;
            margin: 10px 0;
        }

        .device-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            flex: 1;
            text-align: center;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .leaderboard-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .leaderboard-table thead {
            background: #f0f0f0;
        }

        .leaderboard-table th,
        .leaderboard-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .leaderboard-table th {
            font-weight: bold;
            color: #333;
        }

        .rank-badge {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            font-weight: bold;
        }

        .rank-1 {
            background: #ffd700;
            color: #333;
        }

        .rank-2 {
            background: #c0c0c0;
            color: #333;
        }

        .rank-3 {
            background: #cd7f32;
        }

        .activity-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .activity-item {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .activity-info h4 {
            margin: 0 0 5px 0;
            color: #333;
        }

        .activity-info p {
            margin: 0;
            font-size: 12px;
            color: #666;
        }

        .activity-stats {
            text-align: right;
        }

        .activity-stat {
            font-size: 14px;
            font-weight: bold;
            color: #667eea;
            margin: 5px 0;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .empty-state p {
            font-size: 16px;
        }

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #ddd;
        }

        .tab {
            padding: 15px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }

        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="fitness-container">
        <div class="header">
            <h1>💪 Fitness Dashboard</h1>
            <p>Track your workouts, connect wearables, and compete on leaderboards</p>
        </div>

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Workouts (30 days)</div>
                <div class="stat-value"><?php echo $stats['total_activities'] ?? 0; ?></div>
                <div class="stat-unit">sessions</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Duration</div>
                <div class="stat-value"><?php echo number_format($stats['total_minutes'] ?? 0); ?></div>
                <div class="stat-unit">minutes</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Distance Covered</div>
                <div class="stat-value"><?php echo number_format($stats['total_distance'] ?? 0, 1); ?></div>
                <div class="stat-unit">km</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Calories Burned</div>
                <div class="stat-value"><?php echo number_format($stats['total_calories'] ?? 0); ?></div>
                <div class="stat-unit">kcal</div>
            </div>
        </div>

        <!-- Wearable Devices Section -->
        <div class="section">
            <h2>📱 Connected Devices</h2>
            
            <?php if (empty($devices)): ?>
                <div class="empty-state">
                    <p>No wearable devices connected yet.</p>
                    <p>Connect your Apple Health, Garmin, Fitbit, Strava, or WHOOP to sync your fitness data.</p>
                </div>
            <?php else: ?>
                <div class="devices-grid">
                    <?php foreach ($devices as $device): ?>
                        <div class="device-card">
                            <h3><?php echo ucfirst($device['device_type']); ?></h3>
                            <p class="device-info"><?php echo htmlspecialchars($device['device_name']); ?></p>
                            <div class="device-status">✓ Connected</div>
                            <p class="device-info">
                                Last sync: <?php echo $device['last_sync'] ? date('M d, Y H:i', strtotime($device['last_sync'])) : 'Never'; ?>
                            </p>
                            <div class="device-actions">
                                <button class="btn btn-secondary" onclick="syncDevice(<?php echo $device['device_id']; ?>)">
                                    Sync Now
                                </button>
                                <form method="POST" style="flex: 1;">
                                    <input type="hidden" name="device_id" value="<?php echo $device['device_id']; ?>">
                                    <button type="submit" name="disconnect_device" class="btn btn-danger" style="width: 100%;">
                                        Disconnect
                                    </button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <a href="connect_wearable.php" class="btn btn-primary" style="display: inline-block; margin-top: 20px;">
                + Connect Device
            </a>
        </div>

        <!-- Leaderboards Section -->
        <div class="section">
            <h2>🏆 Leaderboards</h2>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('weekly')">Weekly Workouts</button>
                <button class="tab" onclick="switchTab('monthly')">Monthly Calories</button>
            </div>

            <!-- Weekly Workouts -->
            <div id="weekly" class="tab-content active">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Member</th>
                            <th>Workouts</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($weekly_workouts as $idx => $member): ?>
                            <tr>
                                <td>
                                    <span class="rank-badge rank-<?php echo $member['rank']; ?>">
                                        <?php echo $member['rank']; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                                <td><?php echo intval($member['score']); ?> sessions</td>
                                <td>
                                    <?php echo $member['member_id'] == $member_id ? '👤 You' : ''; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Monthly Calories -->
            <div id="monthly" class="tab-content">
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Member</th>
                            <th>Calories</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthly_calories as $idx => $member): ?>
                            <tr>
                                <td>
                                    <span class="rank-badge rank-<?php echo $member['rank']; ?>">
                                        <?php echo $member['rank']; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                                <td><?php echo number_format(intval($member['score'])); ?> kcal</td>
                                <td>
                                    <?php echo $member['member_id'] == $member_id ? '👤 You' : ''; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Activities Section -->
        <div class="section">
            <h2>📊 Recent Activities</h2>
            
            <?php if (empty($activities)): ?>
                <div class="empty-state">
                    <p>No activities recorded yet.</p>
                    <p>Connect a wearable device to start tracking your fitness.</p>
                </div>
            <?php else: ?>
                <ul class="activity-list">
                    <?php foreach ($activities as $activity): ?>
                        <li class="activity-item">
                            <div class="activity-info">
                                <h4><?php echo ucfirst(str_replace('_', ' ', $activity['activity_type'])); ?></h4>
                                <p><?php echo date('M d, Y', strtotime($activity['activity_date'])); ?> • <?php echo htmlspecialchars($activity['device_name'] ?? 'Manual Entry'); ?></p>
                            </div>
                            <div class="activity-stats">
                                <?php if ($activity['duration_minutes']): ?>
                                    <div class="activity-stat">⏱️ <?php echo $activity['duration_minutes']; ?> min</div>
                                <?php endif; ?>
                                <?php if ($activity['distance_km']): ?>
                                    <div class="activity-stat">📍 <?php echo number_format($activity['distance_km'], 1); ?> km</div>
                                <?php endif; ?>
                                <?php if ($activity['calories_burned']): ?>
                                    <div class="activity-stat">🔥 <?php echo $activity['calories_burned']; ?> kcal</div>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div style="text-align: center; margin-top: 40px;">
            <a href="member_profile.php" class="btn btn-secondary">Back to Profile</a>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function switchTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
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
</body>
</html>
