<?php
/**
 * Marketplace & Fan Wall Management
 * Handles gear swap marketplace and fan wall shoutouts
 */

class MarketplaceFanWall {
    private $db;
    private $PLATFORM_FEE_PERCENT = 0.05; // 5% platform fee

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Create gear marketplace listing
     */
    public function createListing($seller_id, $item_name, $category, $description, $condition, $price, $listing_type, $image_url = null) {
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));

        $stmt = $this->db->prepare("
            INSERT INTO gear_marketplace_listings 
            (seller_id, item_name, category, description, condition, price, listing_type, item_image_url, status, expires_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending_approval', ?)
        ");
        
        $status = 'pending_approval';
        $stmt->bind_param("issssdss", $seller_id, $item_name, $category, $description, $condition, $price, $listing_type, $image_url, $expires_at);
        
        if ($stmt->execute()) {
            return $this->db->insert_id;
        }
        return false;
    }

    /**
     * Get active listings
     */
    public function getActiveListings($category = null, $limit = 20, $offset = 0) {
        $query = "
            SELECT 
                gml.listing_id,
                gml.seller_id,
                gml.item_name,
                gml.category,
                gml.description,
                gml.condition,
                gml.price,
                gml.listing_type,
                gml.item_image_url,
                gml.views,
                m.first_name,
                m.last_name
            FROM gear_marketplace_listings gml
            LEFT JOIN members m ON gml.seller_id = m.member_id
            WHERE gml.status = 'active'
        ";

        if ($category) {
            $query .= " AND gml.category = ?";
        }

        $query .= " ORDER BY gml.created_at DESC LIMIT ? OFFSET ?";

        $stmt = $this->db->prepare($query);
        
        if ($category) {
            $stmt->bind_param("sii", $category, $limit, $offset);
        } else {
            $stmt->bind_param("ii", $limit, $offset);
        }
        
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get seller listings
     */
    public function getSellerListings($seller_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM gear_marketplace_listings 
            WHERE seller_id = ? 
            ORDER BY created_at DESC
        ");
        $stmt->bind_param("i", $seller_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Record view
     */
    public function recordView($listing_id) {
        $stmt = $this->db->prepare("
            UPDATE gear_marketplace_listings 
            SET views = views + 1
            WHERE listing_id = ?
        ");
        $stmt->bind_param("i", $listing_id);
        return $stmt->execute();
    }

    /**
     * Create transaction
     */
    public function createTransaction($listing_id, $buyer_id, $seller_id, $transaction_type = 'purchase') {
        // Get listing details
        $listing = $this->getListing($listing_id);
        if (!$listing) return false;

        $amount_paid = $listing['price'];
        $platform_fee = $transaction_type == 'purchase' ? ($amount_paid * $this->PLATFORM_FEE_PERCENT) : 0;

        $stmt = $this->db->prepare("
            INSERT INTO gear_marketplace_transactions 
            (listing_id, buyer_id, seller_id, transaction_type, amount_paid, platform_fee, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("iiiidds", $listing_id, $buyer_id, $seller_id, $transaction_type, $amount_paid, $platform_fee);
        
        if ($stmt->execute()) {
            // Update listing status
            $new_status = 'sold';
            $stmt = $this->db->prepare("UPDATE gear_marketplace_listings SET status = ? WHERE listing_id = ?");
            $stmt->bind_param("si", $new_status, $listing_id);
            $stmt->execute();
            
            return $this->db->insert_id;
        }
        return false;
    }

    /**
     * Get listing details
     */
    private function getListing($listing_id) {
        $stmt = $this->db->prepare("SELECT * FROM gear_marketplace_listings WHERE listing_id = ?");
        $stmt->bind_param("i", $listing_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Complete transaction
     */
    public function completeTransaction($transaction_id) {
        $stmt = $this->db->prepare("
            UPDATE gear_marketplace_transactions 
            SET status = 'completed', completed_at = NOW()
            WHERE transaction_id = ?
        ");
        $stmt->bind_param("i", $transaction_id);
        return $stmt->execute();
    }

    /**
     * Rate transaction
     */
    public function rateTransaction($transaction_id, $rater_id, $rating, $feedback) {
        // Get transaction to determine if rater is buyer or seller
        $stmt = $this->db->prepare("
            SELECT buyer_id, seller_id FROM gear_marketplace_transactions 
            WHERE transaction_id = ?
        ");
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc();

        if ($transaction['buyer_id'] == $rater_id) {
            // Buyer rating
            $stmt = $this->db->prepare("
                UPDATE gear_marketplace_transactions 
                SET buyer_rating = ?, buyer_feedback = ?
                WHERE transaction_id = ?
            ");
        } else {
            // Seller rating
            $stmt = $this->db->prepare("
                UPDATE gear_marketplace_transactions 
                SET seller_rating = ?, seller_feedback = ?
                WHERE transaction_id = ?
            ");
        }

        $stmt->bind_param("isi", $rating, $feedback, $transaction_id);
        return $stmt->execute();
    }

    /**
     * Create fan wall shoutout
     */
    public function createShoutout($fixture_id, $member_id, $shoutout_text, $amount_paid = 0) {
        $stmt = $this->db->prepare("
            INSERT INTO fan_wall_shoutouts 
            (fixture_id, member_id, shoutout_text, amount_paid, status)
            VALUES (?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("iisds", $fixture_id, $member_id, $shoutout_text, $amount_paid);
        
        if ($stmt->execute()) {
            // Award loyalty points for tipping
            if ($amount_paid > 0) {
                $this->awardTippingBonus($member_id, $amount_paid);
            }
            return $this->db->insert_id;
        }
        return false;
    }

    /**
     * Award tipping bonus
     */
    private function awardTippingBonus($member_id, $amount) {
        // Award 1 loyalty point per dollar tipped
        $points = floor($amount);
        
        $stmt = $this->db->prepare("
            UPDATE member_loyalty_points 
            SET total_points = total_points + ?, current_balance = current_balance + ?
            WHERE member_id = ?
        ");
        $stmt->bind_param("ddi", $points, $points, $member_id);
        $stmt->execute();
    }

    /**
     * Get fan wall shoutouts for fixture
     */
    public function getFixtureFanWall($fixture_id, $limit = 50) {
        $stmt = $this->db->prepare("
            SELECT 
                fws.shoutout_id,
                fws.member_id,
                fws.shoutout_text,
                fws.amount_paid,
                fws.display_order,
                fws.status,
                m.first_name,
                m.last_name
            FROM fan_wall_shoutouts fws
            LEFT JOIN members m ON fws.member_id = m.member_id
            WHERE fws.fixture_id = ? AND fws.status = 'approved'
            ORDER BY fws.display_order ASC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $fixture_id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Approve shoutout
     */
    public function approveShoutout($shoutout_id) {
        $stmt = $this->db->prepare("
            UPDATE fan_wall_shoutouts 
            SET status = 'approved'
            WHERE shoutout_id = ?
        ");
        $stmt->bind_param("i", $shoutout_id);
        return $stmt->execute();
    }

    /**
     * Get pending shoutouts for moderation
     */
    public function getPendingShoutouts($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT 
                fws.shoutout_id,
                fws.fixture_id,
                fws.member_id,
                fws.shoutout_text,
                fws.amount_paid,
                fws.created_at,
                m.first_name,
                m.last_name,
                f.home_team,
                f.away_team
            FROM fan_wall_shoutouts fws
            LEFT JOIN members m ON fws.member_id = m.member_id
            LEFT JOIN fixtures f ON fws.fixture_id = f.fixture_id
            WHERE fws.status = 'pending'
            ORDER BY fws.created_at ASC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Track sponsorship impressions
     */
    public function trackSponsorshipImpression($campaign_id, $member_id, $impression_type) {
        $stmt = $this->db->prepare("
            INSERT INTO sponsorship_impressions 
            (campaign_id, member_id, impression_type)
            VALUES (?, ?, ?)
        ");
        $stmt->bind_param("iis", $campaign_id, $member_id, $impression_type);
        
        if ($stmt->execute()) {
            // Update campaign impressions count
            $this->updateCampaignMetrics($campaign_id);
            return true;
        }
        return false;
    }

    /**
     * Track sponsorship click
     */
    public function trackSponsorshipClick($campaign_id, $member_id) {
        $stmt = $this->db->prepare("
            UPDATE sponsorship_impressions 
            SET clicked = TRUE, click_timestamp = NOW()
            WHERE campaign_id = ? AND member_id = ? 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->bind_param("ii", $campaign_id, $member_id);
        return $stmt->execute();
    }

    /**
     * Update campaign metrics
     */
    private function updateCampaignMetrics($campaign_id) {
        $stmt = $this->db->prepare("
            UPDATE sponsorship_campaigns sc
            SET impressions = (
                SELECT COUNT(*) FROM sponsorship_impressions 
                WHERE campaign_id = sc.campaign_id
            ),
            clicks = (
                SELECT COUNT(*) FROM sponsorship_impressions 
                WHERE campaign_id = sc.campaign_id AND clicked = TRUE
            )
            WHERE campaign_id = ?
        ");
        $stmt->bind_param("i", $campaign_id);
        return $stmt->execute();
    }

    /**
     * Get sponsorship ROI report
     */
    public function getSponsorshipROI($campaign_id) {
        $stmt = $this->db->prepare("
            SELECT 
                sc.campaign_id,
                sc.sponsor_id,
                sc.campaign_name,
                sc.annual_fee,
                COUNT(DISTINCT si.impression_id) as total_impressions,
                COUNT(DISTINCT CASE WHEN si.clicked = TRUE THEN si.impression_id END) as total_clicks,
                ROUND((COUNT(DISTINCT CASE WHEN si.clicked = TRUE THEN si.impression_id END) / 
                       COUNT(DISTINCT si.impression_id) * 100), 2) as click_through_rate
            FROM sponsorship_campaigns sc
            LEFT JOIN sponsorship_impressions si ON sc.campaign_id = si.campaign_id
            WHERE sc.campaign_id = ?
            GROUP BY sc.campaign_id
        ");
        $stmt->bind_param("i", $campaign_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

?>
