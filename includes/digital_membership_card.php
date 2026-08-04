<?php
/**
 * Digital Membership Card Helper
 * Handles QR code generation and membership card management
 */

// Vendor autoload is not available in this install, so use a lightweight QR code image URL instead.
// If you install composer packages later, restore the chillerlan QR code implementation here.

class DigitalMembershipCard {
    private $db;
    private $member_id;

    public function __construct($db, $member_id) {
        $this->db = $db;
        $this->member_id = $member_id;
    }

    /**
     * Generate or retrieve digital membership card
     */
    public function getOrCreateCard() {
        // Check if card already exists
        $stmt = $this->db->prepare("
            SELECT * FROM digital_membership_cards 
            WHERE member_id = ? AND card_status = 'active'
        ");
        $stmt->bind_param("i", $this->member_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }

        // Create new card
        return $this->createNewCard();
    }

    /**
     * Create a new digital membership card
     */
    private function createNewCard() {
        $card_number = $this->generateCardNumber();
        $issued_date = date('Y-m-d');
        $expiry_date = date('Y-m-d', strtotime('+1 year'));
        
        // Generate QR code
        $qr_data = json_encode([
            'member_id' => $this->member_id,
            'card_number' => $card_number,
            'issued_date' => $issued_date,
            'expiry_date' => $expiry_date
        ]);
        
        $qr_code = $this->generateQRCode($qr_data);

        // Insert into database
        $stmt = $this->db->prepare("
            INSERT INTO digital_membership_cards 
            (member_id, card_number, qr_code, issued_date, expiry_date, card_status)
            VALUES (?, ?, ?, ?, ?, 'active')
        ");
        $stmt->bind_param("issss", $this->member_id, $card_number, $qr_code, $issued_date, $expiry_date);
        
        if ($stmt->execute()) {
            return $this->getOrCreateCard();
        }
        
        return null;
    }

    /**
     * Generate a unique card number
     */
    private function generateCardNumber() {
        $prefix = 'APEX';
        $timestamp = date('Ymd');
        $random = strtoupper(substr(uniqid(), -6));
        return $prefix . $timestamp . $random;
    }

    /**
     * Generate QR code as base64 encoded image
     */
    private function generateQRCode($data) {
        // Use Google Chart API to generate a QR code image without requiring composer/vendor.
        $params = http_build_query([
            'cht'  => 'qr',
            'chs'  => '300x300',
            'chl'  => $data,
            'chld' => 'L|1',
        ]);

        return 'https://chart.googleapis.com/chart?' . $params;
    }

    /**
     * Record a card scan
     */
    public function recordScan() {
        $stmt = $this->db->prepare("
            UPDATE digital_membership_cards 
            SET last_scanned = NOW(), scan_count = scan_count + 1
            WHERE member_id = ? AND card_status = 'active'
        ");
        $stmt->bind_param("i", $this->member_id);
        return $stmt->execute();
    }

    /**
     * Verify card validity
     */
    public function verifyCard($card_number) {
        $stmt = $this->db->prepare("
            SELECT * FROM digital_membership_cards 
            WHERE card_number = ? AND card_status = 'active' AND expiry_date >= CURDATE()
        ");
        $stmt->bind_param("s", $card_number);
        $stmt->execute();
        $result = $stmt->get_result();
        
        return $result->num_rows > 0 ? $result->fetch_assoc() : null;
    }

    /**
     * Get card statistics
     */
    public function getCardStats() {
        $stmt = $this->db->prepare("
            SELECT 
                card_number,
                scan_count,
                last_scanned,
                issued_date,
                expiry_date,
                DATEDIFF(expiry_date, CURDATE()) as days_until_expiry
            FROM digital_membership_cards 
            WHERE member_id = ? AND card_status = 'active'
        ");
        $stmt->bind_param("i", $this->member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}

?>
