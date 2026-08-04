<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/marketplace_fan_wall.php';

// Check if user is logged in
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$marketplace = new MarketplaceFanWall($conn);
$member_id = $_SESSION['member_id'];

// Get filter
$category = isset($_GET['category']) ? $_GET['category'] : null;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = 12;
$offset = ($page - 1) * $limit;

// Get listings
$listings = $marketplace->getActiveListings($category, $limit, $offset);

// Get categories
$categories = ['boots', 'rackets', 'kits', 'protective', 'accessories', 'other'];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gear Swap Marketplace - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .marketplace-container {
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            margin: 0;
        }

        .header-buttons {
            display: flex;
            gap: 10px;
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
            background: white;
            color: #667eea;
            font-weight: bold;
        }

        .btn-primary:hover {
            background: #f0f0f0;
        }

        .btn-secondary {
            background: rgba(255,255,255,0.2);
            color: white;
        }

        .btn-secondary:hover {
            background: rgba(255,255,255,0.3);
        }

        .filters {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }

        .filter-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 8px 16px;
            border: 2px solid #ddd;
            background: white;
            border-radius: 20px;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.3s;
        }

        .filter-btn:hover,
        .filter-btn.active {
            border-color: #667eea;
            background: #667eea;
            color: white;
        }

        .listings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .listing-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .listing-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.15);
        }

        .listing-image {
            width: 100%;
            height: 200px;
            background: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: #ddd;
        }

        .listing-content {
            padding: 15px;
        }

        .listing-title {
            font-size: 16px;
            font-weight: bold;
            margin: 0 0 10px 0;
            color: #333;
        }

        .listing-meta {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            flex-wrap: wrap;
        }

        .listing-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .badge-condition {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-type {
            background: #fff3cd;
            color: #856404;
        }

        .listing-price {
            font-size: 20px;
            font-weight: bold;
            color: #667eea;
            margin: 10px 0;
        }

        .listing-seller {
            font-size: 12px;
            color: #999;
            margin-bottom: 10px;
        }

        .listing-actions {
            display: flex;
            gap: 10px;
        }

        .listing-actions a {
            flex: 1;
            padding: 10px;
            text-align: center;
            border-radius: 5px;
            text-decoration: none;
            font-size: 12px;
            font-weight: bold;
        }

        .action-view {
            background: #667eea;
            color: white;
        }

        .action-view:hover {
            background: #5568d3;
        }

        .action-contact {
            background: #f0f0f0;
            color: #333;
        }

        .action-contact:hover {
            background: #e0e0e0;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }

        .empty-state p {
            font-size: 16px;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 40px;
        }

        .pagination a,
        .pagination span {
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            color: #333;
        }

        .pagination a:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        .pagination .active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="marketplace-container">
        <div class="header">
            <div>
                <h1>♻️ Gear Swap Marketplace</h1>
                <p>Buy, sell, and trade sports equipment with other members</p>
            </div>
            <div class="header-buttons">
                <a href="gear_marketplace_sell.php" class="btn btn-primary">+ Sell Item</a>
                <a href="gear_marketplace_my_listings.php" class="btn btn-secondary">My Listings</a>
            </div>
        </div>

        <div class="filters">
            <div class="filter-group">
                <a href="gear_marketplace.php" class="filter-btn <?php echo !$category ? 'active' : ''; ?>">
                    All Items
                </a>
                <?php foreach ($categories as $cat): ?>
                    <a href="?category=<?php echo $cat; ?>" class="filter-btn <?php echo $category == $cat ? 'active' : ''; ?>">
                        <?php echo ucfirst($cat); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if (empty($listings)): ?>
            <div class="empty-state">
                <p>No items found in this category.</p>
                <p><a href="gear_marketplace_sell.php" style="color: #667eea;">Be the first to list an item!</a></p>
            </div>
        <?php else: ?>
            <div class="listings-grid">
                <?php foreach ($listings as $listing): ?>
                    <div class="listing-card">
                        <div class="listing-image">
                            <?php 
                                $icons = [
                                    'boots' => '👟',
                                    'rackets' => '🎾',
                                    'kits' => '👕',
                                    'protective' => '🛡️',
                                    'accessories' => '🎒',
                                    'other' => '📦'
                                ];
                                echo $icons[$listing['category']] ?? '📦';
                            ?>
                        </div>
                        <div class="listing-content">
                            <h3 class="listing-title"><?php echo htmlspecialchars($listing['item_name']); ?></h3>
                            
                            <div class="listing-meta">
                                <span class="listing-badge badge-condition">
                                    <?php echo ucfirst($listing['condition']); ?>
                                </span>
                                <span class="listing-badge badge-type">
                                    <?php echo ucfirst(str_replace('_', ' ', $listing['listing_type'])); ?>
                                </span>
                            </div>

                            <?php if ($listing['price']): ?>
                                <div class="listing-price">$<?php echo number_format($listing['price'], 2); ?></div>
                            <?php else: ?>
                                <div class="listing-price">Free</div>
                            <?php endif; ?>

                            <div class="listing-seller">
                                by <?php echo htmlspecialchars($listing['first_name'] . ' ' . $listing['last_name']); ?>
                            </div>

                            <div class="listing-actions">
                                <a href="gear_marketplace_detail.php?id=<?php echo $listing['listing_id']; ?>" class="action-view">
                                    View Details
                                </a>
                                <a href="gear_marketplace_contact.php?id=<?php echo $listing['listing_id']; ?>" class="action-contact">
                                    Contact
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $category ? '&category=' . $category : ''; ?>">← Previous</a>
                <?php endif; ?>
                
                <span class="active"><?php echo $page; ?></span>
                
                <?php if (count($listings) == $limit): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $category ? '&category=' . $category : ''; ?>">Next →</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div style="text-align: center; margin-top: 40px;">
            <a href="member_profile.php" class="btn btn-secondary" style="display: inline-block;">Back to Profile</a>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
