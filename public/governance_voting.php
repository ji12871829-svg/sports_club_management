<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/dao_governance.php';

// Check if user is logged in
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$governance = new DAOGovernance($conn);
$member_id = $_SESSION['member_id'];

// Get active proposals
$proposals = $governance->getActiveProposals(20);

// Get member's token balance
$token_balance = $governance->getTokenBalance($member_id);

// Get member's voting history
$voting_history = $governance->getMemberVotes($member_id);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Club Governance - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .governance-container {
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

        .token-balance {
            background: rgba(255,255,255,0.2);
            padding: 15px 20px;
            border-radius: 5px;
            margin-top: 15px;
            display: inline-block;
        }

        .token-balance strong {
            font-size: 24px;
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
            border-bottom: 2px solid #667eea;
            padding-bottom: 15px;
        }

        .proposal-card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .proposal-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }

        .proposal-header h3 {
            margin: 0;
            color: #333;
            flex: 1;
        }

        .proposal-type {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
            background: #e7e7ff;
            color: #667eea;
        }

        .proposal-description {
            color: #666;
            margin: 15px 0;
            line-height: 1.6;
        }

        .proposal-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 15px 0;
        }

        .stat {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            text-align: center;
        }

        .stat-label {
            font-size: 11px;
            color: #999;
            text-transform: uppercase;
        }

        .stat-value {
            font-size: 18px;
            font-weight: bold;
            color: #667eea;
        }

        .voting-bar {
            display: flex;
            height: 20px;
            border-radius: 10px;
            overflow: hidden;
            background: #f0f0f0;
            margin: 10px 0;
        }

        .vote-for {
            background: #4caf50;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 11px;
            font-weight: bold;
        }

        .vote-against {
            background: #f44336;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 11px;
            font-weight: bold;
        }

        .proposal-actions {
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
        }

        .btn-primary {
            background: #667eea;
            color: white;
            flex: 1;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            flex: 1;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-success {
            background: #4caf50;
            color: white;
        }

        .btn-success:hover {
            background: #45a049;
        }

        .btn-danger {
            background: #f44336;
            color: white;
        }

        .btn-danger:hover {
            background: #da190b;
        }

        .time-remaining {
            font-size: 12px;
            color: #999;
            margin-top: 10px;
        }

        .time-remaining.urgent {
            color: #f44336;
            font-weight: bold;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }

        .empty-state p {
            font-size: 16px;
        }

        .voting-history {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .voting-history-item {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .vote-info h4 {
            margin: 0 0 5px 0;
            color: #333;
        }

        .vote-info p {
            margin: 0;
            font-size: 12px;
            color: #666;
        }

        .vote-choice {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .vote-for-badge {
            background: #d4edda;
            color: #155724;
        }

        .vote-against-badge {
            background: #f8d7da;
            color: #721c24;
        }

        .vote-abstain-badge {
            background: #e2e3e5;
            color: #383d41;
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

    <div class="governance-container">
        <div class="header">
            <h1>🗳️ Club Governance & Voting</h1>
            <p>Have your say in club decisions using your governance tokens</p>
            <div class="token-balance">
                <strong><?php echo $token_balance; ?></strong>
                Governance Tokens
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="switchTab('active')">Active Proposals</button>
            <button class="tab" onclick="switchTab('history')">Your Votes</button>
        </div>

        <!-- Active Proposals Tab -->
        <div id="active" class="tab-content active">
            <div class="section">
                <h2>📋 Active Proposals</h2>
                
                <?php if (empty($proposals)): ?>
                    <div class="empty-state">
                        <p>No active proposals at the moment.</p>
                        <p>Check back soon for new club decisions to vote on!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($proposals as $proposal): ?>
                        <div class="proposal-card">
                            <div class="proposal-header">
                                <h3><?php echo htmlspecialchars($proposal['title']); ?></h3>
                                <span class="proposal-type"><?php echo str_replace('_', ' ', $proposal['proposal_type']); ?></span>
                            </div>

                            <p class="proposal-description">
                                <?php echo htmlspecialchars(substr($proposal['description'], 0, 200)); ?>...
                            </p>

                            <div class="proposal-stats">
                                <div class="stat">
                                    <div class="stat-label">Total Votes</div>
                                    <div class="stat-value"><?php echo $proposal['total_votes']; ?></div>
                                </div>
                                <div class="stat">
                                    <div class="stat-label">Approval Rate</div>
                                    <div class="stat-value"><?php echo $proposal['approval_percentage']; ?>%</div>
                                </div>
                                <div class="stat">
                                    <div class="stat-label">Time Remaining</div>
                                    <div class="stat-value"><?php echo $proposal['hours_remaining']; ?>h</div>
                                </div>
                            </div>

                            <div class="voting-bar">
                                <?php if ($proposal['total_votes'] > 0): ?>
                                    <div class="vote-for" style="width: <?php echo ($proposal['votes_for'] / $proposal['total_votes']) * 100; ?>%;">
                                        <?php echo $proposal['votes_for']; ?> FOR
                                    </div>
                                    <div class="vote-against" style="width: <?php echo ($proposal['votes_against'] / $proposal['total_votes']) * 100; ?>%;">
                                        <?php echo $proposal['votes_against']; ?> AGAINST
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="proposal-actions">
                                <form method="POST" style="flex: 1;">
                                    <input type="hidden" name="proposal_id" value="<?php echo $proposal['proposal_id']; ?>">
                                    <button type="submit" name="vote_for" class="btn btn-success">
                                        👍 Vote For
                                    </button>
                                </form>
                                <form method="POST" style="flex: 1;">
                                    <input type="hidden" name="proposal_id" value="<?php echo $proposal['proposal_id']; ?>">
                                    <button type="submit" name="vote_against" class="btn btn-danger">
                                        👎 Vote Against
                                    </button>
                                </form>
                            </div>

                            <div class="time-remaining <?php echo $proposal['hours_remaining'] < 24 ? 'urgent' : ''; ?>">
                                ⏱️ Voting ends in <?php echo $proposal['hours_remaining']; ?> hours
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Voting History Tab -->
        <div id="history" class="tab-content">
            <div class="section">
                <h2>📜 Your Voting History</h2>
                
                <?php if (empty($voting_history)): ?>
                    <div class="empty-state">
                        <p>You haven't voted yet.</p>
                        <p>Check out the active proposals and make your voice heard!</p>
                    </div>
                <?php else: ?>
                    <ul class="voting-history">
                        <?php foreach ($voting_history as $vote): ?>
                            <li class="voting-history-item">
                                <div class="vote-info">
                                    <h4><?php echo htmlspecialchars($vote['title']); ?></h4>
                                    <p><?php echo date('M d, Y', strtotime($vote['voted_at'])); ?> • <?php echo $vote['tokens_used']; ?> tokens used</p>
                                </div>
                                <span class="vote-choice vote-<?php echo strtolower($vote['vote_choice']); ?>-badge">
                                    <?php echo ucfirst($vote['vote_choice']); ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
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
