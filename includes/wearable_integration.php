<?php
/**
 * Wearable Integration & Fitness Tracking
 * Handles syncing with Apple Health, Garmin, Fitbit, Strava, and WHOOP
 */

class WearableIntegration {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Register a wearable device
     */
    public function registerDevice($member_id, $device_type, $device_name, $api_token) {
        // Validate device type
        $valid_types = ['apple_health', 'garmin', 'fitbit', 'strava', 'whoop'];
        if (!in_array($device_type, $valid_types)) {
            return ['success' => false, 'message' => 'Invalid device type'];
        }

        // Check if device already registered
        $check = $this->db->prepare("SELECT device_id FROM wearable_devices WHERE member_id = ? AND device_type = ?");
        $check->bind_param("is", $member_id, $device_type);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Device already registered'];
        }

        // Register device
        $stmt = $this->db->prepare("
            INSERT INTO wearable_devices 
            (member_id, device_type, device_name, api_token, is_active)
            VALUES (?, ?, ?, ?, TRUE)
        ");
        $stmt->bind_param("isss", $member_id, $device_type, $device_name, $api_token);
        
        if ($stmt->execute()) {
            return ['success' => true, 'device_id' => $this->db->insert_id];
        }
        return ['success' => false, 'message' => 'Failed to register device'];
    }

    /**
     * Sync activities from wearable device
     */
    public function syncActivities($member_id, $device_id, $activities) {
        $synced_count = 0;

        foreach ($activities as $activity) {
            // Check if activity already synced
            $check = $this->db->prepare("
                SELECT activity_id FROM fitness_activities 
                WHERE member_id = ? AND external_activity_id = ?
            ");
            $check->bind_param("is", $member_id, $activity['external_id']);
            $check->execute();
            
            if ($check->get_result()->num_rows > 0) {
                continue; // Skip duplicate
            }

            // Insert activity
            $stmt = $this->db->prepare("
                INSERT INTO fitness_activities 
                (member_id, device_id, activity_type, activity_date, duration_minutes, 
                 distance_km, calories_burned, heart_rate_avg, heart_rate_max, 
                 intensity_level, external_activity_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $activity_type = $activity['type'] ?? 'other';
            $activity_date = $activity['date'] ?? date('Y-m-d');
            $duration = $activity['duration_minutes'] ?? 0;
            $distance = $activity['distance_km'] ?? 0;
            $calories = $activity['calories'] ?? 0;
            $hr_avg = $activity['heart_rate_avg'] ?? null;
            $hr_max = $activity['heart_rate_max'] ?? null;
            $intensity = $this->calculateIntensity($activity) ?? 'moderate';
            $external_id = $activity['external_id'];

            $stmt->bind_param(
                "iissiiiiiis",
                $member_id,
                $device_id,
                $activity_type,
                $activity_date,
                $duration,
                $distance,
                $calories,
                $hr_avg,
                $hr_max,
                $intensity,
                $external_id
            );

            if ($stmt->execute()) {
                $synced_count++;
                // Award loyalty points
                $this->awardFitnessPoints($member_id, $activity);
            }
        }

        // Update last sync time
        $this->db->prepare("UPDATE wearable_devices SET last_sync = NOW() WHERE device_id = ?")->bind_param("i", $device_id)->execute();

        return ['success' => true, 'synced_count' => $synced_count];
    }

    /**
     * Calculate intensity level from activity data
     */
    private function calculateIntensity($activity) {
        $hr_avg = $activity['heart_rate_avg'] ?? 0;
        $hr_max = $activity['heart_rate_max'] ?? 0;

        if ($hr_avg > 150 || $hr_max > 180) {
            return 'very_high';
        } elseif ($hr_avg > 130 || $hr_max > 160) {
            return 'high';
        } elseif ($hr_avg > 110 || $hr_max > 140) {
            return 'moderate';
        }
        return 'low';
    }

    /**
     * Award loyalty points for fitness activities
     */
    private function awardFitnessPoints($member_id, $activity) {
        $points = 0;

        // Points based on duration
        $duration = $activity['duration_minutes'] ?? 0;
        if ($duration >= 60) $points += 50;
        elseif ($duration >= 30) $points += 25;
        elseif ($duration >= 15) $points += 10;

        // Bonus for high intensity
        $intensity = $this->calculateIntensity($activity);
        if ($intensity == 'very_high') $points += 25;
        elseif ($intensity == 'high') $points += 15;

        // Bonus for distance
        $distance = $activity['distance_km'] ?? 0;
        if ($distance >= 10) $points += 20;
        elseif ($distance >= 5) $points += 10;

        if ($points > 0) {
            $stmt = $this->db->prepare("
                UPDATE member_loyalty_points 
                SET current_balance = current_balance + ?, last_activity_date = NOW()
                WHERE member_id = ?
            ");
            $stmt->bind_param("ii", $points, $member_id);
            $stmt->execute();
        }

        return $points;
    }

    /**
     * Get member's fitness activities
     */
    public function getMemberActivities($member_id, $days = 30) {
        $stmt = $this->db->prepare("
            SELECT 
                fa.*,
                wd.device_type,
                wd.device_name
            FROM fitness_activities fa
            LEFT JOIN wearable_devices wd ON fa.device_id = wd.device_id
            WHERE fa.member_id = ? AND fa.activity_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
            ORDER BY fa.activity_date DESC
        ");
        $stmt->bind_param("ii", $member_id, $days);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Calculate fitness statistics
     */
    public function getFitnessStats($member_id, $days = 30) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_activities,
                SUM(duration_minutes) as total_minutes,
                SUM(distance_km) as total_distance,
                SUM(calories_burned) as total_calories,
                AVG(heart_rate_avg) as avg_heart_rate,
                COUNT(DISTINCT activity_type) as activity_variety
            FROM fitness_activities
            WHERE member_id = ? AND activity_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        ");
        $stmt->bind_param("ii", $member_id, $days);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Update fitness leaderboards
     */
    public function updateLeaderboards() {
        $leaderboard_types = ['weekly_steps', 'monthly_calories', 'total_distance', 'weekly_workouts'];

        foreach ($leaderboard_types as $type) {
            $this->calculateLeaderboard($type);
        }
    }

    /**
     * Calculate specific leaderboard
     */
    private function calculateLeaderboard($type) {
        $period_start = date('Y-m-d');
        $period_end = date('Y-m-d');

        if ($type == 'weekly_steps' || $type == 'weekly_workouts') {
            $period_start = date('Y-m-d', strtotime('monday this week'));
            $period_end = date('Y-m-d', strtotime('sunday this week'));
        } elseif ($type == 'monthly_calories') {
            $period_start = date('Y-m-01');
            $period_end = date('Y-m-t');
        }

        // Clear existing leaderboard
        $this->db->prepare("DELETE FROM fitness_leaderboards WHERE leaderboard_type = ? AND period_start = ?")->bind_param("ss", $type, $period_start)->execute();

        // Calculate scores
        if ($type == 'weekly_workouts') {
            $query = "
                SELECT 
                    member_id,
                    COUNT(*) as score
                FROM fitness_activities
                WHERE activity_date BETWEEN ? AND ?
                GROUP BY member_id
                ORDER BY score DESC
            ";
        } elseif ($type == 'monthly_calories') {
            $query = "
                SELECT 
                    member_id,
                    SUM(calories_burned) as score
                FROM fitness_activities
                WHERE activity_date BETWEEN ? AND ?
                GROUP BY member_id
                ORDER BY score DESC
            ";
        } elseif ($type == 'total_distance') {
            $query = "
                SELECT 
                    member_id,
                    SUM(distance_km) as score
                FROM fitness_activities
                WHERE activity_date BETWEEN ? AND ?
                GROUP BY member_id
                ORDER BY score DESC
            ";
        }

        $stmt = $this->db->prepare($query);
        $stmt->bind_param("ss", $period_start, $period_end);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Insert rankings
        $rank = 1;
        foreach ($results as $row) {
            $insert = $this->db->prepare("
                INSERT INTO fitness_leaderboards 
                (member_id, leaderboard_type, rank, score, period_start, period_end)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $insert->bind_param("isidss", $row['member_id'], $type, $rank, $row['score'], $period_start, $period_end);
            $insert->execute();
            $rank++;
        }
    }

    /**
     * Get leaderboard
     */
    public function getLeaderboard($leaderboard_type, $limit = 20) {
        $stmt = $this->db->prepare("
            SELECT 
                fl.rank,
                fl.score,
                m.member_id,
                m.first_name,
                m.last_name,
                m.profile_photo AS profile_photo_url
            FROM fitness_leaderboards fl
            JOIN members m ON fl.member_id = m.member_id
            WHERE fl.leaderboard_type = ? 
            AND fl.period_start = (
                SELECT MAX(period_start) FROM fitness_leaderboards 
                WHERE leaderboard_type = ?
            )
            ORDER BY fl.rank ASC
            LIMIT ?
        ");
        $stmt->bind_param("ssi", $leaderboard_type, $leaderboard_type, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get member's registered devices
     */
    public function getMemberDevices($member_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM wearable_devices 
            WHERE member_id = ? AND is_active = TRUE
            ORDER BY created_at DESC
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Disconnect a wearable device
     */
    public function disconnectDevice($device_id, $member_id) {
        $stmt = $this->db->prepare("
            UPDATE wearable_devices 
            SET is_active = FALSE
            WHERE device_id = ? AND member_id = ?
        ");
        $stmt->bind_param("ii", $device_id, $member_id);
        return $stmt->execute();
    }
}

?>
