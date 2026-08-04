<?php
/**
 * Parent Portal & Compliance Management
 * Handles parent accounts, medical waivers, and coach certifications
 */

class ParentPortalCompliance {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Create parent account
     */
    public function createParentAccount($email, $password, $first_name, $last_name, $phone = null) {
        // Check if email exists
        $check = $this->db->prepare("SELECT parent_id FROM parent_accounts WHERE email = ?");
        $check->bind_param("s", $email);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Email already registered'];
        }

        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $this->db->prepare("
            INSERT INTO parent_accounts 
            (email, password_hash, first_name, last_name, phone_number, status)
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        $stmt->bind_param("sssss", $email, $password_hash, $first_name, $last_name, $phone);
        
        if ($stmt->execute()) {
            return ['success' => true, 'parent_id' => $this->db->insert_id];
        }
        return ['success' => false, 'message' => 'Failed to create account'];
    }

    /**
     * Link child to parent
     */
    public function linkChildToParent($parent_id, $child_member_id, $relationship_type = 'parent') {
        $stmt = $this->db->prepare("
            INSERT INTO parent_child_relationships 
            (parent_id, child_member_id, relationship_type, verified)
            VALUES (?, ?, ?, FALSE)
        ");
        $stmt->bind_param("iis", $parent_id, $child_member_id, $relationship_type);
        
        if ($stmt->execute()) {
            // Send verification email
            $this->sendVerificationEmail($parent_id, $child_member_id);
            return true;
        }
        return false;
    }

    /**
     * Send verification email
     */
    private function sendVerificationEmail($parent_id, $child_member_id) {
        // Get parent and child details
        $parent = $this->getParentDetails($parent_id);
        $child = $this->getChildDetails($child_member_id);

        $verification_link = "https://" . $_SERVER['HTTP_HOST'] . "/verify_parent_link.php?parent_id=" . $parent_id . "&child_id=" . $child_member_id;
        
        $subject = "Verify your child's account at Apex Sports Club";
        $message = "Hello " . $parent['first_name'] . ",\n\n";
        $message .= "Please verify that " . $child['first_name'] . " " . $child['last_name'] . " is your child.\n";
        $message .= "Click here to verify: " . $verification_link . "\n\n";
        $message .= "Best regards,\nApex Sports Club";

        // Send email (implement with your email service)
        error_log("Verification email to: " . $parent['email']);
    }

    /**
     * Get parent details
     */
    private function getParentDetails($parent_id) {
        $stmt = $this->db->prepare("SELECT * FROM parent_accounts WHERE parent_id = ?");
        $stmt->bind_param("i", $parent_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get child details
     */
    private function getChildDetails($child_member_id) {
        $stmt = $this->db->prepare("SELECT * FROM members WHERE member_id = ?");
        $stmt->bind_param("i", $child_member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get parent's children
     */
    public function getParentChildren($parent_id) {
        $stmt = $this->db->prepare("
            SELECT 
                m.member_id,
                m.first_name,
                m.last_name,
                m.email,
                m.membership_status,
                pcr.relationship_type,
                pcr.verified
            FROM members m
            JOIN parent_child_relationships pcr ON m.member_id = pcr.child_member_id
            WHERE pcr.parent_id = ?
            ORDER BY m.first_name ASC
        ");
        $stmt->bind_param("i", $parent_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Upload medical waiver
     */
    public function uploadMedicalWaiver($member_id, $parent_id, $waiver_type, $document_url) {
        $signed_date = date('Y-m-d');
        $expiry_date = date('Y-m-d', strtotime('+1 year'));

        $stmt = $this->db->prepare("
            INSERT INTO medical_waivers 
            (member_id, parent_id, waiver_type, waiver_document_url, signed_date, expiry_date, status)
            VALUES (?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmt->bind_param("iissss", $member_id, $parent_id, $waiver_type, $document_url, $signed_date, $expiry_date);
        
        return $stmt->execute();
    }

    /**
     * Get member's medical waivers
     */
    public function getMemberWaivers($member_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM medical_waivers 
            WHERE member_id = ? 
            ORDER BY signed_date DESC
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Add authorized pickup person
     */
    public function addAuthorizedPickup($child_member_id, $person_name, $phone, $relationship, $id_type = null) {
        $stmt = $this->db->prepare("
            INSERT INTO authorized_pickups 
            (child_member_id, authorized_person_name, authorized_person_phone, relationship, id_document_type, status)
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        $stmt->bind_param("issss", $child_member_id, $person_name, $phone, $relationship, $id_type);
        
        return $stmt->execute();
    }

    /**
     * Get authorized pickups for child
     */
    public function getAuthorizedPickups($child_member_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM authorized_pickups 
            WHERE child_member_id = ? AND status = 'active'
            ORDER BY created_at ASC
        ");
        $stmt->bind_param("i", $child_member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Add coach certification
     */
    public function addCoachCertification($coach_id, $cert_type, $issued_date, $expiry_date, $cert_url = null, $issuing_body = null) {
        $stmt = $this->db->prepare("
            INSERT INTO coach_certifications 
            (coach_id, certification_type, issued_date, expiry_date, certificate_url, issuing_body, status)
            VALUES (?, ?, ?, ?, ?, ?, 'valid')
        ");
        $stmt->bind_param("isssss", $coach_id, $cert_type, $issued_date, $expiry_date, $cert_url, $issuing_body);
        
        if ($stmt->execute()) {
            // Run compliance audit
            $this->runComplianceAudit($coach_id);
            return true;
        }
        return false;
    }

    /**
     * Get coach certifications
     */
    public function getCoachCertifications($coach_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM coach_certifications 
            WHERE coach_id = ?
            ORDER BY expiry_date ASC
        ");
        $stmt->bind_param("i", $coach_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Check if coach is compliant
     */
    public function isCoachCompliant($coach_id) {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as expired_count FROM coach_certifications 
            WHERE coach_id = ? AND expiry_date < CURDATE() AND status = 'valid'
        ");
        $stmt->bind_param("i", $coach_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        
        return $result['expired_count'] == 0;
    }

    /**
     * Run compliance audit
     */
    public function runComplianceAudit($coach_id) {
        // Check certifications
        $stmt = $this->db->prepare("
            SELECT COUNT(*) as expired_count FROM coach_certifications 
            WHERE coach_id = ? AND expiry_date < CURDATE()
        ");
        $stmt->bind_param("i", $coach_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        $status = $result['expired_count'] == 0 ? 'compliant' : 'non_compliant';
        $details = $result['expired_count'] > 0 ? 
                   $result['expired_count'] . " expired certification(s)" : 
                   "All certifications valid";

        $stmt = $this->db->prepare("
            INSERT INTO compliance_audit_log 
            (coach_id, audit_type, status, details)
            VALUES (?, 'certification_check', ?, ?)
        ");
        $stmt->bind_param("iss", $coach_id, $status, $details);
        $stmt->execute();

        // If non-compliant, suspend coaching privileges
        if ($status == 'non_compliant') {
            $this->suspendCoachingPrivileges($coach_id);
        }
    }

    /**
     * Suspend coaching privileges
     */
    private function suspendCoachingPrivileges($coach_id) {
        // Update member status or add flag
        $stmt = $this->db->prepare("
            UPDATE members 
            SET coaching_suspended = TRUE
            WHERE member_id = ?
        ");
        $stmt->bind_param("i", $coach_id);
        $stmt->execute();
    }

    /**
     * Get compliance report
     */
    public function getComplianceReport() {
        $stmt = $this->db->prepare("
            SELECT 
                m.member_id,
                m.first_name,
                m.last_name,
                m.role,
                COUNT(cc.certification_id) as total_certs,
                COUNT(CASE WHEN cc.expiry_date < CURDATE() THEN 1 END) as expired_certs,
                MAX(cc.expiry_date) as next_expiry,
                CASE WHEN COUNT(CASE WHEN cc.expiry_date < CURDATE() THEN 1 END) > 0 THEN 'Non-Compliant' ELSE 'Compliant' END as status
            FROM members m
            LEFT JOIN coach_certifications cc ON m.member_id = cc.coach_id
            WHERE m.role IN ('coach', 'referee', 'groundskeeper')
            GROUP BY m.member_id
            ORDER BY status DESC, next_expiry ASC
        ");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Send compliance reminder
     */
    public function sendComplianceReminders() {
        // Get coaches with expiring certifications (within 30 days)
        $stmt = $this->db->prepare("
            SELECT DISTINCT m.member_id, m.email, m.first_name, cc.certification_type, cc.expiry_date
            FROM members m
            JOIN coach_certifications cc ON m.member_id = cc.coach_id
            WHERE cc.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            AND cc.status = 'valid'
        ");
        $stmt->execute();
        $coaches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($coaches as $coach) {
            $this->sendReminderEmail($coach);
        }
    }

    /**
     * Send reminder email
     */
    private function sendReminderEmail($coach) {
        $subject = "Certification Renewal Reminder - Apex Sports Club";
        $message = "Hello " . $coach['first_name'] . ",\n\n";
        $message .= "Your " . $coach['certification_type'] . " certification expires on " . $coach['expiry_date'] . ".\n";
        $message .= "Please renew it before the expiry date to maintain your coaching privileges.\n\n";
        $message .= "Best regards,\nApex Sports Club";

        error_log("Reminder email to: " . $coach['email']);
    }
}

?>
