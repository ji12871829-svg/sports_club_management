<?php
/**
 * Referral and Loyalty Management Helper
 * Handles member referrals and loyalty points
 */

class ReferralLoyalty {
    private $db;
    private $REFERRAL_CREDIT = 50; // Credits awarded for successful referral
    private $SIGNUP_BONUS = 10; // Bonus points for new member signup

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Create a new referral
     */
    public function createReferral($referrer_id, $referred_email, $referred_name) {
        // Check if referral already exists
        $check = $this->db->prepare("
            SELECT referral_id FROM member_referrals 
            WHERE referrer_id = ? AND referred_email = ? AND status IN ('pending', 'accepted')
        ");
        $check->bind_param("is", $referrer_id, $referred_email);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Referral already exists for this email'];
        }

        // Create referral
        $expires_at = date('Y-m-d H:i:s', strtotime('+30 days'));
        $stmt = $this->db->prepare("
            INSERT INTO member_referrals 
            (referrer_id, referred_email, referred_name, status, expires_at)
            VALUES (?, ?, ?, 'pending', ?)
        ");
        $stmt->bind_param("isss", $referrer_id, $referred_email, $referred_name, $expires_at);
        
        if ($stmt->execute()) {
            return ['success' => true, 'referral_id' => $this->db->insert_id];
        }
        return ['success' => false, 'message' => 'Failed to create referral'];
    }

    /**
     * Complete a referral when referred member joins
     */
    public function completeReferral($referred_email, $referred_member_id) {
        $stmt = $this->db->prepare("
            UPDATE member_referrals 
            SET referred_member_id = ?, status = 'completed', completed_at = NOW()
            WHERE referred_email = ? AND status = 'pending' AND expires_at > NOW()
        ");
        $stmt->bind_param("is", $referred_member_id, $referred_email);
        
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Get referrer_id
            $get_referrer = $this->db->prepare("
                SELECT referrer_id FROM member_referrals 
                WHERE referred_member_id = ? AND status = 'completed'
            ");
            $get_referrer->bind_param("i", $referred_member_id);
            $get_referrer->execute();
            $result = $get_referrer->get_result()->fetch_assoc();
            $referrer_id = $result['referrer_id'];

            // Award credits to referrer
            $this->awardReferralCredits($referrer_id, $this->REFERRAL_CREDIT);
            
            // Award signup bonus to new member
            $this->initializeLoyaltyPoints($referred_member_id, $this->SIGNUP_BONUS);
            
            return true;
        }
        return false;
    }

    /**
     * Award referral credits to referrer
     */
    private function awardReferralCredits($member_id, $credits) {
        // Update loyalty points
        $stmt = $this->db->prepare("
            INSERT INTO member_loyalty_points (member_id, total_points, current_balance)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
            total_points = total_points + ?,
            current_balance = current_balance + ?
        ");
        $stmt->bind_param("idddd", $member_id, $credits, $credits, $credits, $credits);
        $stmt->execute();

        // Record transaction
        $this->recordLoyaltyTransaction($member_id, 'earned', $credits, 'Referral bonus');
    }

    /**
     * Initialize loyalty points for new member
     */
    public function initializeLoyaltyPoints($member_id, $signup_bonus = null) {
        $bonus = $signup_bonus ?? $this->SIGNUP_BONUS;
        
        $stmt = $this->db->prepare("
            INSERT INTO member_loyalty_points 
            (member_id, total_points, current_balance, tier)
            VALUES (?, ?, ?, 'bronze')
            ON DUPLICATE KEY UPDATE
            total_points = total_points + ?,
            current_balance = current_balance + ?
        ");
        $stmt->bind_param("idddd", $member_id, $bonus, $bonus, $bonus, $bonus);
        
        if ($stmt->execute()) {
            $this->recordLoyaltyTransaction($member_id, 'earned', $bonus, 'Welcome bonus');
            return true;
        }
        return false;
    }

    /**
     * Award points for membership renewal
     */
    public function awardRenewalPoints($member_id, $points = 25) {
        $stmt = $this->db->prepare("
            UPDATE member_loyalty_points 
            SET total_points = total_points + ?, current_balance = current_balance + ?
            WHERE member_id = ?
        ");
        $stmt->bind_param("ddi", $points, $points, $member_id);
        
        if ($stmt->execute()) {
            $this->recordLoyaltyTransaction($member_id, 'earned', $points, 'Membership renewal');
            return true;
        }
        return false;
    }

    /**
     * Redeem loyalty points
     */
    public function redeemPoints($member_id, $points, $reason = 'Manual redemption') {
        // Check balance
        $stmt = $this->db->prepare("SELECT current_balance FROM member_loyalty_points WHERE member_id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (!$result || $result['current_balance'] < $points) {
            return ['success' => false, 'message' => 'Insufficient loyalty points'];
        }

        // Deduct points
        $stmt = $this->db->prepare("
            UPDATE member_loyalty_points 
            SET points_redeemed = points_redeemed + ?, current_balance = current_balance - ?
            WHERE member_id = ?
        ");
        $stmt->bind_param("ddi", $points, $points, $member_id);
        
        if ($stmt->execute()) {
            $this->recordLoyaltyTransaction($member_id, 'redeemed', $points, $reason);
            return ['success' => true, 'message' => 'Points redeemed successfully'];
        }
        return ['success' => false, 'message' => 'Failed to redeem points'];
    }

    /**
     * Update member loyalty tier based on points
     */
    public function updateLoyaltyTier($member_id) {
        $stmt = $this->db->prepare("SELECT total_points FROM member_loyalty_points WHERE member_id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        if (!$result) return false;

        $points = $result['total_points'];
        $tier = 'bronze';
        
        if ($points >= 500) $tier = 'platinum';
        elseif ($points >= 300) $tier = 'gold';
        elseif ($points >= 150) $tier = 'silver';

        $stmt = $this->db->prepare("UPDATE member_loyalty_points SET tier = ? WHERE member_id = ?");
        $stmt->bind_param("si", $tier, $member_id);
        return $stmt->execute();
    }

    /**
     * Get member loyalty information
     */
    public function getMemberLoyaltyInfo($member_id) {
        $stmt = $this->db->prepare("
            SELECT 
                mlp.loyalty_id,
                mlp.total_points,
                mlp.points_redeemed,
                mlp.current_balance,
                mlp.tier,
                mlp.last_earned,
                mlp.last_redeemed,
                COUNT(mr.referral_id) as total_referrals,
                SUM(CASE WHEN mr.status = 'completed' THEN 1 ELSE 0 END) as successful_referrals
            FROM member_loyalty_points mlp
            LEFT JOIN member_referrals mr ON mlp.member_id = mr.referrer_id
            WHERE mlp.member_id = ?
            GROUP BY mlp.loyalty_id
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get member referral history
     */
    public function getMemberReferrals($member_id) {
        $stmt = $this->db->prepare("
            SELECT 
                referral_id,
                referred_name,
                referred_email,
                status,
                credits_awarded,
                created_at,
                completed_at
            FROM member_referrals 
            WHERE referrer_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Record loyalty transaction
     */
    private function recordLoyaltyTransaction($member_id, $type, $points, $reason) {
        $stmt = $this->db->prepare("
            INSERT INTO loyalty_transactions 
            (member_id, transaction_type, points, reason)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("isds", $member_id, $type, $points, $reason);
        return $stmt->execute();
    }

    /**
     * Get loyalty leaderboard
     */
    public function getLoyaltyLeaderboard($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT 
                m.member_id,
                m.first_name,
                m.last_name,
                mlp.total_points,
                mlp.current_balance,
                mlp.tier,
                ROW_NUMBER() OVER (ORDER BY mlp.total_points DESC) as rank
            FROM members m
            JOIN member_loyalty_points mlp ON m.member_id = mlp.member_id
            WHERE mlp.total_points > 0
            ORDER BY mlp.total_points DESC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

?>
