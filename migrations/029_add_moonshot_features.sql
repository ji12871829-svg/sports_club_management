-- Migration 029: Add Moonshot Features (Wearable, AR, DAO, Green Tracker, Highlights)

-- ============================================
-- WEARABLE INTEGRATION TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS wearable_devices (
    device_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    device_type ENUM('apple_health', 'garmin', 'fitbit', 'strava', 'whoop') NOT NULL,
    device_name VARCHAR(255),
    api_token VARCHAR(500),
    is_active BOOLEAN DEFAULT TRUE,
    last_sync TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id),
    UNIQUE KEY unique_device (member_id, device_type)
);

CREATE TABLE IF NOT EXISTS fitness_activities (
    activity_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    device_id INT,
    activity_type ENUM('running', 'cycling', 'swimming', 'gym', 'team_sport', 'yoga', 'other') NOT NULL,
    activity_date DATE NOT NULL,
    duration_minutes INT,
    distance_km DECIMAL(10, 2),
    calories_burned INT,
    heart_rate_avg INT,
    heart_rate_max INT,
    intensity_level ENUM('low', 'moderate', 'high', 'very_high') DEFAULT 'moderate',
    external_activity_id VARCHAR(255),
    synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id),
    FOREIGN KEY (device_id) REFERENCES wearable_devices(device_id),
    INDEX idx_member_date (member_id, activity_date)
);

CREATE TABLE IF NOT EXISTS fitness_leaderboards (
    leaderboard_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    leaderboard_type ENUM('weekly_steps', 'monthly_calories', 'total_distance', 'weekly_workouts') NOT NULL,
    rank INT,
    score DECIMAL(10, 2),
    period_start DATE,
    period_end DATE,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id),
    UNIQUE KEY unique_leaderboard (member_id, leaderboard_type, period_start)
);

-- ============================================
-- AR MATCH DAY OVERLAY TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS ar_match_events (
    ar_event_id INT PRIMARY KEY AUTO_INCREMENT,
    fixture_id INT NOT NULL,
    event_type ENUM('goal', 'save', 'tackle', 'pass_accuracy', 'yellow_card', 'red_card') NOT NULL,
    event_time_seconds INT,
    player_id INT,
    player_name VARCHAR(255),
    player_number INT,
    team_id INT,
    x_coordinate DECIMAL(5, 2),
    y_coordinate DECIMAL(5, 2),
    video_timestamp VARCHAR(10),
    ar_asset_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (fixture_id) REFERENCES fixtures(fixture_id),
    INDEX idx_fixture (fixture_id)
);

CREATE TABLE IF NOT EXISTS ar_viewer_analytics (
    viewer_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    ar_event_id INT,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    view_duration_seconds INT,
    interaction_type ENUM('view', 'tap', 'share', 'bookmark') DEFAULT 'view',
    FOREIGN KEY (member_id) REFERENCES members(member_id),
    FOREIGN KEY (ar_event_id) REFERENCES ar_match_events(ar_event_id)
);

-- ============================================
-- DAO-LITE GOVERNANCE TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS club_tokens (
    token_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    token_balance INT DEFAULT 0,
    token_earned_from ENUM('membership_years', 'volunteer_hours', 'sponsorship', 'loyalty_points') NOT NULL,
    earned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id),
    UNIQUE KEY unique_member_token (member_id)
);

CREATE TABLE IF NOT EXISTS governance_proposals (
    proposal_id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    description LONGTEXT,
    proposal_type ENUM('kit_design', 'charity_partner', 'facility_upgrade', 'policy_change', 'event_planning') NOT NULL,
    proposer_id INT NOT NULL,
    status ENUM('draft', 'active', 'closed', 'approved', 'rejected') DEFAULT 'draft',
    start_date TIMESTAMP NULL DEFAULT NULL,
    end_date TIMESTAMP NULL DEFAULT NULL,
    total_votes INT DEFAULT 0,
    votes_for INT DEFAULT 0,
    votes_against INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposer_id) REFERENCES members(member_id)
);

CREATE TABLE IF NOT EXISTS governance_votes (
    vote_id INT PRIMARY KEY AUTO_INCREMENT,
    proposal_id INT NOT NULL,
    member_id INT NOT NULL,
    tokens_used INT,
    vote_choice ENUM('for', 'against', 'abstain') NOT NULL,
    voted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (proposal_id) REFERENCES governance_proposals(proposal_id),
    FOREIGN KEY (member_id) REFERENCES members(member_id),
    UNIQUE KEY unique_vote (proposal_id, member_id)
);

-- ============================================
-- GREEN GOAL TRACKER TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS eco_activities (
    eco_activity_id INT PRIMARY KEY AUTO_INCREMENT,
    member_id INT NOT NULL,
    activity_type ENUM('carpool', 'public_transport', 'bike', 'reusable_bottle', 'waste_reduction', 'tree_planting') NOT NULL,
    activity_date DATE NOT NULL,
    co2_saved_kg DECIMAL(10, 2),
    eco_credits_earned INT,
    description VARCHAR(500),
    verified BOOLEAN DEFAULT FALSE,
    verified_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (member_id) REFERENCES members(member_id),
    FOREIGN KEY (verified_by) REFERENCES members(member_id)
);

CREATE TABLE IF NOT EXISTS club_carbon_footprint (
    footprint_id INT PRIMARY KEY AUTO_INCREMENT,
    month_year DATE NOT NULL,
    total_co2_kg DECIMAL(12, 2),
    energy_usage_kwh DECIMAL(10, 2),
    water_usage_liters DECIMAL(10, 2),
    waste_generated_kg DECIMAL(10, 2),
    member_travel_co2 DECIMAL(10, 2),
    offset_by_eco_activities DECIMAL(10, 2),
    net_carbon_kg DECIMAL(12, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_month (month_year)
);

-- ============================================
-- AUTOMATED HIGHLIGHT REELS TABLES
-- ============================================

CREATE TABLE IF NOT EXISTS match_footage_sources (
    footage_id INT PRIMARY KEY AUTO_INCREMENT,
    fixture_id INT NOT NULL,
    camera_provider ENUM('veo', 'pixellot', 'manual_upload') NOT NULL,
    video_url VARCHAR(500),
    video_duration_seconds INT,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processing_status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
    FOREIGN KEY (fixture_id) REFERENCES fixtures(fixture_id),
    UNIQUE KEY unique_fixture_footage (fixture_id, camera_provider)
);

CREATE TABLE IF NOT EXISTS highlight_reels (
    reel_id INT PRIMARY KEY AUTO_INCREMENT,
    fixture_id INT NOT NULL,
    footage_id INT,
    reel_type ENUM('goals', 'saves', 'tackles', 'full_match', 'best_plays', 'player_highlights') NOT NULL,
    title VARCHAR(255),
    description LONGTEXT,
    video_url VARCHAR(500),
    duration_seconds INT,
    ai_generated BOOLEAN DEFAULT TRUE,
    generation_confidence DECIMAL(3, 2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    published_at TIMESTAMP NULL DEFAULT NULL,
    views INT DEFAULT 0,
    shares INT DEFAULT 0,
    FOREIGN KEY (fixture_id) REFERENCES fixtures(fixture_id),
    FOREIGN KEY (footage_id) REFERENCES match_footage_sources(footage_id)
);

CREATE TABLE IF NOT EXISTS reel_distribution (
    distribution_id INT PRIMARY KEY AUTO_INCREMENT,
    reel_id INT NOT NULL,
    platform ENUM('tiktok', 'instagram_reels', 'youtube_shorts', 'facebook', 'twitter') NOT NULL,
    platform_post_id VARCHAR(255),
    posted_at TIMESTAMP NULL DEFAULT NULL,
    engagement_count INT DEFAULT 0,
    reach INT DEFAULT 0,
    FOREIGN KEY (reel_id) REFERENCES highlight_reels(reel_id),
    UNIQUE KEY unique_reel_platform (reel_id, platform)
);

-- ============================================
-- INDEXES FOR PERFORMANCE
-- ============================================

CREATE INDEX idx_fitness_member ON fitness_activities(member_id);
CREATE INDEX idx_fitness_date ON fitness_activities(activity_date);
CREATE INDEX idx_eco_member ON eco_activities(member_id);
CREATE INDEX idx_eco_date ON eco_activities(activity_date);
CREATE INDEX idx_governance_status ON governance_proposals(status);
CREATE INDEX idx_highlight_fixture ON highlight_reels(fixture_id);
