<?php
/**
 * public/activity_feed.php
 * Club-wide live activity feed — goals, results, new members, bookings.
 * Auto-refreshes seamlessly via background fetch.
 */
include_once '../includes/header.php';
require_once '../config/db_connect.php';
require_once '../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

// Handle background requests cleanly without reloading the complete HTML shell
if (isset($_GET['ajax_render']) && $_GET['ajax_render'] === '1') {
    ob_clean();
    echo render_feed_items($conn);
    $conn->close();
    exit;
}

function render_feed_items($conn) {
    $feed = [];
    $now = time();

    // 1. Recent match results (Optimized SQL Projection)
    $results = $conn->query("
        SELECT 'result' AS type, f.fixture_id,
               CONCAT(h.name,' ',f.home_score,'-',f.away_score,' ',a.name) AS title,
               CONCAT('Full Time · ', l.name) AS subtitle,
               f.live_updated_at AS event_time,
               NULL AS member_id
        FROM fixtures f
        JOIN teams h ON h.team_id = f.home_team_id
        JOIN teams a ON a.team_id = f.away_team_id
        JOIN leagues l ON l.league_id = f.league_id
        WHERE f.status = 'Completed'
        ORDER BY f.live_updated_at DESC
        LIMIT 25
    ")->fetch_all(MYSQLI_ASSOC);
    foreach ($results as $r) {
        $feed[] = array_merge($r, ['icon'=>'🏁', 'color'=>'#10b981']);
    }

    // 1b. Admin-published activity feed items, including AI match reports
    if (db_table_exists($conn, 'activity_feed')) {
        $customRes = $conn->query("
            SELECT af.event_type AS type,
                   af.fixture_id,
                   af.title,
                   CASE
                       WHEN af.event_type = 'match_report' THEN CONCAT('Match Report - ', COALESCE(l.name, 'Club update'))
                       ELSE COALESCE(NULLIF(af.description, ''), 'Club update')
                   END AS subtitle,
                   CASE
                       WHEN af.event_type = 'match_report' THEN af.description
                       ELSE NULL
                   END AS body,
                   af.created_at AS event_time,
                   af.member_id,
                   af.link_url,
                   COALESCE(NULLIF(af.icon, ''), 'AI') AS icon,
                   COALESCE(NULLIF(af.color, ''), '#1d5c8f') AS color
            FROM activity_feed af
            LEFT JOIN fixtures f ON f.fixture_id = af.fixture_id
            LEFT JOIN leagues l ON l.league_id = f.league_id
            ORDER BY af.created_at DESC
            LIMIT 25
        ");
        if ($customRes) {
            foreach ($customRes->fetch_all(MYSQLI_ASSOC) as $row) {
                $feed[] = $row;
            }
            $customRes->free();
        }
    }

    // 2. Recent goals from match_events
    if (db_table_exists($conn, 'match_events')) {
        $goalsRes = $conn->query("
            SELECT 'goal' AS type, me.fixture_id,
                   CONCAT(COALESCE(CONCAT(m.first_name,' ',m.last_name), me.player_name), ' scored!') AS title,
                   CONCAT(h.name,' vs ',a.name,' · Minute ', COALESCE(me.minute,'?')) AS subtitle,
                   me.created_at AS event_time,
                   NULL AS member_id
            FROM match_events me
            JOIN fixtures f ON f.fixture_id = me.fixture_id
            JOIN teams h ON h.team_id = f.home_team_id
            JOIN teams a ON a.team_id = f.away_team_id
            LEFT JOIN members m ON m.member_id = me.member_id
            WHERE me.event_type IN ('goal','penalty')
            ORDER BY me.created_at DESC
            LIMIT 25
        ");
        if ($goalsRes) {
            foreach ($goalsRes->fetch_all(MYSQLI_ASSOC) as $g) {
                $feed[] = array_merge($g, ['icon' => '⚽', 'color' => '#f59e0b']);
            }
            $goalsRes->free();
        }
    }

    // 3. New members
    $new_members = $conn->query("
        SELECT 'new_member' AS type, 0 AS fixture_id,
               CONCAT(first_name,' ',last_name,' joined the club!') AS title,
               'New Member' AS subtitle,
               date_joined AS event_time,
               member_id
        FROM members
        ORDER BY date_joined DESC
        LIMIT 15
    ")->fetch_all(MYSQLI_ASSOC);
    foreach ($new_members as $nm) {
        $feed[] = array_merge($nm, ['icon'=>'👋', 'color'=>'#2a6ba8']);
    }

    // 4. MOTM leaders 
    if (db_table_exists($conn, 'motm_votes')) {
        $motmRes = $conn->query("
            SELECT 'motm' AS type, agg.fixture_id,
                   CONCAT(agg.player_name, ' — Man of the Match!') AS title,
                   CONCAT(h.name, ' vs ', a.name) AS subtitle,
                   agg.last_vote AS event_time,
                   NULL AS member_id
            FROM (
                SELECT fixture_id, player_name, COUNT(*) AS vote_count, MAX(created_at) AS last_vote
                FROM motm_votes
                GROUP BY fixture_id, player_name
            ) agg
            INNER JOIN (
                SELECT fixture_id, MAX(vote_count) AS max_votes
                FROM (
                    SELECT fixture_id, player_name, COUNT(*) AS vote_count
                    FROM motm_votes
                    GROUP BY fixture_id, player_name
                ) vote_totals
                GROUP BY fixture_id
            ) leaders ON leaders.fixture_id = agg.fixture_id AND leaders.max_votes = agg.vote_count
            JOIN fixtures f ON f.fixture_id = agg.fixture_id
            JOIN teams h ON h.team_id = f.home_team_id
            JOIN teams a ON a.team_id = f.away_team_id
            ORDER BY agg.last_vote DESC
            LIMIT 15
        ");
        if ($motmRes) {
            foreach ($motmRes->fetch_all(MYSQLI_ASSOC) as $row) {
                $feed[] = array_merge($row, ['icon' => '⭐', 'color' => '#eab308']);
            }
            $motmRes->free();
        }
    }

    // In-memory sorting (optimized calculation pipeline via timestamp caching)
    usort($feed, function($a, $b) {
        return strcmp($b['event_time'] ?? '', $a['event_time'] ?? '');
    });
    
    $feed = array_slice($feed, 0, 40);
    
    if (empty($feed)) {
        return '
        <div class="text-center py-5 text-muted border rounded-3 bg-white">
            <i class="fas fa-rss fa-2x mb-3 d-block text-secondary"></i>
            No community activity logged inside this system cluster yet.
        </div>';
    }

    ob_start();
    foreach ($feed as $item): 
        $ts = strtotime($item['event_time'] ?? '');
        if (!$ts) {
            $ts = $now;
        }
        $diff = $now - $ts;
        
        if ($diff < 60) {
            $time_string = 'Just now';
        } elseif ($diff < 3600) {
            $time_string = floor($diff/60) . 'm ago';
        } elseif ($diff < 86400) {
            $time_string = floor($diff/3600) . 'h ago';
        } else {
            $time_string = date('d M', $ts);
        }
        
        $has_fixture = (!empty($item['fixture_id']) && $item['fixture_id'] > 0);
        $has_member  = (!empty($item['member_id']) && $item['member_id'] > 0);
        $link_url = trim((string)($item['link_url'] ?? ''));
        $body = trim((string)($item['body'] ?? ''));
        ?>
        <div class="feed-card" style="border-left-color: <?php echo e($item['color']); ?>">
            <div class="d-flex align-items-center gap-3">
                <div class="feed-icon"><?php echo e($item['icon']); ?></div>
                <div class="flex-grow-1">
                    <div class="fw-semibold text-dark" style="font-size:.95rem;">
                        <?php if ($link_url !== ''): ?>
                            <a href="<?php echo e($link_url); ?>" class="text-decoration-none text-dark hover-accent">
                                <?php echo e($item['title']); ?>
                            </a>
                        <?php elseif ($has_fixture): ?>
                            <a href="fixture_detail.php?id=<?php echo (int)$item['fixture_id']; ?>" class="text-decoration-none text-dark hover-accent">
                                <?php echo e($item['title']); ?>
                            </a>
                        <?php elseif ($has_member): ?>
                            <a href="member_profile.php?id=<?php echo (int)$item['member_id']; ?>" class="text-decoration-none text-dark hover-accent">
                                <?php echo e($item['title']); ?>
                            </a>
                        <?php else: ?>
                            <?php echo e($item['title']); ?> <?php endif; ?>
                    </div>
                    <div class="text-muted small mt-0.5"><?php echo e($item['subtitle']); ?></div>
                    <?php if ($body !== ''): ?>
                        <div class="feed-body"><?php echo nl2br(e($body)); ?></div>
                    <?php endif; ?>
                </div>
                <div class="feed-time font-monospace text-uppercase fw-bold text-end">
                    <?php echo e($time_string); ?>
                </div>
            </div>
        </div>
    <?php 
    endforeach;
    return ob_get_clean();
}
?>

<style>
:root {
  --ui-border: #e2e8f0;
  --surface-bg: #ffffff;
  --workspace-bg: #f8fafc;
}
body { background-color: var(--workspace-bg) !important; }

.feed-card {
    border-left: 4px solid var(--ui-border);
    background: var(--surface-bg);
    border-radius: 4px 12px 12px 4px;
    padding: 16px 20px;
    margin-bottom: 14px;
    border-top: 1px solid #f1f5f9;
    border-right: 1px solid #f1f5f9;
    border-bottom: 1px solid #f1f5f9;
    box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.02);
    transition: transform .2s ease, box-shadow .2s ease;
}
.feed-card:hover { 
    transform: translateX(4px); 
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}
.hover-accent:hover { color: #1d5c8f !important; text-decoration: underline !important; }
.feed-icon { font-size: 1.35rem; min-width: 40px; text-align: center; }
.feed-time { font-size: .7rem; color: #94a3b8; letter-spacing: 0.5px; }
.feed-body {
    color: #475569;
    font-size: .9rem;
    line-height: 1.55;
    margin-top: .65rem;
    white-space: normal;
}
</style>

<div class="container py-5" style="max-width: 800px;">
    <div class="d-flex align-items-center justify-content-between mb-4 pb-3 border-bottom">
        <div>
            <h2 class="fw-bold text-dark mb-1" style="letter-spacing: -0.8px;">📰 Live Activity Stream</h2>
            <p class="text-muted mb-0 small">Real-time telemetry logging global match results, actions, and network transactions.</p>
        </div>
        <span id="refresh-badge" class="badge bg-white text-muted border font-monospace px-2 py-1.5 small shadow-sm">
            <span class="spinner-grow spinner-grow-sm text-success me-1" role="status" style="width: 8px; height: 8px;"></span> Live Sync Enabled
        </span>
    </div>

    <div id="feed-container">
        <?php echo render_feed_items($conn); ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const feedContainer = document.getElementById('feed-container');
    const badge = document.getElementById('refresh-badge');

    // Run seamless background fetch operations every 30 seconds
    setInterval(function() {
        badge.classList.replace('text-muted', 'text-primary');
        
        fetch('?ajax_render=1')
            .then(response => {
                if (!response.ok) throw new Error('Network fault detected.');
                return response.text();
            })
            .then(htmlContent => {
                if(htmlContent.trim().length > 0) {
                    feedContainer.innerHTML = htmlContent;
                }
            })
            .catch(err => console.warn('Sync delayed:', err))
            .finally(() => {
                setTimeout(() => badge.classList.replace('text-primary', 'text-muted'), 1000);
            });
    }, 30000);
});
</script>

<?php 
include_once '../includes/footer.php'; 
$conn->close();
?>
