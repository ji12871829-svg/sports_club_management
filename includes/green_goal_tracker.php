<?php
/**
 * Green Goal Tracker & Sustainability
 * Tracks eco-friendly activities and club carbon footprint
 */

class GreenGoalTracker {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Log an eco-friendly activity
     */
    public function logEcoActivity($member_id, $activity_type, $activity_date, $description = null) {
        $valid_types = ['carpool', 'public_transport', 'bike', 'reusable_bottle', 'waste_reduction', 'tree_planting'];
        if (!in_array($activity_type, $valid_types)) {
            return ['success' => false, 'message' => 'Invalid activity type'];
        }

        // Calculate CO2 saved and eco credits
        $co2_saved = $this->calculateCO2Saved($activity_type);
        $eco_credits = $this->calculateEcoCredits($activity_type);

        $stmt = $this->db->prepare("
            INSERT INTO eco_activities 
            (member_id, activity_type, activity_date, co2_saved_kg, eco_credits_earned, description, verified)
            VALUES (?, ?, ?, ?, ?, ?, FALSE)
        ");
        $stmt->bind_param("issdis", $member_id, $activity_type, $activity_date, $co2_saved, $eco_credits, $description);
        
        if ($stmt->execute()) {
            // Award loyalty points
            $this->awardEcoPoints($member_id, $eco_credits);
            
            return ['success' => true, 'eco_credits' => $eco_credits, 'co2_saved' => $co2_saved];
        }
        return ['success' => false, 'message' => 'Failed to log activity'];
    }

    /**
     * Calculate CO2 saved for activity type
     */
    private function calculateCO2Saved($activity_type) {
        $co2_values = [
            'carpool' => 2.5,      // kg CO2 per carpool trip
            'public_transport' => 3.0,  // kg CO2 saved vs car
            'bike' => 0.5,         // kg CO2 per km (assuming 5km trip)
            'reusable_bottle' => 0.1,   // kg CO2 per bottle
            'waste_reduction' => 0.3,   // kg CO2 per kg waste avoided
            'tree_planting' => 20.0     // kg CO2 per tree over lifetime
        ];

        return $co2_values[$activity_type] ?? 0;
    }

    /**
     * Calculate eco credits earned
     */
    private function calculateEcoCredits($activity_type) {
        $credits = [
            'carpool' => 10,
            'public_transport' => 15,
            'bike' => 20,
            'reusable_bottle' => 5,
            'waste_reduction' => 8,
            'tree_planting' => 50
        ];

        return $credits[$activity_type] ?? 0;
    }

    /**
     * Award loyalty points for eco activities
     */
    private function awardEcoPoints($member_id, $eco_credits) {
        $loyalty_points = $eco_credits * 2; // Convert eco credits to loyalty points

        $stmt = $this->db->prepare("
            UPDATE member_loyalty_points 
            SET current_balance = current_balance + ?
            WHERE member_id = ?
        ");
        $stmt->bind_param("ii", $loyalty_points, $member_id);
        $stmt->execute();
    }

    /**
     * Get member's eco activities
     */
    public function getMemberEcoActivities($member_id, $days = 30) {
        $stmt = $this->db->prepare("
            SELECT * FROM eco_activities 
            WHERE member_id = ? AND activity_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY activity_date DESC
        ");
        $stmt->bind_param("ii", $member_id, $days);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Calculate member's total CO2 saved
     */
    public function getMemberCO2Saved($member_id, $days = 365) {
        $stmt = $this->db->prepare("
            SELECT 
                SUM(co2_saved_kg) as total_co2,
                COUNT(*) as activity_count,
                SUM(eco_credits_earned) as total_credits
            FROM eco_activities 
            WHERE member_id = ? AND activity_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        ");
        $stmt->bind_param("ii", $member_id, $days);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get club-wide carbon footprint
     */
    public function getClubCarbonFootprint($month_year = null) {
        if (!$month_year) {
            $month_year = date('Y-m-01');
        }

        $stmt = $this->db->prepare("
            SELECT * FROM club_carbon_footprint 
            WHERE month_year = ?
        ");
        $stmt->bind_param("s", $month_year);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Update club carbon footprint
     */
    public function updateClubFootprint($month_year, $energy_kwh, $water_liters, $waste_kg, $member_travel_co2) {
        // Calculate totals
        $energy_co2 = $energy_kwh * 0.5; // kg CO2 per kWh (varies by region)
        $water_co2 = $water_liters * 0.0003; // kg CO2 per liter
        $waste_co2 = $waste_kg * 0.5; // kg CO2 per kg waste
        $total_co2 = $energy_co2 + $water_co2 + $waste_co2 + $member_travel_co2;

        // Get offset from eco activities
        $offset_stmt = $this->db->prepare("
            SELECT SUM(co2_saved_kg) as offset FROM eco_activities 
            WHERE MONTH(activity_date) = MONTH(?) AND YEAR(activity_date) = YEAR(?)
        ");
        $offset_stmt->bind_param("ss", $month_year, $month_year);
        $offset_stmt->execute();
        $offset_result = $offset_stmt->get_result()->fetch_assoc();
        $offset = $offset_result['offset'] ?? 0;

        $net_carbon = $total_co2 - $offset;

        $stmt = $this->db->prepare("
            INSERT INTO club_carbon_footprint 
            (month_year, total_co2_kg, energy_usage_kwh, water_usage_liters, waste_generated_kg, 
             member_travel_co2, offset_by_eco_activities, net_carbon_kg)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            total_co2_kg = ?, energy_usage_kwh = ?, water_usage_liters = ?, waste_generated_kg = ?,
            member_travel_co2 = ?, offset_by_eco_activities = ?, net_carbon_kg = ?
        ");
        
        $stmt->bind_param(
            "sddddddddddddd",
            $month_year, $total_co2, $energy_kwh, $water_liters, $waste_kg, $member_travel_co2, $offset, $net_carbon,
            $total_co2, $energy_kwh, $water_liters, $waste_kg, $member_travel_co2, $offset, $net_carbon
        );
        
        return $stmt->execute();
    }

    /**
     * Get eco leaderboard
     */
    public function getEcoLeaderboard($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT 
                m.member_id,
                m.first_name,
                m.last_name,
                m.profile_photo AS profile_photo_url,
                SUM(ea.co2_saved_kg) as total_co2_saved,
                SUM(ea.eco_credits_earned) as total_eco_credits,
                COUNT(ea.eco_activity_id) as activity_count
            FROM members m
            LEFT JOIN eco_activities ea ON m.member_id = ea.member_id
            GROUP BY m.member_id
            HAVING total_eco_credits > 0
            ORDER BY total_eco_credits DESC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get club sustainability report
     */
    public function getClubSustainabilityReport($months = 12) {
        $stmt = $this->db->prepare("
            SELECT 
                month_year,
                total_co2_kg,
                energy_usage_kwh,
                water_usage_liters,
                waste_generated_kg,
                offset_by_eco_activities,
                net_carbon_kg
            FROM club_carbon_footprint
            WHERE month_year >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            ORDER BY month_year DESC
        ");
        $stmt->bind_param("i", $months);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Verify an eco activity (admin function)
     */
    public function verifyEcoActivity($eco_activity_id, $admin_id) {
        $stmt = $this->db->prepare("
            UPDATE eco_activities 
            SET verified = TRUE, verified_by = ?
            WHERE eco_activity_id = ?
        ");
        $stmt->bind_param("ii", $admin_id, $eco_activity_id);
        return $stmt->execute();
    }

    /**
     * Get unverified eco activities (for admin review)
     */
    public function getUnverifiedActivities($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT 
                ea.*,
                m.first_name,
                m.last_name
            FROM eco_activities ea
            JOIN members m ON ea.member_id = m.member_id
            WHERE ea.verified = FALSE
            ORDER BY ea.created_at ASC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

?>
