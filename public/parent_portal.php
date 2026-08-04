<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/parent_portal_compliance.php';

// Check if user is logged in
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$parent_mgr = new ParentPortalCompliance($conn);
$member_id = $_SESSION['member_id'];

// Get member info
$stmt = $conn->prepare("SELECT * FROM members WHERE member_id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

// Check if user has parent account
$stmt = $conn->prepare("SELECT * FROM parent_accounts WHERE parent_id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$parent_account = $stmt->get_result()->fetch_assoc();

// Get children
$children = [];
if ($parent_account) {
    $children = $parent_mgr->getParentChildren($parent_account['parent_id']);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Parent Portal - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .parent-container {
            max-width: 1000px;
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

        .children-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .child-card {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
        }

        .child-card h3 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .child-info {
            font-size: 14px;
            color: #666;
            margin: 10px 0;
        }

        .child-actions {
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

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 5px rgba(102, 126, 234, 0.3);
        }

        .waiver-list {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
        }

        .waiver-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .waiver-item:last-child {
            border-bottom: none;
        }

        .waiver-info h4 {
            margin: 0 0 5px 0;
            color: #333;
        }

        .waiver-info p {
            margin: 0;
            font-size: 12px;
            color: #666;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            font-weight: bold;
        }

        .status-expired {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
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

        .info-box {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .info-box strong {
            display: block;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="parent-container">
        <div class="header">
            <h1>👨‍👩‍👧‍👦 Parent Portal</h1>
            <p>Manage your children's club activities, medical waivers, and authorized pickups</p>
        </div>

        <!-- Children Management -->
        <div class="section">
            <h2>👶 My Children</h2>
            
            <?php if (empty($children)): ?>
                <div class="empty-state">
                    <p>No children linked to your account yet.</p>
                    <p>Link your child's membership to manage their activities.</p>
                </div>
            <?php else: ?>
                <div class="children-grid">
                    <?php foreach ($children as $child): ?>
                        <div class="child-card">
                            <h3><?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?></h3>
                            <div class="child-info">
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($child['email']); ?></p>
                                <p><strong>Status:</strong> 
                                    <span class="<?php echo $child['membership_status'] == 'active' ? 'status-active' : 'status-expired'; ?>">
                                        <?php echo ucfirst($child['membership_status']); ?>
                                    </span>
                                </p>
                                <p><strong>Verified:</strong> <?php echo $child['verified'] ? '✓ Yes' : '✗ No'; ?></p>
                            </div>
                            <div class="child-actions">
                                <a href="parent_child_waivers.php?child_id=<?php echo $child['member_id']; ?>" class="btn btn-primary">
                                    Waivers
                                </a>
                                <a href="parent_child_pickups.php?child_id=<?php echo $child['member_id']; ?>" class="btn btn-secondary">
                                    Pickups
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div style="margin-top: 20px;">
                <a href="parent_link_child.php" class="btn btn-primary">+ Link Child</a>
            </div>
        </div>

        <!-- Medical Waivers -->
        <div class="section">
            <h2>📋 Medical Waivers</h2>
            
            <div class="info-box">
                <strong>Important:</strong> Medical waivers must be signed and up-to-date for all children to participate in club activities.
            </div>

            <?php if (empty($children)): ?>
                <div class="empty-state">
                    <p>Link a child to manage their medical waivers.</p>
                </div>
            <?php else: ?>
                <?php foreach ($children as $child): ?>
                    <div style="margin-bottom: 30px;">
                        <h3><?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?></h3>
                        
                        <?php 
                            $waivers = $parent_mgr->getMemberWaivers($child['member_id']);
                            if (empty($waivers)):
                        ?>
                            <div class="empty-state" style="padding: 20px;">
                                <p>No medical waivers on file.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($waivers as $waiver): ?>
                                <div class="waiver-list">
                                    <div class="waiver-item">
                                        <div class="waiver-info">
                                            <h4><?php echo ucfirst(str_replace('_', ' ', $waiver['waiver_type'])); ?> Waiver</h4>
                                            <p>Signed: <?php echo date('M d, Y', strtotime($waiver['signed_date'])); ?></p>
                                            <p>Expires: <?php echo date('M d, Y', strtotime($waiver['expiry_date'])); ?></p>
                                        </div>
                                        <span class="<?php echo $waiver['status'] == 'active' ? 'status-active' : 'status-expired'; ?>">
                                            <?php echo ucfirst($waiver['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <a href="parent_upload_waiver.php?child_id=<?php echo $child['member_id']; ?>" class="btn btn-primary" style="margin-top: 10px;">
                            + Upload Waiver
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Authorized Pickups -->
        <div class="section">
            <h2>🚗 Authorized Pickups</h2>
            
            <div class="info-box">
                <strong>Tip:</strong> Add trusted people who are authorized to pick up your children from the club.
            </div>

            <?php if (empty($children)): ?>
                <div class="empty-state">
                    <p>Link a child to manage authorized pickups.</p>
                </div>
            <?php else: ?>
                <?php foreach ($children as $child): ?>
                    <div style="margin-bottom: 30px;">
                        <h3><?php echo htmlspecialchars($child['first_name'] . ' ' . $child['last_name']); ?></h3>
                        
                        <?php 
                            $pickups = $parent_mgr->getAuthorizedPickups($child['member_id']);
                            if (empty($pickups)):
                        ?>
                            <div class="empty-state" style="padding: 20px;">
                                <p>No authorized pickups added yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($pickups as $pickup): ?>
                                <div class="waiver-list">
                                    <div class="waiver-item">
                                        <div class="waiver-info">
                                            <h4><?php echo htmlspecialchars($pickup['authorized_person_name']); ?></h4>
                                            <p>Relationship: <?php echo htmlspecialchars($pickup['relationship']); ?></p>
                                            <p>Phone: <?php echo htmlspecialchars($pickup['authorized_person_phone']); ?></p>
                                        </div>
                                        <span class="status-active">Active</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                        <a href="parent_add_pickup.php?child_id=<?php echo $child['member_id']; ?>" class="btn btn-primary" style="margin-top: 10px;">
                            + Add Authorized Person
                        </a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="text-align: center; margin-top: 40px;">
            <a href="member_profile.php" class="btn btn-secondary">Back to Profile</a>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
