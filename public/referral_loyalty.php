<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/referral_loyalty.php';
require_once __DIR__ . '/../includes/csrf.php';

// Check if user is logged in
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$member_id = $_SESSION['member_id'];
$referral_mgr = new ReferralLoyalty($conn);

$message = '';
$message_type = '';

// Handle referral creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_referral']) && csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
    $referred_email = trim($_POST['referred_email']);
    $referred_name = trim($_POST['referred_name']);
    
    if (empty($referred_email) || empty($referred_name)) {
        $message = 'Please fill in all fields';
        $message_type = 'error';
    } else {
        $result = $referral_mgr->createReferral($member_id, $referred_email, $referred_name);
        if ($result['success']) {
            $message = 'Referral created! Share your unique link with your friend.';
            $message_type = 'success';
        } else {
            $message = $result['message'];
            $message_type = 'error';
        }
    }
}

// Get loyalty info
$loyalty_info = $referral_mgr->getMemberLoyaltyInfo($member_id);
$referrals = $referral_mgr->getMemberReferrals($member_id);

// Get member info
$stmt = $conn->prepare("SELECT first_name, last_name FROM members WHERE member_id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

// Generate referral link
$referral_link = "https://" . $_SERVER['HTTP_HOST'] . "/register.php?ref=" . base64_encode($member_id);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral & Loyalty Program - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .referral-container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 20px;
        }

        .loyalty-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 40px;
            text-align: center;
        }

        .loyalty-header h1 {
            margin: 0 0 10px 0;
        }

        .loyalty-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .loyalty-stat {
            background: white;
            border: 2px solid #667eea;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }

        .loyalty-stat h3 {
            margin: 0 0 10px 0;
            color: #666;
            font-size: 14px;
            text-transform: uppercase;
        }

        .loyalty-stat .value {
            font-size: 32px;
            font-weight: bold;
            color: #667eea;
        }

        .tier-badge {
            display: inline-block;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12px;
            margin-top: 10px;
        }

        .tier-bronze { background: #cd7f32; color: white; }
        .tier-silver { background: #c0c0c0; color: #333; }
        .tier-gold { background: #ffd700; color: #333; }
        .tier-platinum { background: #e5e4e2; color: #333; }

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

        .referral-link-box {
            background: #f8f9fa;
            border: 2px dashed #667eea;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }

        .referral-link-box label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
            color: #333;
        }

        .referral-link-box input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-family: monospace;
            font-size: 12px;
            box-sizing: border-box;
        }

        .copy-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            margin-top: 10px;
            font-size: 14px;
        }

        .copy-btn:hover {
            background: #5568d3;
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

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
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

        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .referral-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .referral-table th,
        .referral-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }

        .referral-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #333;
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-pending { background: #fff3cd; color: #856404; }
        .status-accepted { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-expired { background: #f8d7da; color: #721c24; }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .points-breakdown {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin: 15px 0;
        }

        .points-breakdown p {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="referral-container">
        <div class="loyalty-header">
            <h1>Referral & Loyalty Program</h1>
            <p>Earn rewards by referring friends and participating in club activities</p>
        </div>

        <?php if ($message): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Loyalty Stats -->
        <?php if ($loyalty_info): ?>
            <div class="loyalty-stats">
                <div class="loyalty-stat">
                    <h3>Current Points</h3>
                    <div class="value"><?php echo number_format($loyalty_info['current_balance'], 0); ?></div>
                </div>
                <div class="loyalty-stat">
                    <h3>Total Points Earned</h3>
                    <div class="value"><?php echo number_format($loyalty_info['total_points'], 0); ?></div>
                </div>
                <div class="loyalty-stat">
                    <h3>Successful Referrals</h3>
                    <div class="value"><?php echo $loyalty_info['successful_referrals'] ?? 0; ?></div>
                </div>
                <div class="loyalty-stat">
                    <h3>Membership Tier</h3>
                    <div class="value" style="font-size: 24px;">
                        <span class="tier-badge tier-<?php echo strtolower($loyalty_info['tier']); ?>">
                            <?php echo ucfirst($loyalty_info['tier']); ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Referral Section -->
        <div class="section">
            <h2>🔗 Invite Friends to Apex Sports Club</h2>
            
            <p>Share your unique referral link with friends. When they join using your link, you both earn rewards!</p>

            <div class="referral-link-box">
                <label>Your Referral Link:</label>
                <input type="text" id="referralLink" value="<?php echo htmlspecialchars($referral_link); ?>" readonly>
                <button class="copy-btn" onclick="copyToClipboard()">📋 Copy Link</button>
            </div>

            <div class="points-breakdown">
                <p><strong>Rewards for Successful Referral:</strong></p>
                <p>✓ You earn: <strong>50 Loyalty Points</strong></p>
                <p>✓ Your friend earns: <strong>10 Welcome Bonus Points</strong></p>
            </div>

            <h3 style="margin-top: 30px;">Or Manually Refer a Friend</h3>
            <form method="POST">
                <?php echo csrf_field('member_csrf'); ?>
                <div class="form-group">
                    <label>Friend's Name</label>
                    <input type="text" name="referred_name" required>
                </div>
                <div class="form-group">
                    <label>Friend's Email</label>
                    <input type="email" name="referred_email" required>
                </div>
                <button type="submit" name="create_referral" class="btn btn-primary">Send Referral Invitation</button>
            </form>
        </div>

        <!-- Referral History -->
        <div class="section">
            <h2>📊 Your Referral History</h2>
            
            <?php if (empty($referrals)): ?>
                <div class="empty-state">
                    <p>You haven't made any referrals yet.</p>
                    <p>Start inviting friends to earn rewards!</p>
                </div>
            <?php else: ?>
                <table class="referral-table">
                    <thead>
                        <tr>
                            <th>Friend's Name</th>
                            <th>Email</th>
                            <th>Status</th>
                            <th>Rewards Earned</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($referrals as $ref): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ref['referred_name']); ?></td>
                                <td><?php echo htmlspecialchars($ref['referred_email']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($ref['status']); ?>">
                                        <?php echo ucfirst($ref['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo $ref['status'] == 'completed' ? $ref['credits_awarded'] . ' pts' : '-'; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($ref['created_at'])); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- How It Works -->
        <div class="section">
            <h2>💡 How the Loyalty Program Works</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
                <div>
                    <h3>🎯 Earn Points</h3>
                    <p>Earn points by:</p>
                    <ul>
                        <li>Renewing your membership (25 pts)</li>
                        <li>Referring friends (50 pts)</li>
                        <li>Volunteering (1 pt per hour)</li>
                        <li>Attending events</li>
                    </ul>
                </div>
                <div>
                    <h3>📈 Reach Higher Tiers</h3>
                    <ul>
                        <li>Bronze: 0-149 points</li>
                        <li>Silver: 150-299 points</li>
                        <li>Gold: 300-499 points</li>
                        <li>Platinum: 500+ points</li>
                    </ul>
                </div>
                <div>
                    <h3>🎁 Redeem Rewards</h3>
                    <p>Use your points to:</p>
                    <ul>
                        <li>Discount on membership renewal</li>
                        <li>Free training sessions</li>
                        <li>Club merchandise</li>
                        <li>Event tickets</li>
                    </ul>
                </div>
            </div>
        </div>

        <div style="text-align: center; margin-top: 40px;">
            <a href="member_profile.php" class="btn btn-secondary">Back to Profile</a>
        </div>
    </div>

    <script>
        function copyToClipboard() {
            const link = document.getElementById('referralLink');
            link.select();
            document.execCommand('copy');
            alert('Referral link copied to clipboard!');
        }
    </script>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
