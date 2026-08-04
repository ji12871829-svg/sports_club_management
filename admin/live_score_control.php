<?php
/**
 * admin/live_score_control.php
 * Admin page to update live scores for today's fixtures.
 * Designed to be used on mobile at the ground.
 */
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once "../includes/fixture_scheduler.php";
require_once "../includes/standings.php";
require_once "../includes/send_email.php";
require_once "../includes/url.php";
require_once "../includes/match_commentary.php";

require_once __DIR__ . '/../includes/input_sanitize.php';

/**
 * Send a "match is live" email blast to all active members.
 * Returns [sent, failed] counts.
 */
function sendMatchLiveBlast($conn, array $fixture): array {
    $members = $conn->query("
        SELECT first_name, email
        FROM members
        WHERE email IS NOT NULL
          AND email != ''
    ");
    if (!$members) return [0, 0];

    $home     = htmlspecialchars($fixture['home_team'], ENT_QUOTES, 'UTF-8');
    $away     = htmlspecialchars($fixture['away_team'], ENT_QUOTES, 'UTF-8');
    $league   = htmlspecialchars($fixture['league_name'], ENT_QUOTES, 'UTF-8');
    $time     = date('H:i', strtotime($fixture['match_time']));
    $venue    = $fixture['venue'] ? htmlspecialchars($fixture['venue'], ENT_QUOTES, 'UTF-8') : 'Club Ground';
    $fixtures_url = app_absolute_url('public/view_fixtures.php');

    $subject  = "⚽ LIVE NOW: {$home} vs {$away}";

    $sent = $failed = 0;
    while ($member = $members->fetch_assoc()) {
        $name = htmlspecialchars($member['first_name'], ENT_QUOTES, 'UTF-8');
        $html = "
        <div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif;
                    max-width:540px;margin:30px auto;border-radius:12px;overflow:hidden;
                    border:1px solid #e2e8f0;box-shadow:0 4px 6px -1px rgba(0,0,0,.06);background:#fff;'>

          <div style='background:#dc2626;padding:32px 24px;text-align:center;'>
            <div style='display:inline-block;background:rgba(255,255,255,.15);
                        border-radius:50px;padding:6px 18px;margin-bottom:12px;'>
              <span style='color:#fff;font-size:12px;font-weight:700;letter-spacing:1px;text-transform:uppercase;'>
                🔴 &nbsp;Match Live Now
              </span>
            </div>
            <h2 style='color:#fff;margin:0;font-size:22px;font-weight:800;'>
              {$home} vs {$away}
            </h2>
            <p style='color:rgba(255,255,255,.85);margin:8px 0 0;font-size:14px;'>
              {$league}
            </p>
          </div>

          <div style='padding:32px 24px;color:#334155;line-height:1.7;'>
            <p style='font-size:16px;margin-top:0;'>Hi <strong>{$name}</strong>,</p>
            <p style='font-size:15px;color:#475569;'>
              The match has just kicked off. Follow the live score on the fixtures page —
              it updates automatically every 15 seconds.
            </p>

            <table style='width:100%;border-collapse:collapse;margin:20px 0;font-size:14px;'>
              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#64748b;width:35%;'>Kick-off</td>
                <td style='padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#0f172a;font-weight:600;'>{$time}</td>
              </tr>
              <tr>
                <td style='padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#64748b;'>Venue</td>
                <td style='padding:10px 14px;border-bottom:1px solid #f1f5f9;color:#0f172a;font-weight:600;'>{$venue}</td>
              </tr>
              <tr>
                <td style='padding:10px 14px;color:#64748b;'>Competition</td>
                <td style='padding:10px 14px;color:#0f172a;font-weight:600;'>{$league}</td>
              </tr>
            </table>

            <div style='text-align:center;margin-top:28px;'>
              <a href='{$fixtures_url}'
                 style='display:inline-block;background:#dc2626;color:#fff;
                        padding:14px 32px;border-radius:8px;text-decoration:none;
                        font-weight:700;font-size:15px;'>
                ⚽ &nbsp;Watch Live Score →
              </a>
            </div>
          </div>

          <div style='background:#f8fafc;padding:16px;text-align:center;
                      color:#94a3b8;font-size:12px;border-top:1px solid #f1f5f9;'>
            Apex Sports Club &nbsp;·&nbsp; Live Match Alerts
          </div>
        </div>";

        if (sendEmail($member['email'], $member['first_name'], $subject, $html)) {
            $sent++;
        } else {
            $failed++;
        }
    }
    return [$sent, $failed];
}

// ── CSRF ─────────────────────────────────────────────────────────────────────
if (empty($_SESSION['live_score_csrf'])) {
    $_SESSION['live_score_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['live_score_csrf'];

$message = '';

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $posted)) {
        $message = '<div class="alert alert-danger shadow-sm border-0 d-flex align-items-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>Security check failed.</div>';
    } elseif (($_POST['action'] ?? '') === 'commentary') {
        $fixture_id = (int)($_POST['fixture_id'] ?? 0);
        $text = trim($_POST['commentary_text'] ?? '');
        $minute = $_POST['commentary_minute'] !== '' ? (int)$_POST['commentary_minute'] : null;
        if ($fixture_id > 0 && commentary_add($conn, $fixture_id, $text, $minute)) {
            $message = '<div class="alert alert-success shadow-sm border-0 mb-4"><i class="fas fa-microphone me-2"></i>Commentary posted.</div>';
        } else {
            $message = '<div class="alert alert-warning shadow-sm border-0 mb-4">Could not post commentary.</div>';
        }
    } else {
        $fixture_id  = (int)($_POST['fixture_id']  ?? 0);
        $home_score  = max(0, (int)($_POST['home_score']  ?? 0));
        $away_score  = max(0, (int)($_POST['away_score']  ?? 0));
        $live_minute = isset($_POST['live_minute']) && $_POST['live_minute'] !== ''
                         ? max(0, (int)$_POST['live_minute'])
                         : null;
        $live_status = trim($_POST['live_status'] ?? '');
        $new_status  = trim($_POST['match_status'] ?? 'Live');

        $allowed_live   = ['', 'First Half', 'Half Time', 'Second Half', 'Extra Time', 'Full Time'];
        $allowed_status = ['Scheduled', 'Live', 'Completed', 'Postponed', 'Cancelled'];
        if (!in_array($live_status, $allowed_live, true))   $live_status = '';
        if (!in_array($new_status, $allowed_status, true))  $new_status  = 'Live';

        // When full time, finalize as Completed and recalculate standings
        if ($live_status === 'Full Time' || $new_status === 'Completed') {
            $new_status  = 'Completed';
            $live_minute = null;
        }

        if ($fixture_id <= 0) {
            $message = '<div class="alert alert-danger shadow-sm border-0 d-flex align-items-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>Invalid fixture.</div>';
        } else {
            $stmt = $conn->prepare("
                UPDATE fixtures
                SET home_score       = ?,
                    away_score       = ?,
                    live_minute      = ?,
                    live_status      = ?,
                    status           = ?,
                    live_updated_at  = NOW()
                WHERE fixture_id = ?
            ");
            $stmt->bind_param("iiissi",
                $home_score, $away_score, $live_minute,
                $live_status, $new_status, $fixture_id
            );

            if ($stmt->execute()) {
                // Recalculate standings when match is completed
                if ($new_status === 'Completed') {
                    asc_recalculate_standings($conn, $fixture_id);
                    $message = '<div class="alert alert-success shadow-sm border-0 d-flex align-items-center mb-4">
                                  <i class="fas fa-check-circle me-2"></i>
                                  <div>Match marked as <strong>Completed</strong>. Standings updated.</div>
                                </div>';
                } elseif ($new_status === 'Live') {
                    // Only blast on first transition to Live (previous status was not Live)
                    $prev = $conn->query("SELECT f.status, f.home_score, f.away_score, f.match_time, f.venue,
                                                 h.name AS home_team, a.name AS away_team, l.name AS league_name
                                          FROM fixtures f
                                          JOIN teams h ON h.team_id = f.home_team_id
                                          JOIN teams a ON a.team_id = f.away_team_id
                                          JOIN leagues l ON l.league_id = f.league_id
                                          WHERE f.fixture_id = $fixture_id LIMIT 1")
                                ->fetch_assoc();

                    [$sent, $failed] = sendMatchLiveBlast($conn, $prev);

                    // WhatsApp blast to all members with phone numbers
                    require_once '../includes/whatsapp.php';
                    $wa_msg = "⚽ LIVE NOW: {$prev['home_team']} vs {$prev['away_team']}\n{$prev['league_name']}\nFollow live scores on the fixtures page.";
                    $members_phones = $conn->query("SELECT phone_number FROM members WHERE phone_number IS NOT NULL AND phone_number != ''");
                    $wa_sent = 0;
                    if ($members_phones) {
                        while ($mp = $members_phones->fetch_row()) {
                            if (wa_notify($mp[0], $wa_msg)) $wa_sent++;
                        }
                    }

                    $blast_note = $sent > 0
                        ? " &nbsp;·&nbsp; <i class='fas fa-envelope me-1'></i> Email sent to <strong>{$sent}</strong> member(s)."
                        : ($failed > 0 ? " &nbsp;·&nbsp; Email blast failed — check your Brevo API key." : "");
                    $wa_note = $wa_sent > 0
                        ? " &nbsp;·&nbsp; <i class='fab fa-whatsapp me-1'></i> WhatsApp sent to <strong>{$wa_sent}</strong> member(s)."
                        : "";

                    $message = "<div class='alert alert-success shadow-sm border-0 d-flex align-items-center mb-4'>
                                  <i class='fas fa-satellite-dish me-2'></i>
                                  <div>Match is now <strong>Live</strong>.{$blast_note}{$wa_note}</div>
                                </div>";
                } else {
                    $message = '<div class="alert alert-success shadow-sm border-0 d-flex align-items-center mb-4">
                                  <i class="fas fa-check-circle me-2"></i>
                                  Live score updated successfully.
                                </div>';
                }
            } else {
                $message = '<div class="alert alert-danger shadow-sm border-0 d-flex align-items-center mb-4"><i class="fas fa-exclamation-triangle me-2"></i>Update failed: ' . e($conn->error) . '</div>';
            }
            $stmt->close();
        }
    }
}

// ── Generate PIN handler ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate_pin') {
    $posted = $_POST['csrf_token'] ?? '';
    if (hash_equals($csrf, $posted)) {
        $fixture_id = (int)($_POST['fixture_id'] ?? 0);
        $new_pin    = str_pad(random_int(1000, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("UPDATE fixtures SET referee_pin = ? WHERE fixture_id = ?");
        $stmt->bind_param("si", $new_pin, $fixture_id);
        $stmt->execute();
        $stmt->close();
        $referee_url = '../public/referee.php?pin=' . $new_pin;
        $message = "<div class='alert alert-warning shadow-sm border-0 mb-4 text-dark' style='background-color:#fef3c7;'>
                      <div class='d-flex align-items-center flex-wrap gap-2'>
                        <i class='fas fa-key text-warning fs-5 me-1'></i>
                        <span>Referee PIN Generated: <strong style='letter-spacing:3px;font-size:1.15rem;font-family:monospace;' class='bg-white px-2 py-1 rounded shadow-sm border'>{$new_pin}</strong></span>
                        <span class='text-muted small ms-md-2'>Share this with your field match-official.</span>
                        <a href='{$referee_url}' target='_blank' class='btn btn-sm btn-dark ms-auto mt-2 mt-md-0 shadow-sm'>
                          <i class='fas fa-external-link-alt me-1 small'></i> Open Pad
                        </a>
                      </div>
                    </div>";
    }
}

// ── Load today's fixtures (all statuses except Cancelled) ────────────────────
$today_fixtures = $conn->query("
    SELECT
        f.fixture_id, f.match_date, f.match_time, f.venue, f.matchday,
        f.status, f.home_score, f.away_score,
        f.live_minute, f.live_status, f.live_updated_at, f.referee_pin,
        h.name AS home_team,
        a.name AS away_team,
        l.name  AS league_name
    FROM fixtures f
    JOIN teams  h ON h.team_id  = f.home_team_id
    JOIN teams  a ON a.team_id  = f.away_team_id
    JOIN leagues l ON l.league_id = f.league_id
    WHERE DATE(f.match_date) = CURDATE()
      AND f.status NOT IN ('Cancelled')
    ORDER BY f.match_time ASC
")->fetch_all(MYSQLI_ASSOC);

// ── Load upcoming fixtures (next 7 days, for early setup) ────────────────────
$upcoming_fixtures = $conn->query("
    SELECT
        f.fixture_id, f.match_date, f.match_time, f.venue, f.matchday,
        f.status, f.home_score, f.away_score,
        f.live_minute, f.live_status,
        h.name AS home_team,
        a.name AS away_team,
        l.name  AS league_name
    FROM fixtures f
    JOIN teams  h ON h.team_id  = f.home_team_id
    JOIN teams  a ON a.team_id  = f.away_team_id
    JOIN leagues l ON l.league_id = f.league_id
    WHERE f.match_date > CURDATE()
      AND f.match_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND f.status = 'Scheduled'
    ORDER BY f.match_date ASC, f.match_time ASC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

$conn->close();

// Helper: status badge HTML
function status_badge($status, $live_minute = null) {
    $map = [
        'Live'      => 'danger',
        'Scheduled' => 'primary',
        'Completed' => 'success',
        'Postponed' => 'warning',
        'Cancelled' => 'secondary',
    ];
    $color = $map[$status] ?? 'secondary';
    
    if ($status === 'Live') {
        $min_lbl = $live_minute ? (int)$live_minute . "'" : 'LIVE';
        return "<span class=\"badge bg-danger d-inline-flex align-items-center px-2 py-1.5 fw-bold\" style=\"letter-spacing: 0.5px;\">
                    <span class=\"live-dot me-1.5\"></span>{$min_lbl}
                </span>";
    }
    
    $badge_styles = $status === 'Scheduled' ? 'bg-opacity-10 text-primary border border-primary border-opacity-25' : "bg-{$color}";
    $bg_class = $status === 'Scheduled' ? '' : $badge_styles;
    
    return "<span class=\"badge {$bg_class} text-uppercase fw-semibold px-2.5 py-1.5\" style=\"" . ($status === 'Scheduled' ? '' : '') . "\">" . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . "</span>";
}
?>

<style>
:root {
    --asc-border-radius: 1rem;
    --asc-card-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.04), 0 4px 6px -4px rgba(0, 0, 0, 0.04);
}
body {
    background-color: #f8fafc;
    color: #1e293b;
}
.live-card {
    border: 1px solid #e2e8f0;
    border-radius: var(--asc-border-radius);
    background: #fff;
    box-shadow: var(--asc-card-shadow);
    transition: all 0.25s ease;
    overflow: hidden;
}
.live-card.is-live {
    border-color: #fca5a5;
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.06), var(--asc-card-shadow);
}
.live-card.is-completed {
    border-color: #cbd5e1;
    background-color: #f8fafc;
}
.score-display-container {
    background: #f1f5f9;
    border-radius: 0.75rem;
    padding: 0.75rem 1.2rem;
}
.score-display {
    font-size: 2.25rem;
    font-weight: 800;
    font-family: monospace;
    letter-spacing: -1px;
    color: #0f172a;
}
.team-title {
    font-weight: 700;
    font-size: 1.05rem;
    color: #0f172a;
}
.live-dot {
    width: 8px;
    height: 8px;
    background-color: #fff;
    border-radius: 50%;
    display: inline-block;
    animation: pulse-dot 1.2s infinite;
}
@keyframes pulse-dot {
    0% { transform: scale(0.8); opacity: 0.5; }
    50% { transform: scale(1.2); opacity: 1; }
    100% { transform: scale(0.8); opacity: 0.5; }
}
.score-stepper {
    display: flex;
    align-items: center;
    background: #f1f5f9;
    border: 1px solid #e2e8f0;
    border-radius: 0.5rem;
    max-width: 140px;
    margin: 0 auto;
}
.score-stepper button {
    border: none;
    background: transparent;
    padding: 0.5rem 0.75rem;
    color: #475569;
    font-weight: bold;
    font-size: 1.1rem;
    transition: background 0.2s;
}
.score-stepper button:hover {
    background: #e2e8f0;
}
.score-stepper button:active {
    background: #cbd5e1;
}
.score-input-stepped {
    border: none !important;
    background: transparent !important;
    text-align: center;
    font-size: 1.25rem;
    font-weight: 700;
    width: 100%;
    padding: 0;
    color: #0f172a;
    pointer-events: none;
}
/* Eliminate default spin handles inside stepper layout */
.score-input-stepped::-webkit-outer-spin-button,
.score-input-stepped::-webkit-inner-spin-button {
    -webkit-appearance: none;
    margin: 0;
}
.score-input-stepped[type=number] {
    -moz-appearance: textfield;
}
.mobile-input-card {
    background-color: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
}
.btn-pitch-action {
    border-radius: 0.5rem;
    padding: 0.55rem 1rem;
    font-weight: 600;
    font-size: 0.9rem;
    transition: all 0.2s ease;
}
</style>

<div class="container-fluid py-4" style="max-width: 900px;">

    <div class="card border-0 bg-dark text-white p-4 mb-4" style="border-radius: var(--asc-border-radius); background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-3 bg-danger bg-opacity-20 d-flex align-items-center justify-content-center border border-danger border-opacity-20" style="width:50px;height:50px">
                    <i class="fas fa-broadcast-tower text-danger fs-4"></i>
                </div>
                <div>
                    <h1 class="mb-0 fw-bold fs-4 text-white">Live Score Control</h1>
                    <p class="text-slate-400 mb-0 small text-white-50">Pitchside management utility · Automatic sync every 15s</p>
                </div>
            </div>
            <div>
                <span class="badge bg-white bg-opacity-10 text-white border border-white border-opacity-10 px-3 py-2 fs-7 rounded-2">
                    <i class="fas fa-calendar-day me-1.5 text-danger"></i>
                    <?php echo date('l, d M Y'); ?>
                </span>
            </div>
        </div>
    </div>

    <?php if ($message) echo $message; ?>

    <div class="d-flex align-items-center justify-content-between mb-3 px-1">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-futbol text-danger fs-5"></i>
            <h2 class="h5 mb-0 fw-bold text-dark">Today's Fixtures</h2>
            <span class="badge bg-danger rounded-pill px-2 py-1 fs-8"><?php echo count($today_fixtures); ?></span>
        </div>
        <span class="small text-muted d-none d-sm-inline">Changes reflect automatically for fans</span>
    </div>

    <?php if (empty($today_fixtures)): ?>
        <div class="card border-0 p-5 text-center text-muted mb-5 shadow-sm" style="border-radius: var(--asc-border-radius);">
            <div class="py-3">
                <i class="fas fa-calendar-times fa-3x text-slate-300 mb-3 text-muted opacity-50"></i>
                <p class="fw-medium mb-1">No fixtures scheduled for today.</p>
                <small class="text-muted">Check out the upcoming list below to initialize early feeds.</small>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($today_fixtures as $f): ?>
            <?php
                $is_live      = $f['status'] === 'Live';
                $is_completed = $f['status'] === 'Completed';
                $card_class   = $is_live ? 'is-live' : ($is_completed ? 'is-completed' : '');
            ?>
            <div class="live-card <?php echo $card_class; ?> mb-4">
                
                <div class="px-3 py-2.5 bg-light border-bottom d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div class="d-flex align-items-center gap-1.5 text-muted small">
                        <span class="fw-bold text-dark"><?php echo e($f['league_name']); ?></span>
                        <span>•</span>
                        <span>Matchday <?php echo e($f['matchday']); ?></span>
                        <?php if($f['venue']): ?>
                            <span>•</span>
                            <span class="text-truncate" style="max-width: 150px;"><i class="fas fa-map-marker-alt me-0.5"></i> <?php echo e($f['venue']); ?></span>
                        <?php endif; ?>
                    </div>
                    <div>
                        <?php echo status_badge($f['status'], $f['live_minute']); ?>
                    </div>
                </div>

                <div class="p-4 bg-white">
                    <div class="row align-items-center justify-content-center text-center g-2">
                        <div class="col-5">
                            <div class="team-title text-uppercase text-truncate"><?php echo e($f['home_team']); ?></div>
                            <small class="text-muted small">Home</small>
                        </div>
                        <div class="col-2">
                            <div class="score-display-container d-inline-block">
                                <span class="score-display"><?php echo (int)$f['home_score']; ?>:<?php echo (int)$f['away_score']; ?></span>
                            </div>
                        </div>
                        <div class="col-5">
                            <div class="team-title text-uppercase text-truncate"><?php echo e($f['away_team']); ?></div>
                            <small class="text-muted small">Away</small>
                        </div>
                    </div>

                    <?php if ($f['live_status'] && !$is_completed): ?>
                        <div class="text-center mt-3">
                            <span class="badge bg-danger bg-opacity-10 text-danger border border-danger border-opacity-10 fw-bold px-3 py-1.5 rounded-pill shadow-sm" style="font-size: 0.8rem;">
                                <i class="fas fa-clock me-1"></i> <?php echo e($f['live_status']); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if (!$is_completed): ?>
                        <form method="POST" class="mt-4 pt-3 border-top">
                            <input type="hidden" name="csrf_token"  value="<?php echo e($csrf); ?>">
                            <input type="hidden" name="fixture_id"  value="<?php echo e($f['fixture_id']); ?>">

                            <div class="mobile-input-card p-3 mb-3">
                                <div class="row g-3 align-items-center">
                                    <div class="col-6 text-center border-end">
                                        <label class="form-label small fw-bold text-muted mb-2 text-truncate d-block"><?php echo e($f['home_team']); ?></label>
                                        <div class="score-stepper">
                                            <button type="button" onclick="const input = this.nextElementSibling; if(input.value > 0) { input.value = parseInt(input.value) - 1; }">-</button>
                                            <input type="number" name="home_score" value="<?php echo (int)$f['home_score']; ?>" min="0" class="score-input-stepped">
                                            <button type="button" onclick="const input = this.previousElementSibling; input.value = parseInt(input.value) + 1;">+</button>
                                        </div>
                                    </div>
                                    <div class="col-6 text-center">
                                        <label class="form-label small fw-bold text-muted mb-2 text-truncate d-block"><?php echo e($f['away_team']); ?></label>
                                        <div class="score-stepper">
                                            <button type="button" onclick="const input = this.nextElementSibling; if(input.value > 0) { input.value = parseInt(input.value) - 1; }">-</button>
                                            <input type="number" name="away_score" value="<?php echo (int)$f['away_score']; ?>" min="0" class="score-input-stepped">
                                            <button type="button" onclick="const input = this.previousElementSibling; input.value = parseInt(input.value) + 1;">+</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6 col-sm-4">
                                    <label class="form-label small fw-bold text-muted mb-1">Match Minute</label>
                                    <div class="input-group input-group-sm">
                                        <span class="input-group-text bg-white"><i class="fas fa-stopwatch text-muted"></i></span>
                                        <input type="number" name="live_minute" value="<?php echo e($f['live_minute'] ?? ''); ?>" min="0" max="120" placeholder="e.g. 45" class="form-control">
                                    </div>
                                </div>
                                <div class="col-6 col-sm-4">
                                    <label class="form-label small fw-bold text-muted mb-1">Period Bracket</label>
                                    <select name="live_status" class="form-select form-select-sm">
                                        <?php foreach (['', 'First Half', 'Half Time', 'Second Half', 'Extra Time', 'Full Time'] as $ls): ?>
                                            <option value="<?php echo e($ls); ?>" <?php echo $f['live_status'] === $ls ? 'selected' : ''; ?>>
                                                <?php echo $ls ?: '— Not Started —'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12 col-sm-4">
                                    <label class="form-label small fw-bold text-muted mb-1">Fixture Status</label>
                                    <select name="match_status" class="form-select form-select-sm">
                                        <?php foreach (['Scheduled', 'Live', 'Completed', 'Postponed'] as $ms): ?>
                                            <option value="<?php echo e($ms); ?>" <?php echo $f['status'] === $ms ? 'selected' : ''; ?>>
                                                <?php echo e($ms); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="d-flex gap-2 flex-wrap mt-3">
                                <button type="submit" class="btn btn-danger btn-pitch-action flex-grow-1 shadow-sm">
                                    <i class="fas fa-save me-1.5"></i> Update Dashboard Data
                                </button>
                                
                                <?php if (!$is_live): ?>
                                    <button type="submit" class="btn btn-outline-danger btn-pitch-action" onclick="this.form.querySelector('[name=match_status]').value='Live'; this.form.querySelector('[name=live_status]').value='First Half';">
                                        <i class="fas fa-play me-1.5"></i> Kick Off
                                    </button>
                                <?php endif; ?>
                                
                                <button type="submit" class="btn btn-outline-success btn-pitch-action" onclick="this.form.querySelector('[name=match_status]').value='Completed'; this.form.querySelector('[name=live_status]').value='Full Time';">
                                    <i class="fas fa-flag-checkered me-1.5"></i> End Game
                                </button>
                            </div>
                        </form>
                        <?php if ($is_live): ?>
                        <form method="POST" class="mt-2 p-3 bg-light rounded border">
                            <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                            <input type="hidden" name="action" value="commentary">
                            <input type="hidden" name="fixture_id" value="<?php echo (int)$f['fixture_id']; ?>">
                            <label class="form-label small fw-bold mb-1"><i class="fas fa-microphone me-1"></i> Live commentary</label>
                            <div class="row g-2">
                                <div class="col-3"><input type="number" name="commentary_minute" class="form-control form-control-sm" placeholder="Min" min="0" max="120"></div>
                                <div class="col-7"><input type="text" name="commentary_text" class="form-control form-control-sm" placeholder="Goal! Chance missed..." required maxlength="255"></div>
                                <div class="col-2"><button type="submit" class="btn btn-sm btn-dark w-100">Post</button></div>
                            </div>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-success border-0 py-3 mb-0 small text-center mt-2 shadow-sm rounded-3">
                            <i class="fas fa-check-circle me-1.5 text-success fs-5 vertical-align-middle"></i>
                            Fixture Completed & Logged · Final Score: <strong><?php echo (int)$f['home_score']; ?> – <?php echo (int)$f['away_score']; ?></strong> · Standings Updated
                        </div>
                    <?php endif; ?>

                    <?php if ($f['live_updated_at']): ?>
                        <div class="text-end mt-3 pt-2 border-top border-light">
                            <small class="text-muted d-inline-flex align-items-center gap-1" style="font-size:0.75rem;">
                                <i class="fas fa-history"></i> Log Sync: <?php echo e(date('H:i:s', strtotime($f['live_updated_at']))); ?>
                            </small>
                        </div>
                    <?php endif; ?>

                    <div class="mt-3 pt-3 border-top d-flex align-items-center justify-content-between flex-wrap gap-3 bg-light p-3 rounded-3 border">
                        <?php if ($f['referee_pin']): ?>
                            <div class="d-flex align-items-center gap-3">
                                <div class="bg-white px-2.5 py-1.5 rounded shadow-sm border border-warning border-opacity-50 text-center">
                                    <small class="text-muted d-block uppercase fw-bold tracking-wider" style="font-size:0.65rem;"><i class="fas fa-key text-warning"></i> GATEPIN</small>
                                    <span style="font-size:1.3rem; font-weight:800; font-family:monospace; color:#d97706; letter-spacing: 2px;">
                                        <?php echo e($f['referee_pin']); ?>
                                    </span>
                                </div>
                                <div class="d-none d-md-block">
                                    <div class="small fw-semibold text-dark">Ref Delegation Active</div>
                                    <div class="text-muted small" style="font-size:0.8rem;">Officials can mutate live event attributes via standalone pad.</div>
                                </div>
                            </div>
                            <div class="d-flex gap-1.5">
                                <a href="../public/referee.php?pin=<?php echo e($f['referee_pin']); ?>" target="_blank" class="btn btn-sm btn-white border shadow-sm px-2.5">
                                    <i class="fas fa-external-link-alt text-muted me-1"></i> Launch Pad
                                </a>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="csrf_token"  value="<?php echo e($csrf); ?>">
                                    <input type="hidden" name="fixture_id"  value="<?php echo e($f['fixture_id']); ?>">
                                    <input type="hidden" name="action"      value="generate_pin">
                                    <button type="submit" class="btn btn-sm btn-light border px-2" onclick="return confirm('Generate a new PIN? The old one will stop working.')" title="Regenerate Pin">
                                        <i class="fas fa-sync-alt text-muted"></i>
                                    </button>
                                </form>
                            </div>
                        <?php else: ?>
                            <div class="text-muted small"><i class="fas fa-user-shield me-1"></i> No pitch official delegate proxy configured</div>
                            <form method="POST">
                                <input type="hidden" name="csrf_token"  value="<?php echo e($csrf); ?>">
                                <input type="hidden" name="fixture_id"  value="<?php echo e($f['fixture_id']); ?>">
                                <input type="hidden" name="action"      value="generate_pin">
                                <button type="submit" class="btn btn-sm btn-warning text-dark fw-semibold px-3 shadow-sm">
                                    <i class="fas fa-key me-1"></i> Provision Ref Secret
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if (!empty($upcoming_fixtures)): ?>
        <div class="card border-0 shadow-sm mt-5" style="border-radius: var(--asc-border-radius); overflow: hidden;">
            <div class="card-header bg-white py-3 border-bottom d-flex align-items-center gap-2">
                <i class="fas fa-calendar-week text-primary fs-5"></i>
                <h3 class="h6 mb-0 fw-bold text-dark">Upcoming This Week Schedule</h3>
                <span class="badge bg-primary bg-opacity-10 text-primary ms-1 small fw-semibold">Early Set Provision</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-uppercase font-monospace text-muted" style="font-size: 0.75rem;">
                            <tr>
                                <th class="ps-3 py-3">Date/Time</th>
                                <th class="py-3">Matchup</th>
                                <th class="py-3">League</th>
                                <th class="py-3">Status</th>
                                <th class="pe-3 py-3 text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcoming_fixtures as $f): ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-bold text-dark"><?php echo e(date('d M', strtotime($f['match_date']))); ?></div>
                                        <div class="text-muted small" style="font-size: 0.8rem;"><i class="far fa-clock me-1"></i><?php echo e(date('H:i', strtotime($f['match_time']))); ?></div>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center gap-1">
                                            <span class="fw-semibold text-dark"><?php echo e($f['home_team']); ?></span>
                                            <span class="text-muted small px-1 text-lowercase">vs</span>
                                            <span class="fw-semibold text-dark"><?php echo e($f['away_team']); ?></span>
                                        </div>
                                    </td>
                                    <td><span class="text-muted small"><?php echo e($f['league_name']); ?></span></td>
                                    <td><?php echo status_badge($f['status']); ?></td>
                                    <td class="pe-3 text-end">
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="csrf_token"   value="<?php echo e($csrf); ?>">
                                            <input type="hidden" name="fixture_id"   value="<?php echo e($f['fixture_id']); ?>">
                                            <input type="hidden" name="home_score"   value="0">
                                            <input type="hidden" name="away_score"   value="0">
                                            <input type="hidden" name="live_minute"  value="">
                                            <input type="hidden" name="live_status"  value="First Half">
                                            <input type="hidden" name="match_status" value="Live">
                                            <button type="submit" class="btn btn-xs btn-outline-danger px-2.5 py-1 font-sans fw-semibold rounded" style="font-size: 0.8rem;">
                                                <i class="fas fa-play me-1 small"></i> Deploy Live
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php
// Include admin footer/scripts
$admin_footer = file_get_contents(__DIR__ . '/../includes/admin_header.php');
?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>