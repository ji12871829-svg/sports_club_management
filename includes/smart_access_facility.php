<?php
/**
 * Smart Access & Facility Management
 * Handles access codes, equipment damage reports, and energy management
 */

class SmartAccessFacility {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Generate access code for a booking
     */
    public function generateAccessCode($booking_id, $member_id) {
        // Check if code already exists
        $check = $this->db->prepare("
            SELECT code_id FROM smart_access_codes 
            WHERE booking_id = ? AND code_status = 'active'
        ");
        $check->bind_param("i", $booking_id);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            return $this->getAccessCode($booking_id);
        }

        // Generate 6-digit code (CSPRNG — mt_rand is predictable and must not
        // be used for access credentials)
        $access_code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Get booking details to set expiry
        $booking = $this->getBookingDetails($booking_id);
        $expires_at = date('Y-m-d H:i:s', strtotime($booking['end_time'] . ' +30 minutes'));

        // Insert code
        $stmt = $this->db->prepare("
            INSERT INTO smart_access_codes 
            (booking_id, member_id, access_code, facility_id, expires_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iisss", $booking_id, $member_id, $access_code, $booking['facility_id'], $expires_at);
        
        if ($stmt->execute()) {
            // Send code via WhatsApp
            $this->sendAccessCodeViaWhatsApp($member_id, $access_code, $booking);
            return $access_code;
        }
        return false;
    }

    /**
     * Get access code for a booking
     */
    private function getAccessCode($booking_id) {
        $stmt = $this->db->prepare("
            SELECT access_code FROM smart_access_codes 
            WHERE booking_id = ? AND code_status = 'active'
        ");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ? $result['access_code'] : null;
    }

    /**
     * Get booking details
     */
    private function getBookingDetails($booking_id) {
        $stmt = $this->db->prepare("
            SELECT b.*, f.facility_name 
            FROM bookings b
            LEFT JOIN facilities f ON b.facility_id = f.facility_id
            WHERE b.booking_id = ?
        ");
        $stmt->bind_param("i", $booking_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Send access code via WhatsApp
     */
    private function sendAccessCodeViaWhatsApp($member_id, $access_code, $booking) {
        // Get member phone
        $stmt = $this->db->prepare("SELECT phone_number FROM members WHERE member_id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $member = $stmt->get_result()->fetch_assoc();

        if ($member && $member['phone_number']) {
            $message = "Your Apex Sports Club access code: " . $access_code . 
                      "\nFacility: " . $booking['facility_name'] . 
                      "\nValid until: " . date('H:i', strtotime($booking['end_time']));
            
            // Queue WhatsApp message (implement with your WhatsApp API)
            $this->queueWhatsAppMessage($member['phone_number'], $message);
        }
    }

    /**
     * Queue WhatsApp message (placeholder)
     */
    private function queueWhatsAppMessage($phone, $message) {
        // This would integrate with Meta WhatsApp Cloud API
        // For now, log to database
        error_log("WhatsApp to $phone: $message");
    }

    /**
     * Verify access code
     */
    public function verifyAccessCode($access_code) {
        $stmt = $this->db->prepare("
            SELECT * FROM smart_access_codes 
            WHERE access_code = ? AND code_status = 'active' AND expires_at > NOW()
        ");
        $stmt->bind_param("s", $access_code);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result) {
            // Update code status
            $stmt = $this->db->prepare("
                UPDATE smart_access_codes 
                SET code_status = 'used', first_used_at = NOW(), access_attempts = access_attempts + 1
                WHERE code_id = ?
            ");
            $stmt->bind_param("i", $result['code_id']);
            $stmt->execute();
            
            return $result;
        }
        return null;
    }

    /**
     * Report equipment damage
     */
    public function reportDamage($member_id, $facility_id, $equipment_name, $description, $photo_url = null) {
        $stmt = $this->db->prepare("
            INSERT INTO equipment_damage_reports 
            (member_id, facility_id, equipment_name, damage_description, damage_photo_url, status)
            VALUES (?, ?, ?, ?, ?, 'reported')
        ");
        $stmt->bind_param("iisss", $member_id, $facility_id, $equipment_name, $description, $photo_url);
        
        if ($stmt->execute()) {
            $report_id = $this->db->insert_id;
            
            // Classify damage using AI (simplified)
            $this->classifyDamageAI($report_id, $photo_url);
            
            // Auto-assign to groundskeeper
            $this->autoAssignRepair($report_id);
            
            return $report_id;
        }
        return false;
    }

    /**
     * Classify damage using AI (simplified)
     */
    private function classifyDamageAI($report_id, $photo_url) {
        // Simplified AI classification logic
        // In production, this would call a real AI service
        $damage_class = 'moderate';
        $confidence = 0.85;

        $stmt = $this->db->prepare("
            UPDATE equipment_damage_reports 
            SET ai_damage_class = ?, ai_confidence = ?
            WHERE report_id = ?
        ");
        $stmt->bind_param("sdi", $damage_class, $confidence, $report_id);
        $stmt->execute();
    }

    /**
     * Auto-assign repair to groundskeeper
     */
    private function autoAssignRepair($report_id) {
        // Find groundskeeper role
        $stmt = $this->db->prepare("
            SELECT member_id FROM members 
            WHERE role = 'groundskeeper' OR role = 'maintenance'
            LIMIT 1
        ");
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result) {
            $assigned_to = $result['member_id'];
            $stmt = $this->db->prepare("
                UPDATE equipment_damage_reports 
                SET assigned_to = ?, status = 'reviewed'
                WHERE report_id = ?
            ");
            $stmt->bind_param("ii", $assigned_to, $report_id);
            $stmt->execute();
        }
    }

    /**
     * Get damage reports
     */
    public function getDamageReports($status = null, $limit = 10) {
        $query = "
            SELECT 
                edr.report_id,
                edr.member_id,
                edr.facility_id,
                edr.equipment_name,
                edr.damage_description,
                edr.ai_damage_class,
                edr.status,
                m.first_name,
                m.last_name,
                f.facility_name
            FROM equipment_damage_reports edr
            LEFT JOIN members m ON edr.member_id = m.member_id
            LEFT JOIN facilities f ON edr.facility_id = f.facility_id
        ";

        if ($status) {
            $query .= " WHERE edr.status = ?";
        }

        $query .= " ORDER BY edr.reported_at DESC LIMIT ?";

        $stmt = $this->db->prepare($query);
        
        if ($status) {
            $stmt->bind_param("si", $status, $limit);
        } else {
            $stmt->bind_param("i", $limit);
        }
        
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Schedule facility device activation
     */
    public function scheduleDeviceActivation($facility_id, $booking_id, $device_type, $device_name) {
        // Get booking times
        $booking = $this->getBookingDetails($booking_id);
        
        // Schedule device to turn on 5 minutes before booking
        $on_time = date('Y-m-d H:i:s', strtotime($booking['start_time'] . ' -5 minutes'));
        $off_time = date('Y-m-d H:i:s', strtotime($booking['end_time'] . ' +5 minutes'));

        $stmt = $this->db->prepare("
            INSERT INTO facility_energy_management 
            (facility_id, booking_id, device_type, device_name, scheduled_on_time, scheduled_off_time, status)
            VALUES (?, ?, ?, ?, ?, ?, 'scheduled')
        ");
        $stmt->bind_param("iissss", $facility_id, $booking_id, $device_type, $device_name, $on_time, $off_time);
        
        return $stmt->execute();
    }

    /**
     * Get energy consumption report
     */
    public function getEnergyConsumptionReport($facility_id, $start_date, $end_date) {
        $stmt = $this->db->prepare("
            SELECT 
                device_type,
                COUNT(*) as activations,
                SUM(energy_consumed_kwh) as total_kwh,
                AVG(energy_consumed_kwh) as avg_kwh
            FROM facility_energy_management
            WHERE facility_id = ? 
            AND status = 'completed'
            AND actual_on_time >= ? 
            AND actual_off_time <= ?
            GROUP BY device_type
        ");
        $stmt->bind_param("iss", $facility_id, $start_date, $end_date);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}

?>
