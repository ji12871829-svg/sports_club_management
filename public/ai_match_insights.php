<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/ai_insights_engine.php';

// Check if user is admin
if (!isset($_SESSION['member_id']) || $_SESSION['role'] != 'admin') {
    header('Location: login.php');
    exit;
}

$ai_engine = new AIInsightsEngine($conn);

// Get draft posts
$draft_posts = $ai_engine->getDraftPosts(20);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Match Insights - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .insights-container {
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

        .header p {
            margin: 0;
            opacity: 0.9;
        }

        .posts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .post-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .post-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .post-platform {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .platform-instagram {
            background: #E4405F;
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

        .post-status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-draft {
            background: #f0f0f0;
            color: #666;
        }

        .status-scheduled {
            background: #fff3cd;
            color: #856404;
        }

        .status-published {
            background: #d4edda;
            color: #155724;
        }

        .post-content {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
            font-size: 14px;
            line-height: 1.6;
            color: #333;
            max-height: 150px;
            overflow: auto;
        }

        .post-meta {
            font-size: 12px;
            color: #999;
            margin: 10px 0;
        }

        .post-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }

        .btn {
            padding: 8px 15px;
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

        .engagement-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .stat {
            text-align: center;
        }

        .stat-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
        }

        .stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state p {
            font-size: 16px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="insights-container">
        <div class="header">
            <h1>🤖 AI Match Insights & Social Media</h1>
            <p>Automatically generated match summaries and social media content</p>
        </div>

        <?php if (empty($draft_posts)): ?>
            <div class="empty-state">
                <p>No social media posts yet.</p>
                <p>Complete a match report to generate AI-powered insights and social media content.</p>
            </div>
        <?php else: ?>
            <div class="posts-grid">
                <?php foreach ($draft_posts as $post): ?>
                    <div class="post-card">
                        <div class="post-header">
                            <span class="post-platform platform-<?php echo strtolower($post['platform']); ?>">
                                <?php echo ucfirst($post['platform']); ?>
                            </span>
                            <span class="post-status status-<?php echo strtolower($post['status']); ?>">
                                <?php echo ucfirst($post['status']); ?>
                            </span>
                        </div>

                        <?php if ($post['home_team'] && $post['away_team']): ?>
                            <div class="post-meta">
                                📅 <?php echo $post['home_team']; ?> vs <?php echo $post['away_team']; ?>
                            </div>
                        <?php endif; ?>

                        <div class="post-content">
                            <?php echo htmlspecialchars($post['content']); ?>
                        </div>

                        <div class="engagement-stats">
                            <div class="stat">
                                <div class="stat-label">Engagement</div>
                                <div class="stat-value"><?php echo $post['engagement_count']; ?></div>
                            </div>
                            <div class="stat">
                                <div class="stat-label">Status</div>
                                <div class="stat-value" style="font-size: 14px;">
                                    <?php echo ucfirst($post['status']); ?>
                                </div>
                            </div>
                        </div>

                        <div class="post-actions">
                            <form method="POST" style="display: inline; flex: 1;">
                                <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                <button type="submit" name="schedule_post" class="btn btn-primary">
                                    Schedule
                                </button>
                            </form>
                            <form method="POST" style="display: inline; flex: 1;">
                                <input type="hidden" name="post_id" value="<?php echo $post['post_id']; ?>">
                                <button type="submit" name="edit_post" class="btn btn-secondary">
                                    Edit
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 40px;">
            <a href="dashboard.php" class="btn btn-secondary" style="display: inline-block;">Back to Dashboard</a>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
