<?php
/**
 * public/member_profile.php
 * Public member profile showing stats, teams, upcoming fixtures, and badges.
 */
include_once '../includes/header.php';
require_once '../config/db_connect.php';
require_once '../includes/achievements.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int)($_GET['id'] ?? 0);
if ($member_id <= 0) { header('Location: index.php'); exit; }

// Member details
$stmt = $conn->prepare("
    SELECT m.member_id, m.first_name, m.last_name, m.email, m.date_joined,
           mm.end_date AS membership_end, mp.name AS plan_name
    FROM members m
    LEFT JOIN member_memberships mm ON mm.member_id = m.member_id AND mm.status = 'Active'
    LEFT JOIN membership_plans mp ON mp.plan_id = mm.plan_id
    WHERE m.member_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$member) { header('Location: index.php'); exit; }

// Teams (Refactored into parameterized prepared statement to block potential SQL Injection)
$stmt = $conn->prepare("
    SELECT t.name AS team_name, l.name AS league_name
    FROM team_memberships tm
    JOIN teams t ON t.team_id = tm.team_id
    JOIN leagues l ON l.league_id = t.league_id
    WHERE tm.member_id = ?
    ORDER BY l.name ASC
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$teams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Season stats (Refactored into parameterized prepared statement to block potential SQL Injection)
$stmt = $conn->prepare("
    SELECT
        COUNT(CASE WHEN me.event_type IN ('goal','penalty') THEN 1 END) AS goals,
        COUNT(CASE WHEN me.event_type = 'own_goal'          THEN 1 END) AS own_goals,
        COUNT(CASE WHEN me.event_type = 'yellow_card'       THEN 1 END) AS yellow_cards,
        COUNT(CASE WHEN me.event_type = 'red_card'          THEN 1 END) AS red_cards,
        COUNT(DISTINCT me.fixture_id)                                   AS appearances
    FROM match_events me
    WHERE me.member_id = ?
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Recent match events (Refactored into parameterized prepared statement to block potential SQL Injection)
$stmt = $conn->prepare("
    SELECT me.event_type, me.minute, me.created_at,
           h.name AS home_team, a.name AS away_team,
           f.home_score, f.away_score, f.match_date, f.fixture_id
    FROM match_events me
    JOIN fixtures f ON f.fixture_id = me.fixture_id
    JOIN teams h ON h.team_id = f.home_team_id
    JOIN teams a ON a.team_id = f.away_team_id
    WHERE me.member_id = ?
    ORDER BY f.match_date DESC
    LIMIT 10
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$recent_events = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// MOTM wins (Refactored into parameterized prepared statement to block potential SQL Injection)
$stmt = $conn->prepare("
    SELECT COUNT(*) FROM motm_votes
    WHERE member_id = ?
    GROUP BY member_id
");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$motm_result = $stmt->get_result()->fetch_row();
$motm_count = $motm_result[0] ?? 0;
$stmt->close();

// Upcoming fixtures (Refactored into parameterized prepared statement to block potential SQL Injection)
$stmt = $conn->prepare("
    SELECT f.fixture_id, f.match_date, f.match_time,
           h.name AS home_team, a.name AS away_team, l.name AS league_name
    FROM fixtures f
    JOIN teams h ON h.team_id = f.home_team_id
    JOIN teams a ON a.team_id = f.away_team_id
    JOIN leagues l ON l.league_id = f.league_id
    WHERE (f.home_team_id IN (SELECT team_id FROM team_memberships WHERE member_id = ?)
        OR f.away_team_id IN (SELECT team_id FROM team_memberships WHERE member_id = ?))
      AND f.match_date >= CURDATE()
      AND f.status = 'Scheduled'
    ORDER BY f.match_date ASC
    LIMIT 5
");
$stmt->bind_param("ii", $member_id, $member_id);
$stmt->execute();
$upcoming = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$awardedBadges = asc_member_achievements($conn, $member_id);
$conn->close();

$name = $member['first_name'] . ' ' . $member['last_name'];
$initials = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));
?>

<style>
    body {
        background-color: #f8fafc !important;
        color: #334155 !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    
    .profile-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
    }

    .profile-avatar {
        width: 80px;
        height: 80px;
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        color: #ffffff;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .tier-badge-premium {
        background-color: #e8f1f8;
        color: #1d5c8f;
        font-weight: 700;
        font-size: 0.78rem;
        padding: 0.35rem 0.75rem;
        border-radius: 6px;
        border: 1px solid #bfdbfe;
        display: inline-block;
    }

    .custom-badge-pill {
        font-weight: 700;
        font-size: 0.78rem;
        padding: 0.4rem 0.75rem;
        border-radius: 6px;
        border: 1px solid rgba(0,0,0,0.03);
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .stat-metric-val {
        font-weight: 800;
        font-size: 1.5rem;
        letter-spacing: -0.5px;
    }
    .stat-metric-lbl {
        color: #64748b;
        font-size: 0.72rem;
        text-uppercase: uppercase;
        font-weight: 700;
        letter-spacing: 0.5px;
        margin-top: 2px;
    }

    .workspace-block-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }

    .workspace-block-header {
        background-color: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.25rem 1.5rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        align-items: center;
    }

    .list-item-frame {
        padding: 1.1rem 1.5rem;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .list-item-frame:last-child {
        border-bottom: none;
    }

    .fixture-team-name {
        font-weight: 600;
        color: #0f172a;
        font-size: 0.92rem;
    }
    .fixture-vs-divider {
        color: #94a3b8;
        font-size: 0.8rem;
        margin: 0 0.5rem;
        font-weight: 500;
    }

    .activity-log-icon {
        width: 38px;
        height: 38px;
        background-color: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        flex-shrink: 0;
    }
</style>

<div class="container py-5">
    <div class="row g-4">

        <div class="col-lg-4 col-md-5">
            <div class="card profile-card text-center p-4 mb-4">
                <div class="mx-auto mb-3 rounded-circle d-flex align-items-center justify-content-center fs-2 profile-avatar">
                    <?php echo e($initials); ?>
                </div>
                <h4 class="fw-bold mb-1 text-dark" style="letter-spacing: -0.5px;"><?php echo e($name); ?></h4>
                
                <?php if ($member['plan_name']): ?>
                    <div class="mb-2">
                        <span class="tier-badge-premium text-uppercase"><?php echo e($member['plan_name']); ?> Tier</span>
                    </div>
                <?php endif; ?>
                
                <p class="text-muted small mb-4">
                    Registry context initialized <?php echo e(date('F Y', strtotime($member['date_joined']))); ?>
                </p>

                <?php if (!empty($awardedBadges)): ?>
                <div class="d-flex flex-wrap justify-content-center gap-2 mb-4 pt-2 border-top border-light">
                    <?php foreach ($awardedBadges as $badge): ?>
                        <span class="custom-badge-pill" style="background: #f8fafc; color: #334155;">
                            <span><?php echo e($badge['icon'] ?: '🏅'); ?></span>
                            <span><?php echo e($badge['name']); ?></span>
                        </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="row g-2 text-center pt-3 border-top" style="border-color: #f1f5f9 !important;">
                    <div class="col-4 border-end" style="border-color: #f1f5f9 !important;">
                        <div class="stat-metric-val text-success"><?php echo (int)($stats['goals'] ?? 0); ?></div>
                        <div class="stat-metric-lbl">Goals</div>
                    </div>
                    <div class="col-4 border-end" style="border-color: #f1f5f9 !important;">
                        <div class="stat-metric-val text-dark"><?php echo (int)($stats['appearances'] ?? 0); ?></div>
                        <div class="stat-metric-lbl">Apps</div>
                    </div>
                    <div class="col-4">
                        <div class="stat-metric-val style-motm" style="color: #ea580c;"><?php echo (int)$motm_count; ?></div>
                        <div class="stat-metric-lbl">MOTM</div>
                    </div>
                </div>
            </div>

            <?php if (!empty($teams)): ?>
            <div class="card workspace-block-card">
                <div class="workspace-block-header">
                    <i class="fas fa-users me-2 text-muted" style="font-size: 0.85rem;"></i> Active Squad Allocations
                </div>
                <div class="d-flex flex-column">
                    <?php foreach ($teams as $t): ?>
                    <div class="list-item-frame">
                        <span class="fw-semibold text-dark" style="font-size: 0.9rem;"><?php echo e($t['team_name']); ?></span>
                        <small class="badge bg-light text-secondary border px-2 py-1" style="font-size: 0.72rem; font-weight: 600;"><?php echo e($t['league_name']); ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-8 col-md-7">
            
            <?php if (!empty($upcoming)): ?>
            <div class="card workspace-block-card mb-4">
                <div class="workspace-block-header">
                    <i class="far fa-calendar-alt me-2 text-primary"></i> Scheduled Competitive Fixtures
                </div>
                <div class="d-flex flex-column">
                    <?php foreach ($upcoming as $f): ?>
                    <div class="list-item-frame">
                        <div>
                            <div class="d-flex align-items-center">
                                <span class="fixture-team-name"><?php echo e($f['home_team']); ?></span>
                                <span class="fixture-vs-divider">vs</span>
                                <span class="fixture-team-name"><?php echo e($f['away_team']); ?></span>
                            </div>
                            <small class="text-muted d-block mt-1" style="font-size: 0.78rem;"><i class="fas fa-trophy me-1" style="font-size:0.7rem;"></i> <?php echo e($f['league_name']); ?></small>
                        </div>
                        <div class="text-end">
                            <div class="fw-bold text-dark" style="font-size: 0.9rem;"><?php echo e(date('d M Y', strtotime($f['match_date']))); ?></div>
                            <small class="text-muted" style="font-size: 0.8rem; font-weight: 500;"><?php echo e(date('H:i', strtotime($f['match_time']))); ?> hrs</small>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($recent_events)): ?>
            <div class="card workspace-block-card">
                <div class="workspace-block-header">
                    <i class="fas fa-history me-2 text-success"></i> Verified Historic Performance Log
                </div>
                <div class="d-flex flex-column">
                    <?php foreach ($recent_events as $ev):
                        $icon = match($ev['event_type']) {
                            'goal','penalty' => '⚽',
                            'own_goal'       => '😬',
                            'yellow_card'    => '🟡',
                            'red_card'       => '🔴',
                            default          => '•'
                        };
                        $label = match($ev['event_type']) {
                            'goal'        => 'Goal Scored',
                            'penalty'     => 'Penalty Conversion',
                            'own_goal'    => 'Own Goal Conceded',
                            'yellow_card' => 'Cautionary Yellow Card',
                            'red_card'    => 'Ejection Red Card',
                            default       => ucwords(str_replace('_', ' ', $ev['event_type']))
                        };
                    ?>
                    <div class="list-item-frame justify-content-start gap-3">
                        <div class="activity-log-icon">
                            <?php echo $icon; ?>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-semibold text-dark" style="font-size: 0.92rem;"><?php echo e($label); ?></span>
                                <?php if ($ev['minute']): ?>
                                    <span class="badge bg-light text-dark border font-mono px-1.5 py-0.5" style="font-size:0.7rem; font-family: monospace;"><?php echo e($ev['minute']); ?>'</span>
                                <?php endif; ?>
                            </div>
                            <div class="text-muted small mt-0.5" style="font-size: 0.82rem;">
                                <?php echo e($ev['home_team']); ?> <b class="text-dark mx-0.5"><?php echo e($ev['home_score']); ?>–<?php echo e($ev['away_score']); ?></b> <?php echo e($ev['away_team']); ?>
                                <span class="mx-1 text-light">|</span>
                                <?php echo e(date('d M Y', strtotime($ev['match_date']))); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($recent_events) && empty($upcoming)): ?>
            <div class="text-center py-5 border rounded-3 bg-white shadow-sm">
                <i class="fas fa-database fa-2x mb-3 text-muted d-block" style="opacity: 0.4;"></i>
                <span class="text-muted small d-block">No contextual historical metric records or scheduled parameters linked to profile reference ID.</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>