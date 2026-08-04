<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/highlight_reels_engine.php';

// Check if user is admin
if (!isset($_SESSION['member_id']) || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit;
}

$reels = new HighlightReelsEngine($conn);

// Get top performing reels
$top_reels = $reels->getTopReels(10);

// Get pending footage
$pending_footage = $reels->getPendingFootage(10);

// Get social media dashboard
$social_dashboard = $reels->getSocialMediaDashboard();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Highlight Reels - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .reels-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
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
            color: #ff6b6b;
            border-bottom-color: #ff6b6b;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
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
            border-bottom: 2px solid #ff6b6b;
            padding-bottom: 15px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: #f9f9f9;
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
            font-size: 28px;
            font-weight: bold;
            color: #ff6b6b;
        }

        .stat-unit {
            font-size: 12px;
            color: #666;
        }

        .reel-card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 20px;
            align-items: center;
        }

        .reel-info h3 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .reel-meta {
            font-size: 12px;
            color: #666;
            margin: 5px 0;
        }

        .reel-type-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            background: #ffe7e7;
            color: #ff6b6b;
            margin: 10px 0;
        }

        .reel-stats {
            display: flex;
            gap: 20px;
        }

        .reel-stat {
            text-align: center;
        }

        .reel-stat-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
        }

        .reel-stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #ff6b6b;
        }

        .reel-actions {
            display: flex;
            gap: 10px;
            flex-direction: column;
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
            background: #ff6b6b;
            color: white;
        }

        .btn-primary:hover {
            background: #ee5a6f;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .platform-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .platform-table thead {
            background: #f0f0f0;
        }

        .platform-table th,
        .platform-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .platform-table th {
            font-weight: bold;
            color: #333;
        }

        .platform-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .platform-tiktok {
            background: #000;
            color: white;
        }

        .platform-instagram {
            background: #E4405F;
            color: white;
        }

        .platform-youtube {
            background: #FF0000;
            color: white;
        }

        .platform-facebook {
            background: #1877F2;
            color: white;
        }

        .platform-twitter {
            background: #1DA1F2;
            color: white;
        }

        .footage-card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .footage-info h4 {
            margin: 0 0 5px 0;
            color: #333;
        }

        .footage-info p {
            margin: 0;
            font-size: 12px;
            color: #666;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-processing {
            background: #d1ecf1;
            color: #0c5460;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .empty-state p {
            font-size: 16px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="reels-container">
        <div class="header">
            <h1>🎬 Automated Highlight Reels</h1>
            <p>AI-powered match highlights for social media</p>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="switchTab('top')">Top Reels</button>
            <button class="tab" onclick="switchTab('pending')">Pending Footage</button>
            <button class="tab" onclick="switchTab('platforms')">Social Media</button>
        </div>

        <!-- Top Reels Tab -->
        <div id="top" class="tab-content active">
            <div class="section">
                <h2>🏆 Top Performing Reels</h2>
                
                <?php if (empty($top_reels)): ?>
                    <div class="empty-state">
                        <p>No reels generated yet.</p>
                        <p>Upload match footage to generate AI-powered highlights.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($top_reels as $reel): ?>
                        <div class="reel-card">
                            <div class="reel-info">
                                <h3><?php echo htmlspecialchars($reel['title']); ?></h3>
                                <p class="reel-meta">
                                    📅 <?php echo date('M d, Y', strtotime($reel['published_at'])); ?> • 
                                    <?php echo htmlspecialchars($reel['home_team']); ?> vs <?php echo htmlspecialchars($reel['away_team']); ?>
                                </p>
                                <span class="reel-type-badge"><?php echo str_replace('_', ' ', $reel['reel_type']); ?></span>
                                <p class="reel-meta">
                                    AI Confidence: <?php echo round($reel['generation_confidence'] * 100); ?>%
                                </p>
                            </div>

                            <div class="reel-stats">
                                <div class="reel-stat">
                                    <div class="reel-stat-label">Engagement</div>
                                    <div class="reel-stat-value"><?php echo number_format($reel['total_engagement'] ?? 0); ?></div>
                                </div>
                                <div class="reel-stat">
                                    <div class="reel-stat-label">Reach</div>
                                    <div class="reel-stat-value"><?php echo number_format($reel['total_reach'] ?? 0); ?></div>
                                </div>
                                <div class="reel-stat">
                                    <div class="reel-stat-label">Duration</div>
                                    <div class="reel-stat-value"><?php echo $reel['duration_seconds']; ?>s</div>
                                </div>
                            </div>

                            <div class="reel-actions">
                                <a href="<?php echo htmlspecialchars($reel['video_url']); ?>" target="_blank" class="btn btn-primary">
                                    Watch
                                </a>
                                <button class="btn btn-secondary">Share</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending Footage Tab -->
        <div id="pending" class="tab-content">
            <div class="section">
                <h2>📹 Pending Footage Processing</h2>
                
                <?php if (empty($pending_footage)): ?>
                    <div class="empty-state">
                        <p>No pending footage.</p>
                        <p>Upload match footage from Veo, Pixellot, or manual upload to generate highlights.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($pending_footage as $footage): ?>
                        <div class="footage-card">
                            <div class="footage-info">
                                <h4><?php echo htmlspecialchars($footage['home_team']); ?> vs <?php echo htmlspecialchars($footage['away_team']); ?></h4>
                                <p><?php echo date('M d, Y', strtotime($footage['match_date'])); ?></p>
                                <p>Provider: <?php echo ucfirst($footage['camera_provider']); ?> • Duration: <?php echo $footage['video_duration_seconds']; ?>s</p>
                            </div>
                            <div>
                                <span class="status-badge status-<?php echo strtolower($footage['processing_status']); ?>">
                                    <?php echo ucfirst($footage['processing_status']); ?>
                                </span>
                            </div>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="footage_id" value="<?php echo $footage['footage_id']; ?>">
                                <button type="submit" name="process_footage" class="btn btn-primary">
                                    Process Now
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Social Media Tab -->
        <div id="platforms" class="tab-content">
            <div class="section">
                <h2>📱 Social Media Performance</h2>
                
                <table class="platform-table">
                    <thead>
                        <tr>
                            <th>Platform</th>
                            <th>Total Reels</th>
                            <th>Total Engagement</th>
                            <th>Total Reach</th>
                            <th>Avg Engagement</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($social_dashboard as $platform): ?>
                            <tr>
                                <td>
                                    <span class="platform-badge platform-<?php echo strtolower($platform['platform']); ?>">
                                        <?php echo ucfirst($platform['platform']); ?>
                                    </span>
                                </td>
                                <td><?php echo $platform['total_reels']; ?></td>
                                <td><?php echo number_format($platform['total_engagement']); ?></td>
                                <td><?php echo number_format($platform['total_reach']); ?></td>
                                <td><?php echo number_format($platform['avg_engagement']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
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
