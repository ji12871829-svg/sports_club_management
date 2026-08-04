<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/digital_membership_card.php';

// Check if user is logged in
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$member_id = $_SESSION['member_id'];
$card_manager = new DigitalMembershipCard($conn, $member_id);
$card = $card_manager->getOrCreateCard();

// Get member info
$stmt = $conn->prepare("SELECT first_name, last_name, email, membership_status FROM members WHERE member_id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

// Get card stats
$stats = $card_manager->getCardStats();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Digital Membership Card - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .card-container {
            max-width: 600px;
            margin: 40px auto;
            padding: 20px;
        }

        .membership-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 15px;
            padding: 30px;
            color: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            text-align: center;
            margin-bottom: 30px;
        }

        .card-header {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 20px;
            text-transform: uppercase;
            letter-spacing: 2px;
        }

        .card-member-name {
            font-size: 28px;
            font-weight: bold;
            margin: 20px 0;
        }

        .card-number {
            font-size: 14px;
            letter-spacing: 3px;
            margin: 15px 0;
            font-family: monospace;
            background: rgba(255,255,255,0.1);
            padding: 10px;
            border-radius: 5px;
        }

        .qr-code-section {
            background: white;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }

        .qr-code-section img {
            max-width: 200px;
            height: auto;
        }

        .card-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 30px;
        }

        .stat-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #333;
        }

        .expiry-info {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            text-align: left;
        }

        .expiry-info p {
            margin: 5px 0;
            font-size: 14px;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            justify-content: center;
        }

        .btn {
            padding: 10px 20px;
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

        .btn-secondary:hover {
            background: #5a6268;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="card-container">
        <h1>Digital Membership Card</h1>

        <?php if ($card): ?>
            <div class="membership-card">
                <div class="card-header">Apex Sports Club</div>
                <div class="card-member-name"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></div>
                <div class="card-number">Card #: <?php echo htmlspecialchars($card['card_number']); ?></div>
                
                <div class="qr-code-section">
                    <?php if ($card['qr_code']): ?>
                        <img src="<?php echo htmlspecialchars($card['qr_code']); ?>" alt="QR Code" loading="lazy" decoding="async">
                    <?php endif; ?>
                    <p style="margin-top: 10px; color: #666; font-size: 12px;">Scan this QR code at check-in</p>
                </div>

                <div style="font-size: 12px; color: rgba(255,255,255,0.8);">
                    <p>Status: <?php echo ucfirst($member['membership_status']); ?></p>
                    <p>Issued: <?php echo date('M d, Y', strtotime($card['issued_date'])); ?></p>
                    <p>Expires: <?php echo date('M d, Y', strtotime($card['expiry_date'])); ?></p>
                </div>
            </div>

            <?php if ($stats): ?>
                <div class="card-stats">
                    <div class="stat-box">
                        <div class="stat-label">Check-ins</div>
                        <div class="stat-value"><?php echo $stats['scan_count']; ?></div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-label">Last Scanned</div>
                        <div class="stat-value"><?php echo $stats['last_scanned'] ? date('M d', strtotime($stats['last_scanned'])) : 'Never'; ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($stats && $stats['days_until_expiry'] < 30): ?>
                <div class="expiry-info">
                    <p><strong>Card Expiring Soon</strong></p>
                    <p>Your membership card will expire in <?php echo $stats['days_until_expiry']; ?> days.</p>
                    <p>Please renew your membership to continue enjoying club benefits.</p>
                </div>
            <?php endif; ?>

            <div class="action-buttons">
                <a href="member_profile.php" class="btn btn-secondary">Back to Profile</a>
                <a href="memberships.php" class="btn btn-primary">Renew Membership</a>
            </div>

        <?php else: ?>
            <div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; color: #721c24;">
                <p>Unable to generate your digital membership card. Please contact the club administrator.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
