<?php
/**
 * AI Insights Engine
 * Generates automated match insights, player of match, and social media content
 */

class AIInsightsEngine {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    /**
     * Generate AI insights for a completed fixture
     */
    public function generateMatchInsights($fixture_id) {
        // Get fixture details
        $fixture = $this->getFixtureDetails($fixture_id);
        if (!$fixture) return false;

        // Get match report and MOTM votes
        $match_report = $this->getMatchReport($fixture_id);
        $motm_votes = $this->getMotmVotes($fixture_id);
        $player_of_match = $this->determinePlayerOfMatch($motm_votes);

        // Generate team of week (simplified logic)
        $team_of_week = $this->generateTeamOfWeek($fixture_id);

        // Generate key moments
        $key_moments = $this->extractKeyMoments($match_report);

        // Generate performance summary
        $performance_summary = $this->generatePerformanceSummary($fixture, $match_report, $player_of_match);

        // Generate social media caption
        $social_caption = $this->generateSocialCaption($fixture, $player_of_match, $performance_summary);

        // Store insights
        $stmt = $this->db->prepare("
            INSERT INTO ai_match_insights 
            (fixture_id, player_of_match, team_of_week, key_moments, performance_summary, social_media_caption)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            player_of_match = ?, team_of_week = ?, key_moments = ?, performance_summary = ?, social_media_caption = ?
        ");

        $team_of_week_json = json_encode($team_of_week);
        $key_moments_json = json_encode($key_moments);

        $stmt->bind_param(
            "isssssisss",
            $fixture_id,
            $player_of_match,
            $team_of_week_json,
            $key_moments_json,
            $performance_summary,
            $social_caption,
            $player_of_match,
            $team_of_week_json,
            $key_moments_json,
            $performance_summary,
            $social_caption
        );

        if ($stmt->execute()) {
            // Create social media posts
            $this->createSocialMediaPosts($fixture_id, $social_caption);
            return true;
        }
        return false;
    }

    /**
     * Get fixture details
     */
    private function getFixtureDetails($fixture_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM fixtures WHERE fixture_id = ?
        ");
        $stmt->bind_param("i", $fixture_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get match report
     */
    private function getMatchReport($fixture_id) {
        $stmt = $this->db->prepare("
            SELECT * FROM match_reports WHERE fixture_id = ? ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->bind_param("i", $fixture_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get MOTM votes
     */
    private function getMotmVotes($fixture_id) {
        $stmt = $this->db->prepare("
            SELECT player_id, COUNT(*) as vote_count 
            FROM motm_votes 
            WHERE fixture_id = ? 
            GROUP BY player_id 
            ORDER BY vote_count DESC
        ");
        $stmt->bind_param("i", $fixture_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Determine player of match
     */
    private function determinePlayerOfMatch($motm_votes) {
        if (empty($motm_votes)) return null;
        return $motm_votes[0]['player_id'];
    }

    /**
     * Generate team of week (simplified)
     */
    private function generateTeamOfWeek($fixture_id) {
        $stmt = $this->db->prepare("
            SELECT TOP 11 m.member_id, m.first_name, m.last_name, m.position
            FROM match_reports mr
            JOIN members m ON mr.fixture_id = ?
            WHERE mr.rating >= 7
            ORDER BY mr.rating DESC
        ");
        $stmt->bind_param("i", $fixture_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Extract key moments from match report
     */
    private function extractKeyMoments($match_report) {
        $moments = [];
        if ($match_report && isset($match_report['goals_scored'])) {
            $moments[] = "Goals: " . $match_report['goals_scored'];
        }
        if ($match_report && isset($match_report['red_cards'])) {
            $moments[] = "Red Cards: " . $match_report['red_cards'];
        }
        if ($match_report && isset($match_report['yellow_cards'])) {
            $moments[] = "Yellow Cards: " . $match_report['yellow_cards'];
        }
        return $moments;
    }

    /**
     * Generate performance summary
     */
    private function generatePerformanceSummary($fixture, $match_report, $player_of_match) {
        $summary = "Match between " . $fixture['home_team'] . " and " . $fixture['away_team'] . ". ";
        
        if ($match_report) {
            $summary .= "Final score: " . ($match_report['home_goals'] ?? 0) . " - " . ($match_report['away_goals'] ?? 0) . ". ";
        }
        
        if ($player_of_match) {
            $stmt = $this->db->prepare("SELECT first_name, last_name FROM members WHERE member_id = ?");
            $stmt->bind_param("i", $player_of_match);
            $stmt->execute();
            $player = $stmt->get_result()->fetch_assoc();
            if ($player) {
                $summary .= "Player of the Match: " . $player['first_name'] . " " . $player['last_name'] . ".";
            }
        }
        
        return $summary;
    }

    /**
     * Generate social media caption
     */
    private function generateSocialCaption($fixture, $player_of_match, $performance_summary) {
        $caption = "🏆 MATCH RECAP 🏆\n\n";
        $caption .= $performance_summary . "\n\n";
        $caption .= "📸 Check out the full match report on our website!\n";
        $caption .= "#ApexSportsClub #MatchDay #Football";
        
        return $caption;
    }

    /**
     * Create social media posts
     */
    private function createSocialMediaPosts($fixture_id, $caption) {
        $platforms = ['instagram', 'facebook', 'twitter'];
        
        foreach ($platforms as $platform) {
            $stmt = $this->db->prepare("
                INSERT INTO social_media_posts 
                (fixture_id, platform, content, status)
                VALUES (?, ?, ?, 'draft')
            ");
            $stmt->bind_param("iss", $fixture_id, $platform, $caption);
            $stmt->execute();
        }
    }

    /**
     * Schedule social media post
     */
    public function schedulePost($post_id, $scheduled_time) {
        $stmt = $this->db->prepare("
            UPDATE social_media_posts 
            SET status = 'scheduled', scheduled_at = ?
            WHERE post_id = ?
        ");
        $stmt->bind_param("si", $scheduled_time, $post_id);
        return $stmt->execute();
    }

    /**
     * Get draft posts
     */
    public function getDraftPosts($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT 
                sp.post_id,
                sp.fixture_id,
                sp.platform,
                sp.content,
                sp.status,
                f.home_team,
                f.away_team,
                f.match_date
            FROM social_media_posts sp
            LEFT JOIN fixtures f ON sp.fixture_id = f.fixture_id
            WHERE sp.status IN ('draft', 'scheduled')
            ORDER BY sp.created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Track social media engagement
     */
    public function recordEngagement($post_id, $engagement_count) {
        $stmt = $this->db->prepare("
            UPDATE social_media_posts 
            SET engagement_count = ?, status = 'published', published_at = NOW()
            WHERE post_id = ?
        ");
        $stmt->bind_param("ii", $engagement_count, $post_id);
        return $stmt->execute();
    }
}

?>
