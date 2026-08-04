<?php
/**
 * Volunteer Management Helper
 * Handles volunteer task assignments and credit tracking
 */

class VolunteerManagement {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Create a new volunteer task
     */
    public function createTask($fixture_id, $task_name, $task_description, $task_type, $required_count) {
        $stmt = $this->db->prepare("
            INSERT INTO volunteer_tasks 
            (fixture_id, task_name, task_description, task_type, required_count, status)
            VALUES (?, ?, ?, ?, ?, 'open')
        ");
        $stmt->bind_param("isssi", $fixture_id, $task_name, $task_description, $task_type, $required_count);
        
        if ($stmt->execute()) {
            return $this->db->insert_id;
        }
        return false;
    }

    /**
     * Get available volunteer tasks
     */
    public function getAvailableTasks($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT 
                vt.task_id,
                vt.fixture_id,
                vt.task_name,
                vt.task_description,
                vt.task_type,
                vt.required_count,
                COUNT(va.assignment_id) as assigned_count,
                (vt.required_count - COUNT(va.assignment_id)) as slots_available,
                f.match_date,
                f.home_team,
                f.away_team
            FROM volunteer_tasks vt
            LEFT JOIN volunteer_assignments va ON vt.task_id = va.task_id AND va.status IN ('assigned', 'accepted')
            LEFT JOIN fixtures f ON vt.fixture_id = f.fixture_id
            WHERE vt.status = 'open' AND f.match_date >= CURDATE()
            GROUP BY vt.task_id
            HAVING slots_available > 0
            ORDER BY f.match_date ASC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Assign a volunteer to a task
     */
    public function assignVolunteer($task_id, $member_id) {
        // Check if already assigned
        $check = $this->db->prepare("
            SELECT assignment_id FROM volunteer_assignments 
            WHERE task_id = ? AND member_id = ? AND status != 'declined'
        ");
        $check->bind_param("ii", $task_id, $member_id);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Already assigned to this task'];
        }

        // Assign volunteer
        $stmt = $this->db->prepare("
            INSERT INTO volunteer_assignments 
            (task_id, member_id, status)
            VALUES (?, ?, 'assigned')
        ");
        $stmt->bind_param("ii", $task_id, $member_id);
        
        if ($stmt->execute()) {
            return ['success' => true, 'message' => 'Successfully assigned to task'];
        }
        return ['success' => false, 'message' => 'Failed to assign task'];
    }

    /**
     * Accept a volunteer assignment
     */
    public function acceptAssignment($assignment_id, $member_id) {
        $stmt = $this->db->prepare("
            UPDATE volunteer_assignments 
            SET status = 'accepted'
            WHERE assignment_id = ? AND member_id = ?
        ");
        $stmt->bind_param("ii", $assignment_id, $member_id);
        return $stmt->execute();
    }

    /**
     * Complete a volunteer assignment and record hours
     */
    public function completeAssignment($assignment_id, $hours_worked, $notes = null) {
        $stmt = $this->db->prepare("
            UPDATE volunteer_assignments 
            SET status = 'completed', hours_worked = ?, completed_at = NOW(), notes = ?
            WHERE assignment_id = ?
        ");
        $stmt->bind_param("dsi", $hours_worked, $notes, $assignment_id);
        
        if ($stmt->execute()) {
            // Update volunteer credits
            $this->updateVolunteerCredits($assignment_id, $hours_worked);
            return true;
        }
        return false;
    }

    /**
     * Update volunteer hours and credits
     */
    private function updateVolunteerCredits($assignment_id, $hours_worked) {
        // Get member_id from assignment
        $stmt = $this->db->prepare("SELECT member_id FROM volunteer_assignments WHERE assignment_id = ?");
        $stmt->bind_param("i", $assignment_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $member_id = $result['member_id'];

        // Calculate credits (1 hour = 1 credit)
        $credits = $hours_worked;

        // Update or insert volunteer credits
        $stmt = $this->db->prepare("
            INSERT INTO volunteer_credits (member_id, total_hours, total_credits)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
            total_hours = total_hours + ?,
            total_credits = total_credits + ?
        ");
        $stmt->bind_param("idddd", $member_id, $hours_worked, $credits, $hours_worked, $credits);
        $stmt->execute();

        // Update members table
        $stmt = $this->db->prepare("
            UPDATE members 
            SET volunteer_hours = volunteer_hours + ?
            WHERE member_id = ?
        ");
        $stmt->bind_param("di", $hours_worked, $member_id);
        $stmt->execute();

        // Record transaction
        $this->recordLoyaltyTransaction($member_id, 'earned', $credits, 'Volunteer work: ' . $hours_worked . ' hours');
    }

    /**
     * Get volunteer statistics for a member
     */
    public function getMemberVolunteerStats($member_id) {
        $stmt = $this->db->prepare("
            SELECT 
                m.member_id,
                m.first_name,
                m.last_name,
                m.volunteer_hours,
                vc.total_hours,
                vc.total_credits,
                COUNT(CASE WHEN va.status = 'completed' THEN 1 END) as tasks_completed,
                COUNT(CASE WHEN va.status = 'accepted' THEN 1 END) as tasks_upcoming
            FROM members m
            LEFT JOIN volunteer_credits vc ON m.member_id = vc.member_id
            LEFT JOIN volunteer_assignments va ON m.member_id = va.member_id
            WHERE m.member_id = ?
            GROUP BY m.member_id
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get top volunteers
     */
    public function getTopVolunteers($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT 
                m.member_id,
                m.first_name,
                m.last_name,
                vc.total_hours,
                vc.total_credits,
                COUNT(va.assignment_id) as total_tasks
            FROM members m
            LEFT JOIN volunteer_credits vc ON m.member_id = vc.member_id
            LEFT JOIN volunteer_assignments va ON m.member_id = va.member_id AND va.status = 'completed'
            WHERE vc.total_hours > 0
            GROUP BY m.member_id
            ORDER BY vc.total_hours DESC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
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
}

?>
