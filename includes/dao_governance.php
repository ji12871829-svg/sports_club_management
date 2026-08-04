<?php
/**
 * DAO-Lite Governance System
 * Handles club tokens, voting, and community proposals
 */

class DAOGovernance {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Award tokens to member
     */
    public function awardTokens($member_id, $amount, $reason) {
        $valid_reasons = ['membership_years', 'volunteer_hours', 'sponsorship', 'loyalty_points'];
        if (!in_array($reason, $valid_reasons)) {
            return false;
        }

        $stmt = $this->db->prepare("
            INSERT INTO club_tokens (member_id, token_balance, token_earned_from)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
            token_balance = token_balance + ?
        ");
        $stmt->bind_param("iisi", $member_id, $amount, $reason, $amount);
        return $stmt->execute();
    }

    /**
     * Get member's token balance
     */
    public function getTokenBalance($member_id) {
        $stmt = $this->db->prepare("SELECT token_balance FROM club_tokens WHERE member_id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result['token_balance'] ?? 0;
    }

    /**
     * Create a governance proposal
     */
    public function createProposal($title, $description, $proposal_type, $proposer_id, $duration_days = 14) {
        $valid_types = ['kit_design', 'charity_partner', 'facility_upgrade', 'policy_change', 'event_planning'];
        if (!in_array($proposal_type, $valid_types)) {
            return ['success' => false, 'message' => 'Invalid proposal type'];
        }

        $start_date = date('Y-m-d H:i:s');
        $end_date = date('Y-m-d H:i:s', strtotime("+$duration_days days"));

        $stmt = $this->db->prepare("
            INSERT INTO governance_proposals 
            (title, description, proposal_type, proposer_id, status, start_date, end_date)
            VALUES (?, ?, ?, ?, 'active', ?, ?)
        ");
        $stmt->bind_param("sssiss", $title, $description, $proposal_type, $proposer_id, $start_date, $end_date);
        
        if ($stmt->execute()) {
            return ['success' => true, 'proposal_id' => $this->db->insert_id];
        }
        return ['success' => false, 'message' => 'Failed to create proposal'];
    }

    /**
     * Cast a vote on a proposal
     */
    public function castVote($proposal_id, $member_id, $vote_choice, $tokens_used = 1) {
        $valid_choices = ['for', 'against', 'abstain'];
        if (!in_array($vote_choice, $valid_choices)) {
            return ['success' => false, 'message' => 'Invalid vote choice'];
        }

        // Check if proposal is active
        $proposal = $this->getProposal($proposal_id);
        if ($proposal['status'] != 'active') {
            return ['success' => false, 'message' => 'Proposal is not active'];
        }

        // Check if voting period is still open
        if (strtotime($proposal['end_date']) < time()) {
            $this->closeProposal($proposal_id);
            return ['success' => false, 'message' => 'Voting period has ended'];
        }

        // Check if member already voted
        $check = $this->db->prepare("SELECT vote_id FROM governance_votes WHERE proposal_id = ? AND member_id = ?");
        $check->bind_param("ii", $proposal_id, $member_id);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'Member has already voted'];
        }

        // Check member's token balance
        $token_balance = $this->getTokenBalance($member_id);
        if ($token_balance < $tokens_used) {
            return ['success' => false, 'message' => 'Insufficient tokens'];
        }

        // Record vote
        $stmt = $this->db->prepare("
            INSERT INTO governance_votes 
            (proposal_id, member_id, tokens_used, vote_choice)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("iiii", $proposal_id, $member_id, $tokens_used, $vote_choice);
        
        if ($stmt->execute()) {
            // Deduct tokens
            $this->db->prepare("
                UPDATE club_tokens 
                SET token_balance = token_balance - ? 
                WHERE member_id = ?
            ")->bind_param("ii", $tokens_used, $member_id)->execute();

            // Update vote counts
            $this->updateVoteCounts($proposal_id);
            
            return ['success' => true, 'message' => 'Vote recorded'];
        }
        return ['success' => false, 'message' => 'Failed to record vote'];
    }

    /**
     * Update vote counts for a proposal
     */
    private function updateVoteCounts($proposal_id) {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_votes,
                SUM(CASE WHEN vote_choice = 'for' THEN 1 ELSE 0 END) as votes_for,
                SUM(CASE WHEN vote_choice = 'against' THEN 1 ELSE 0 END) as votes_against
            FROM governance_votes
            WHERE proposal_id = ?
        ");
        $stmt->bind_param("i", $proposal_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        $update = $this->db->prepare("
            UPDATE governance_proposals 
            SET total_votes = ?, votes_for = ?, votes_against = ?
            WHERE proposal_id = ?
        ");
        $update->bind_param("iiii", $result['total_votes'], $result['votes_for'], $result['votes_against'], $proposal_id);
        $update->execute();
    }

    /**
     * Get a proposal
     */
    public function getProposal($proposal_id) {
        $stmt = $this->db->prepare("
            SELECT 
                gp.*,
                m.first_name,
                m.last_name
            FROM governance_proposals gp
            JOIN members m ON gp.proposer_id = m.member_id
            WHERE gp.proposal_id = ?
        ");
        $stmt->bind_param("i", $proposal_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get active proposals
     */
    public function getActiveProposals($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT 
                gp.*,
                m.first_name,
                m.last_name,
                ROUND((gp.votes_for / GREATEST(gp.total_votes, 1)) * 100, 1) as approval_percentage,
                TIMESTAMPDIFF(HOUR, NOW(), gp.end_date) as hours_remaining
            FROM governance_proposals gp
            JOIN members m ON gp.proposer_id = m.member_id
            WHERE gp.status = 'active'
            ORDER BY gp.end_date ASC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Close a proposal
     */
    public function closeProposal($proposal_id) {
        $proposal = $this->getProposal($proposal_id);
        
        // Determine if approved (>50% votes for)
        $approval_rate = $proposal['total_votes'] > 0 ? 
                        ($proposal['votes_for'] / $proposal['total_votes']) : 0;
        
        $status = $approval_rate > 0.5 ? 'approved' : 'rejected';

        $stmt = $this->db->prepare("
            UPDATE governance_proposals 
            SET status = ?
            WHERE proposal_id = ?
        ");
        $stmt->bind_param("si", $status, $proposal_id);
        return $stmt->execute();
    }

    /**
     * Get member's voting history
     */
    public function getMemberVotes($member_id) {
        $stmt = $this->db->prepare("
            SELECT 
                gp.proposal_id,
                gp.title,
                gp.proposal_type,
                gv.vote_choice,
                gv.tokens_used,
                gv.voted_at
            FROM governance_votes gv
            JOIN governance_proposals gp ON gv.proposal_id = gp.proposal_id
            WHERE gv.member_id = ?
            ORDER BY gv.voted_at DESC
        ");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get proposal results
     */
    public function getProposalResults($proposal_id) {
        $proposal = $this->getProposal($proposal_id);
        
        return [
            'proposal_id' => $proposal['proposal_id'],
            'title' => $proposal['title'],
            'status' => $proposal['status'],
            'total_votes' => $proposal['total_votes'],
            'votes_for' => $proposal['votes_for'],
            'votes_against' => $proposal['votes_against'],
            'approval_percentage' => $proposal['total_votes'] > 0 ? 
                                    round(($proposal['votes_for'] / $proposal['total_votes']) * 100, 1) : 0,
            'approval_status' => $proposal['status'] == 'approved' ? 'APPROVED ✓' : 'REJECTED ✗'
        ];
    }

    /**
     * Auto-close expired proposals
     */
    public function closeExpiredProposals() {
        $stmt = $this->db->prepare("
            SELECT proposal_id FROM governance_proposals 
            WHERE status = 'active' AND end_date < NOW()
        ");
        $stmt->execute();
        $proposals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($proposals as $proposal) {
            $this->closeProposal($proposal['proposal_id']);
        }

        return count($proposals);
    }
}

?>
