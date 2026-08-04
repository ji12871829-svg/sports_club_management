<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/green_goal_tracker.php';

// Check if user is logged in
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$green = new GreenGoalTracker($conn);
$member_id = $_SESSION['member_id'];

// Get member's eco activities
$eco_activities = $green->getMemberEcoActivities($member_id, 90);

// Get member's CO2 saved
$co2_stats = $green->getMemberCO2Saved($member_id, 365);

// Get eco leaderboard
$eco_leaderboard = $green->getEcoLeaderboard(15);

// Get club sustainability report
$club_report = $green->getClubSustainabilityReport(12);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Green Goals - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .green-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #4caf50 0%, #2e7d32 100%);
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
            color: #4caf50;
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
            border-bottom: 2px solid #4caf50;
            padding-bottom: 15px;
        }

        .activity-form {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #333;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #4caf50;
            box-shadow: 0 0 5px rgba(76, 175, 80, 0.3);
        }

        .btn {
            padding: 10px 15px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: #4caf50;
            color: white;
        }

        .btn-primary:hover {
            background: #45a049;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
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

        .activity-impact {
            text-align: right;
        }

        .impact-stat {
            font-size: 14px;
            font-weight: bold;
            color: #4caf50;
            margin: 5px 0;
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
            background: #4caf50;
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

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .empty-state p {
            font-size: 16px;
        }

        .impact-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
            background: #d4edda;
            color: #155724;
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
            color: #4caf50;
            border-bottom-color: #4caf50;
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

    <div class="green-container">
        <div class="header">
            <h1>🌱 Green Goals Tracker</h1>
            <p>Track your eco-friendly activities and help the club achieve sustainability goals</p>
        </div>

        <!-- Stats Overview -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">CO2 Saved (1 Year)</div>
                <div class="stat-value"><?php echo number_format($co2_stats['total_co2'] ?? 0, 1); ?></div>
                <div class="stat-unit">kg</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Eco Activities</div>
                <div class="stat-value"><?php echo $co2_stats['activity_count'] ?? 0; ?></div>
                <div class="stat-unit">logged</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Eco Credits</div>
                <div class="stat-value"><?php echo $co2_stats['total_credits'] ?? 0; ?></div>
                <div class="stat-unit">earned</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Trees Equivalent</div>
                <div class="stat-value"><?php echo number_format(($co2_stats['total_co2'] ?? 0) / 20); ?></div>
                <div class="stat-unit">trees</div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="switchTab('log')">Log Activity</button>
            <button class="tab" onclick="switchTab('history')">My Activities</button>
            <button class="tab" onclick="switchTab('leaderboard')">Leaderboard</button>
            <button class="tab" onclick="switchTab('club')">Club Report</button>
        </div>

        <!-- Log Activity Tab -->
        <div id="log" class="tab-content active">
            <div class="section">
                <h2>📝 Log an Eco-Friendly Activity</h2>
                
                <form method="POST" class="activity-form">
                    <div class="form-group">
                        <label for="activity_type">Activity Type *</label>
                        <select name="activity_type" id="activity_type" required>
                            <option value="">Select an activity...</option>
                            <option value="carpool">🚗 Carpooled to the club</option>
                            <option value="public_transport">🚌 Used public transport</option>
                            <option value="bike">🚴 Cycled or walked</option>
                            <option value="reusable_bottle">♻️ Used reusable bottle</option>
                            <option value="waste_reduction">🗑️ Reduced waste</option>
                            <option value="tree_planting">🌳 Planted a tree</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="activity_date">Date *</label>
                        <input type="date" name="activity_date" id="activity_date" required value="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea name="description" id="description" placeholder="Add any details about your eco-friendly activity..."></textarea>
                    </div>

                    <button type="submit" name="log_activity" class="btn btn-primary">
                        ✓ Log Activity
                    </button>
                </form>

                <div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin-top: 20px;">
                    <p><strong>💡 Tip:</strong> Each eco-friendly activity earns you eco-credits that convert to loyalty points!</p>
                </div>
            </div>
        </div>

        <!-- My Activities Tab -->
        <div id="history" class="tab-content">
            <div class="section">
                <h2>📊 Your Recent Activities</h2>
                
                <?php if (empty($eco_activities)): ?>
                    <div class="empty-state">
                        <p>No eco activities logged yet.</p>
                        <p>Start logging your green activities to earn credits!</p>
                    </div>
                <?php else: ?>
                    <ul class="activity-list">
                        <?php foreach ($eco_activities as $activity): ?>
                            <li class="activity-item">
                                <div class="activity-info">
                                    <h4><?php echo ucfirst(str_replace('_', ' ', $activity['activity_type'])); ?></h4>
                                    <p><?php echo date('M d, Y', strtotime($activity['activity_date'])); ?></p>
                                    <?php if ($activity['description']): ?>
                                        <p><?php echo htmlspecialchars($activity['description']); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="activity-impact">
                                    <div class="impact-stat">🌍 <?php echo number_format($activity['co2_saved_kg'], 2); ?> kg CO2</div>
                                    <div class="impact-stat">⭐ <?php echo $activity['eco_credits_earned']; ?> credits</div>
                                    <span class="impact-badge">
                                        <?php echo $activity['verified'] ? '✓ Verified' : '⏳ Pending'; ?>
                                    </span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Leaderboard Tab -->
        <div id="leaderboard" class="tab-content">
            <div class="section">
                <h2>🏆 Eco Champions Leaderboard</h2>
                
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>Member</th>
                            <th>CO2 Saved</th>
                            <th>Eco Credits</th>
                            <th>Activities</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($eco_leaderboard as $idx => $member): 
                        ?>
                            <tr>
                                <td>
                                    <span class="rank-badge rank-<?php echo $rank; ?>">
                                        <?php echo $rank; ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></td>
                                <td><?php echo number_format($member['total_co2_saved'] ?? 0, 1); ?> kg</td>
                                <td><?php echo $member['total_eco_credits'] ?? 0; ?></td>
                                <td><?php echo $member['activity_count'] ?? 0; ?></td>
                            </tr>
                        <?php 
                        $rank++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Club Report Tab -->
        <div id="club" class="tab-content">
            <div class="section">
                <h2>📈 Club Sustainability Report</h2>
                
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th>Total CO2</th>
                            <th>Energy (kWh)</th>
                            <th>Water (L)</th>
                            <th>Offset</th>
                            <th>Net Carbon</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($club_report as $report): ?>
                            <tr>
                                <td><?php echo date('M Y', strtotime($report['month_year'])); ?></td>
                                <td><?php echo number_format($report['total_co2_kg'] ?? 0, 1); ?> kg</td>
                                <td><?php echo number_format($report['energy_usage_kwh'] ?? 0); ?></td>
                                <td><?php echo number_format($report['water_usage_liters'] ?? 0); ?></td>
                                <td><?php echo number_format($report['offset_by_eco_activities'] ?? 0, 1); ?> kg</td>
                                <td style="color: <?php echo ($report['net_carbon_kg'] ?? 0) < 0 ? '#4caf50' : '#f44336'; ?>; font-weight: bold;">
                                    <?php echo number_format($report['net_carbon_kg'] ?? 0, 1); ?> kg
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
    </script>
</body>
</html>
