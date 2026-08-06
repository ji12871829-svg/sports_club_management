<?php
/**
 * Churn Analysis & Wellness Tracking
 * Handles predictive churn analysis and member wellness tracking
 */

class ChurnWellnessAnalytics {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Analyze member churn risk
     */
    public function analyzeMemberChurnRisk($member_id) {
        $member = $this->getMemberActivity($member_id);
        if (!$member) return false;

        // Calculate risk score (0-100)
        $risk_score = 0;
        $risk_factors = [];

        // Factor 1: Days since last login (max 30 points)
        $days_since_login = $member['days_since_last_login'];
        if ($days_since_login > 60) {
            $risk_score += 30;
            $risk_factors[] = "No login in " . $days_since_login . " days";
        } elseif ($days_since_login > 30) {
            $risk_score += 20;
            $risk_factors[] = "Inactive for " . $days_since_login . " days";
        } elseif ($days_since_login > 14) {
            $risk_score += 10;
        }

        // Factor 2: Days since last booking (max 25 points)
        $days_since_booking = $member['days_since_last_booking'];
        if ($days_since_booking > 90) {
            $risk_score += 25;
            $risk_factors[] = "No bookings in " . $days_since_booking . " days";
        } elseif ($days_since_booking > 60) {
            $risk_score += 15;
            $risk_factors[] = "Low booking frequency";
        } elseif ($days_since_booking > 30) {
            $risk_score += 10;
        }

        // Factor 3: Booking frequency trend (max 20 points)
        $booking_trend = $this->getBookingTrend($member_id);
        if ($booking_trend['trend'] == 'declining') {
            $risk_score += 20;
            $risk_factors[] = "Declining booking trend";
        } elseif ($booking_trend['trend'] == 'flat') {
            $risk_score += 10;
        }

        // Factor 4: Engagement score (max 25 points)
        $engagement_score = $this->calculateEngagementScore($member_id);
        if ($engagement_score < 30) {
            $risk_score += 25;
            $risk_factors[] = "Low engagement score: " . $engagement_score;
        } elseif ($engagement_score < 50) {
            $risk_score += 15;
        } elseif ($engagement_score < 70) {
            $risk_score += 5;
        }

        // Determine risk level
        if ($risk_score >= 75) {
            $risk_level = 'critical';
        } elseif ($risk_score >= 50) {
            $risk_level = 'high';
        } elseif ($risk_score >= 25) {
            $risk_level = 'medium';
        } else {
            $risk_level = 'low';
        }

        // Store analysis
        $this->storeChurnAnalysis($member_id, $risk_score, $risk_level, $engagement_score, $booking_trend['trend']);

        return [
            'member_id' => $member_id,
            'risk_score' => $risk_score,
            'risk_level' => $risk_level,
            'risk_factors' => $risk_factors,
            'engagement_score' => $engagement_score,
            'booking_trend' => $booking_trend['trend']
        ];
    }

    /**
     * Get member activity
     */
    private function getMemberActivity($member_id) {
        $stmt = $this->db->prepare("
            SELECT 
                m.member_id,
                m.first_name,
                m.last_name,
                m.email,
                m.phone_number,
                DATEDIFF(CURDATE(), COALESCE(m.last_login, m.date_joined)) as days_since_last_login,
                DATEDIFF(CURDATE(), COALESCE(MAX(b.booking_date), m.date_joined)) as days_since_last_booking
            FROM members m
            LEFT JOIN bookings b ON m.member_id = b.member_id
            WHERE m.member_id = ?
            GROUP BY m.member_id
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get booking trend
     */
    private function getBookingTrend($member_id) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(CASE WHEN booking_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as recent_bookings,
                COUNT(CASE WHEN booking_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND booking_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as previous_bookings
            FROM bookings
            WHERE member_id = ?
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        $trend = 'flat';
        if ($result['recent_bookings'] < $result['previous_bookings']) {
            $trend = 'declining';
        } elseif ($result['recent_bookings'] > $result['previous_bookings']) {
            $trend = 'improving';
        }

        return ['trend' => $trend, 'recent' => $result['recent_bookings'], 'previous' => $result['previous_bookings']];
    }

    /**
     * Calculate engagement score
     */
    private function calculateEngagementScore($member_id) {
        $score = 0;

        // Bookings (max 30 points)
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM bookings WHERE member_id = ? AND booking_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
            if ($stmt) { $stmt->bind_param("i", $member_id); $stmt->execute(); $bookings = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0); $score += min(($bookings / 10) * 30, 30); $stmt->close(); }
        } catch (\Throwable $e) { /* table may not exist */ }

        // Volunteer hours (max 20 points) — skip if table doesn't exist
        try {
            $stmt = $this->db->prepare("SELECT COALESCE(SUM(hours_worked), 0) as hours FROM volunteer_assignments WHERE member_id = ? AND status = 'completed'");
            if ($stmt) { $stmt->bind_param("i", $member_id); $stmt->execute(); $volunteer_hours = (float)($stmt->get_result()->fetch_assoc()['hours'] ?? 0); $score += min(($volunteer_hours / 10) * 20, 20); $stmt->close(); }
        } catch (\Throwable $e) { /* table may not exist */ }

        // Loyalty points (max 20 points) — skip if table doesn't exist
        try {
            $stmt = $this->db->prepare("SELECT COALESCE(current_balance, 0) as points FROM member_loyalty_points WHERE member_id = ?");
            if ($stmt) { $stmt->bind_param("i", $member_id); $stmt->execute(); $loyalty_points = (float)($stmt->get_result()->fetch_assoc()['points'] ?? 0); $score += min(($loyalty_points / 100) * 20, 20); $stmt->close(); }
        } catch (\Throwable $e) { /* table may not exist */ }

        // Event attendance (max 15 points) — skip if table doesn't exist
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM event_attendance WHERE member_id = ? AND event_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
            if ($stmt) { $stmt->bind_param("i", $member_id); $stmt->execute(); $events = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0); $score += min(($events / 5) * 15, 15); $stmt->close(); }
        } catch (\Throwable $e) { /* table may not exist */ }

        // Forum activity (max 15 points) — skip if table doesn't exist
        try {
            $stmt = $this->db->prepare("SELECT COUNT(*) as count FROM forum_posts WHERE member_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
            if ($stmt) { $stmt->bind_param("i", $member_id); $stmt->execute(); $posts = (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0); $score += min(($posts / 10) * 15, 15); $stmt->close(); }
        } catch (\Throwable $e) { /* table may not exist */ }

        return round($score, 2);
    }

    /**
     * Store churn analysis
     */
    private function storeChurnAnalysis($member_id, $risk_score, $risk_level, $engagement_score, $booking_trend) {
        $stmt = $this->db->prepare("
            INSERT INTO member_churn_risk 
            (member_id, risk_score, risk_level, engagement_score, booking_frequency_trend)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            risk_score = ?, risk_level = ?, engagement_score = ?, booking_frequency_trend = ?
        ");
        $stmt->bind_param("issssssss", $member_id, $risk_score, $risk_level, $engagement_score, $booking_trend, $risk_score, $risk_level, $engagement_score, $booking_trend);
        return $stmt->execute();
    }

    /**
     * Get high-risk members
     */
    public function getHighRiskMembers($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT 
                mcr.member_id,
                mcr.risk_score,
                mcr.risk_level,
                mcr.engagement_score,
                m.first_name,
                m.last_name,
                m.email,
                CASE WHEN EXISTS (
                    SELECT 1 FROM member_memberships mm
                    WHERE mm.member_id = m.member_id AND mm.status = 'Active'
                ) THEN 'Active' ELSE 'Inactive' END AS membership_status
            FROM member_churn_risk mcr
            JOIN members m ON mcr.member_id = m.member_id
            WHERE mcr.risk_level IN ('high', 'critical')
            ORDER BY mcr.risk_score DESC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Log wellness tracking
     */
    public function logWellnessTracking($member_id, $off_pitch_minutes = 0, $on_pitch_minutes = 0, $injury_status = 'healthy', $injury_notes = null) {
        $tracking_date = date('Y-m-d');
        $wellness_score = $this->calculateWellnessScore($off_pitch_minutes, $on_pitch_minutes, $injury_status);

        $stmt = $this->db->prepare("
            INSERT INTO member_wellness_tracking 
            (member_id, tracking_date, off_pitch_minutes, on_pitch_minutes, injury_status, injury_notes, wellness_score)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            off_pitch_minutes = ?, on_pitch_minutes = ?, injury_status = ?, injury_notes = ?, wellness_score = ?
        ");
        
        // 7 INSERT params + 5 UPDATE params = 12 total; type string must match.
        $stmt->bind_param(
            "isiiissiissi",
            $member_id,
            $tracking_date,
            $off_pitch_minutes,
            $on_pitch_minutes,
            $injury_status,
            $injury_notes,
            $wellness_score,
            $off_pitch_minutes,
            $on_pitch_minutes,
            $injury_status,
            $injury_notes,
            $wellness_score
        );
        
        return $stmt->execute();
    }

    /**
     * Calculate wellness score
     */
    private function calculateWellnessScore($off_pitch_minutes, $on_pitch_minutes, $injury_status) {
        $score = 50; // Base score

        // Activity bonus
        $total_minutes = $off_pitch_minutes + $on_pitch_minutes;
        if ($total_minutes >= 60) $score += 30;
        elseif ($total_minutes >= 30) $score += 15;

        // Injury penalty
        if ($injury_status == 'healthy') $score += 20;
        elseif ($injury_status == 'minor_injury') $score -= 10;
        elseif ($injury_status == 'major_injury') $score -= 30;
        elseif ($injury_status == 'recovery') $score -= 15;

        return min(max($score, 0), 100);
    }

    /**
     * Get member wellness history
     */
    public function getMemberWellnessHistory($member_id, $days = 30) {
        $stmt = $this->db->prepare("
            SELECT * FROM member_wellness_tracking 
            WHERE member_id = ? AND tracking_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY tracking_date DESC
        ");
        $stmt->bind_param("ii", $member_id, $days);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get injured members
     */
    public function getInjuredMembers() {
        $stmt = $this->db->prepare("
            SELECT DISTINCT
                m.member_id,
                m.first_name,
                m.last_name,
                mwt.injury_status,
                mwt.injury_notes,
                mwt.recommended_rest_until,
                mwt.tracking_date
            FROM members m
            JOIN member_wellness_tracking mwt ON m.member_id = mwt.member_id
            WHERE mwt.injury_status IN ('minor_injury', 'major_injury', 'recovery')
            AND mwt.tracking_date = (
                SELECT MAX(tracking_date) FROM member_wellness_tracking 
                WHERE member_id = m.member_id
            )
            ORDER BY mwt.injury_status DESC
        ");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Recommend retention actions
     */
    public function recommendRetentionActions($member_id) {
        $analysis = $this->analyzeMemberChurnRisk($member_id);
        $actions = [];

        if ($analysis['risk_level'] == 'critical' || $analysis['risk_level'] == 'high') {
            $actions[] = "Send personalized re-engagement email";
            $actions[] = "Offer 20% discount on next month's membership";
            $actions[] = "Invite to free trial session";
            $actions[] = "Schedule call with membership coordinator";
        }

        if (in_array("No login in", $analysis['risk_factors'])) {
            $actions[] = "Send reminder about app features";
        }

        if (in_array("Low booking frequency", $analysis['risk_factors'])) {
            $actions[] = "Suggest popular time slots";
            $actions[] = "Recommend group classes";
        }

        // Store actions
        $actions_text = implode("; ", $actions);
        $stmt = $this->db->prepare("
            UPDATE member_churn_risk 
            SET retention_actions_taken = ?
            WHERE member_id = ?
        ");
        $stmt->bind_param("si", $actions_text, $member_id);
        $stmt->execute();

        return $actions;
    }
}

?>
