<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/churn_wellness_analytics.php';

// Check if user is admin
if (!isset($_SESSION['member_id']) || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit;
}

$analytics = new ChurnWellnessAnalytics($conn);

// Get high-risk members
$high_risk = $analytics->getHighRiskMembers(20);

// Get injured members
$injured = $analytics->getInjuredMembers();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Churn & Wellness Analytics - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .analytics-container {
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

        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
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

        .member-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 20px;
            align-items: center;
        }

        .member-info h3 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .member-meta {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            font-size: 14px;
            color: #666;
        }

        .risk-meter {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .risk-bar {
            width: 150px;
            height: 20px;
            background: #f0f0f0;
            border-radius: 10px;
            overflow: hidden;
        }

        .risk-fill {
            height: 100%;
            background: linear-gradient(90deg, #4caf50, #ffc107, #f44336);
            width: 0%;
            transition: width 0.3s;
        }

        .risk-score {
            font-weight: bold;
            min-width: 50px;
            text-align: right;
        }

        .risk-level-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .risk-low {
            background: #d4edda;
            color: #155724;
        }

        .risk-medium {
            background: #fff3cd;
            color: #856404;
        }

        .risk-high {
            background: #f8d7da;
            color: #721c24;
        }

        .risk-critical {
            background: #721c24;
            color: white;
        }

        .member-actions {
            display: flex;
            gap: 10px;
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

        .injury-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
        }

        .injury-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .injury-header h3 {
            margin: 0;
            color: #333;
        }

        .injury-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-minor {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-major {
            background: #f8d7da;
            color: #721c24;
        }

        .status-recovery {
            background: #fff3cd;
            color: #856404;
        }

        .injury-details {
            font-size: 14px;
            color: #666;
            line-height: 1.6;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state p {
            font-size: 16px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-box {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
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
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="analytics-container">
        <div class="header">
            <h1>📊 Churn & Wellness Analytics</h1>
            <p>Predict member churn and track member wellness</p>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="switchTab('churn')">
                ⚠️ Churn Risk Analysis
            </button>
            <button class="tab" onclick="switchTab('wellness')">
                💪 Member Wellness
            </button>
        </div>

        <!-- Churn Risk Tab -->
        <div id="churn" class="tab-content active">
            <h2>High-Risk Members</h2>
            
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Total High-Risk</div>
                    <div class="stat-value"><?php echo count($high_risk); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Critical Risk</div>
                    <div class="stat-value">
                        <?php echo count(array_filter($high_risk, fn($m) => $m['risk_level'] == 'critical')); ?>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Avg Engagement</div>
                    <div class="stat-value">
                        <?php 
                            $avg_engagement = array_sum(array_column($high_risk, 'engagement_score')) / max(count($high_risk), 1);
                            echo number_format($avg_engagement, 1);
                        ?>
                    </div>
                </div>
            </div>

            <?php if (empty($high_risk)): ?>
                <div class="empty-state">
                    <p>No high-risk members detected.</p>
                    <p>Great job keeping members engaged!</p>
                </div>
            <?php else: ?>
                <?php foreach ($high_risk as $member): ?>
                    <div class="member-card">
                        <div class="member-info">
                            <h3><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                            <div class="member-meta">
                                <div>📧 <?php echo htmlspecialchars($member['email']); ?></div>
                                <div>📊 Engagement: <?php echo number_format($member['engagement_score'], 1); ?>/100</div>
                                <div>Status: <?php echo ucfirst($member['membership_status']); ?></div>
                            </div>
                        </div>

                        <div class="risk-meter">
                            <div class="risk-bar">
                                <div class="risk-fill" style="width: <?php echo min($member['risk_score'], 100); ?>%;"></div>
                            </div>
                            <div class="risk-score"><?php echo $member['risk_score']; ?>/100</div>
                        </div>

                        <div>
                            <span class="risk-level-badge risk-<?php echo strtolower($member['risk_level']); ?>">
                                <?php echo ucfirst($member['risk_level']); ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Wellness Tab -->
        <div id="wellness" class="tab-content">
            <h2>Injured Members</h2>
            
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Total Injured</div>
                    <div class="stat-value"><?php echo count($injured); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Major Injuries</div>
                    <div class="stat-value">
                        <?php echo count(array_filter($injured, fn($m) => $m['injury_status'] == 'major_injury')); ?>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">In Recovery</div>
                    <div class="stat-value">
                        <?php echo count(array_filter($injured, fn($m) => $m['injury_status'] == 'recovery')); ?>
                    </div>
                </div>
            </div>

            <?php if (empty($injured)): ?>
                <div class="empty-state">
                    <p>No injured members currently tracked.</p>
                    <p>All members are healthy!</p>
                </div>
            <?php else: ?>
                <?php foreach ($injured as $member): ?>
                    <div class="injury-card">
                        <div class="injury-header">
                            <h3><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h3>
                            <span class="injury-status status-<?php echo strtolower($member['injury_status']); ?>">
                                <?php echo ucfirst(str_replace('_', ' ', $member['injury_status'])); ?>
                            </span>
                        </div>
                        <div class="injury-details">
                            <p><strong>Last Updated:</strong> <?php echo date('M d, Y', strtotime($member['tracking_date'])); ?></p>
                            <?php if ($member['injury_notes']): ?>
                                <p><strong>Notes:</strong> <?php echo htmlspecialchars($member['injury_notes']); ?></p>
                            <?php endif; ?>
                            <?php if ($member['recommended_rest_until']): ?>
                                <p><strong>Recommended Rest Until:</strong> <?php echo date('M d, Y', strtotime($member['recommended_rest_until'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>

    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
