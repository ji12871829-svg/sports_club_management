<?php
/**
 * public/referee.php
 * PIN-protected mobile scorekeeper page for referees/scorekeepers at the ground.
 * No member login required — just the 4-digit match PIN.
 *
 * Usage: yoursite.com/public/referee.php?pin=XXXX
 */
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once "../config/db_connect.php";
require_once "../includes/match_events.php";
require_once __DIR__ . '/../includes/input_sanitize.php';

$error      = '';
$fixture    = null;
$pin        = trim($_GET['pin'] ?? $_POST['pin'] ?? $_SESSION['referee_pin'] ?? '');
$action     = $_POST['action'] ?? '';
$pin_valid  = false;

// ── Look up fixture by PIN ────────────────────────────────────────────────────
if ($pin !== '') {
    $stmt = $conn->prepare("
        SELECT f.fixture_id, f.match_date, f.match_time, f.venue, f.matchday,
               f.status, f.home_score, f.away_score,
               f.live_minute, f.live_status, f.referee_pin,
               f.home_team_id, f.away_team_id,
               h.name AS home_team,
               a.name AS away_team,
               l.name AS league_name
        FROM fixtures f
        JOIN teams  h ON h.team_id  = f.home_team_id
        JOIN teams  a ON a.team_id  = f.away_team_id
        JOIN leagues l ON l.league_id = f.league_id
        WHERE f.referee_pin = ?
          AND f.status IN ('Scheduled','Live')
        LIMIT 1
    ");
    $stmt->bind_param("s", $pin);
    $stmt->execute();
    $result  = $stmt->get_result();
    $fixture = $result->fetch_assoc();
    $stmt->close();

    if ($fixture) {
        $pin_valid = true;
        $_SESSION['referee_pin'] = $pin; // remember pin in session
    } else {
        $error = 'Invalid PIN or match is not active.';
    }
}

// ── Handle score actions ──────────────────────────────────────────────────────
$toast = '';
if ($pin_valid && $action !== '' && isset($_POST['fixture_id'])) {
    $fixture_id = (int)$_POST['fixture_id'];

    // Verify fixture_id matches the PIN (safety check)
    if ($fixture_id !== (int)$fixture['fixture_id']) {
        $error = 'Security check failed.';
    } else {
        $new_home  = (int)$fixture['home_score'];
        $new_away  = (int)$fixture['away_score'];
        $new_min   = isset($_POST['live_minute']) && $_POST['live_minute'] !== ''
                       ? max(0, (int)$_POST['live_minute'])
                       : $fixture['live_minute'];
        $new_period = trim($_POST['live_status'] ?? $fixture['live_status'] ?? '');

        $player_name = trim($_POST['player_name'] ?? '');
        $event_minute = isset($_POST['live_minute']) && $_POST['live_minute'] !== ''
            ? max(0, (int) $_POST['live_minute'])
            : ($fixture['live_minute'] !== null ? (int) $fixture['live_minute'] : null);

        switch ($action) {
            case 'goal_home':
                $new_home++;
                $toast = '⚽ Goal — ' . $fixture['home_team'] . '!';
                asc_sync_goal_from_referee($conn, $fixture_id, (int) $fixture['home_team_id'], (int) $fixture['away_team_id'], 'goal_home', $player_name ?: null, $event_minute);
                break;
            case 'goal_away':
                $new_away++;
                $toast = '⚽ Goal — ' . $fixture['away_team'] . '!';
                asc_sync_goal_from_referee($conn, $fixture_id, (int) $fixture['home_team_id'], (int) $fixture['away_team_id'], 'goal_away', $player_name ?: null, $event_minute);
                break;
            case 'undo_home':
                $new_home = max(0, $new_home - 1);
                $toast = '↩ Goal cancelled (' . $fixture['home_team'] . ')';
                asc_sync_goal_from_referee($conn, $fixture_id, (int) $fixture['home_team_id'], (int) $fixture['away_team_id'], 'undo_home', null, null);
                break;
            case 'undo_away':
                $new_away = max(0, $new_away - 1);
                $toast = '↩ Goal cancelled (' . $fixture['away_team'] . ')';
                asc_sync_goal_from_referee($conn, $fixture_id, (int) $fixture['home_team_id'], (int) $fixture['away_team_id'], 'undo_away', null, null);
                break;
            case 'card_yellow_home':
                asc_record_match_event($conn, $fixture_id, (int) $fixture['home_team_id'], 'yellow_card', $player_name ?: null, null, $event_minute, 'referee');
                $toast = '🟨 Yellow card — ' . $fixture['home_team'];
                break;
            case 'card_yellow_away':
                asc_record_match_event($conn, $fixture_id, (int) $fixture['away_team_id'], 'yellow_card', $player_name ?: null, null, $event_minute, 'referee');
                $toast = '🟨 Yellow card — ' . $fixture['away_team'];
                break;
            case 'card_red_home':
                asc_record_match_event($conn, $fixture_id, (int) $fixture['home_team_id'], 'red_card', $player_name ?: null, null, $event_minute, 'referee');
                $toast = '🟥 Red card — ' . $fixture['home_team'];
                break;
            case 'card_red_away':
                asc_record_match_event($conn, $fixture_id, (int) $fixture['away_team_id'], 'red_card', $player_name ?: null, null, $event_minute, 'referee');
                $toast = '🟥 Red card — ' . $fixture['away_team'];
                break;
            case 'update_info':
                $toast = '🕐 Match info updated.';
                break;
        }

        // Set status to Live automatically on first score action
        $new_status = $fixture['status'] === 'Scheduled' ? 'Live' : $fixture['status'];

        $stmt = $conn->prepare("
            UPDATE fixtures
            SET home_score      = ?,
                away_score      = ?,
                live_minute     = ?,
                live_status     = ?,
                status          = ?,
                live_updated_at = NOW()
            WHERE fixture_id = ?
        ");
        $stmt->bind_param("iiissi",
            $new_home, $new_away, $new_min,
            $new_period, $new_status, $fixture_id
        );
        $stmt->execute();
        $stmt->close();

        // Refresh fixture data
        $stmt = $conn->prepare("
            SELECT f.fixture_id, f.match_date, f.match_time, f.venue, f.matchday,
                   f.status, f.home_score, f.away_score,
                   f.live_minute, f.live_status, f.referee_pin,
                   f.home_team_id, f.away_team_id,
                   h.name AS home_team,
                   a.name AS away_team,
                   l.name AS league_name
            FROM fixtures f
            JOIN teams  h ON h.team_id  = f.home_team_id
            JOIN teams  a ON a.team_id  = f.away_team_id
            JOIN leagues l ON l.league_id = f.league_id
            WHERE f.fixture_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("i", $fixture_id);
        $stmt->execute();
        $fixture = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Referee Scorekeeper — Apex Sports Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { -webkit-tap-highlight-color: transparent; }

        body {
            background: #0f172a;
            color: #f8fafc;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            min-height: 100vh;
            overscroll-behavior: none;
        }

        /* ── PIN screen ── */
        .pin-screen {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .pin-card {
            background: #1e293b;
            border-radius: 20px;
            padding: 40px 32px;
            width: 100%;
            max-width: 360px;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0,0,0,.4);
        }
        .pin-logo {
            width: 64px; height: 64px;
            background: #dc2626;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 20px;
            font-size: 28px;
        }
        .pin-input {
            font-size: 2rem;
            font-weight: 700;
            letter-spacing: 12px;
            text-align: center;
            background: #0f172a;
            border: 2px solid #334155;
            color: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            width: 100%;
        }
        .pin-input:focus {
            outline: none;
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220,38,38,.2);
        }
        .pin-btn {
            width: 100%;
            padding: 16px;
            font-size: 1rem;
            font-weight: 700;
            border-radius: 12px;
            background: #dc2626;
            color: #fff;
            border: none;
            margin-top: 16px;
            cursor: pointer;
            transition: background .2s, transform .1s;
        }
        .pin-btn:active { transform: scale(.97); background: #b91c1c; }

        /* ── Score screen ── */
        .score-screen { padding: 16px; max-width: 480px; margin: 0 auto; }

        .match-header {
            background: #1e293b;
            border-radius: 16px;
            padding: 20px 16px;
            margin-bottom: 16px;
            text-align: center;
        }
        .live-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #dc2626;
            color: #fff;
            font-size: .75rem;
            font-weight: 700;
            padding: 4px 14px;
            border-radius: 50px;
            letter-spacing: .5px;
            margin-bottom: 12px;
        }
        .live-dot {
            width: 7px; height: 7px;
            background: #fff;
            border-radius: 50%;
            animation: blink 1s infinite;
        }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }

        .scoreboard {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            margin: 12px 0;
        }
        .team-block { flex: 1; text-align: center; }
        .team-name-display {
            font-size: .85rem;
            font-weight: 600;
            color: #94a3b8;
            line-height: 1.3;
            margin-bottom: 6px;
        }
        .score-number {
            font-size: 4rem;
            font-weight: 900;
            color: #f8fafc;
            line-height: 1;
        }
        .score-divider {
            font-size: 2.5rem;
            font-weight: 300;
            color: #475569;
        }

        /* ── Goal buttons ── */
        .goal-btn {
            width: 100%;
            padding: 22px 16px;
            font-size: 1.1rem;
            font-weight: 800;
            border-radius: 16px;
            border: none;
            cursor: pointer;
            transition: transform .1s, filter .2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .goal-btn:active { transform: scale(.95); filter: brightness(.85); }
        .goal-btn-home { background: #2563eb; color: #fff; }
        .goal-btn-away { background: #16a34a; color: #fff; }

        .undo-btn {
            background: #1e293b;
            color: #94a3b8;
            border: 1px solid #334155;
            border-radius: 10px;
            padding: 10px;
            font-size: .8rem;
            cursor: pointer;
            width: 100%;
            transition: background .2s;
        }
        .undo-btn:active { background: #334155; }

        /* ── Info panel ── */
        .info-panel {
            background: #1e293b;
            border-radius: 16px;
            padding: 20px;
            margin-top: 16px;
        }
        .info-panel label { font-size: .8rem; color: #64748b; font-weight: 600; text-transform: uppercase; }
        .info-input {
            background: #0f172a;
            border: 1px solid #334155;
            color: #f8fafc;
            border-radius: 8px;
            padding: 10px 12px;
            width: 100%;
            font-size: .95rem;
        }
        .info-input:focus { outline: none; border-color: #3b82f6; }

        .update-info-btn {
            background: #334155;
            color: #f8fafc;
            border: none;
            border-radius: 10px;
            padding: 12px;
            width: 100%;
            font-weight: 600;
            font-size: .9rem;
            cursor: pointer;
            transition: background .2s;
        }
        .update-info-btn:active { background: #475569; }

        /* ── Full time button ── */
        .fulltime-btn {
            width: 100%;
            padding: 16px;
            background: #0f172a;
            color: #f59e0b;
            border: 2px solid #f59e0b;
            border-radius: 14px;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
            margin-top: 16px;
            transition: background .2s;
        }
        .fulltime-btn:active { background: #1e293b; }

        /* ── Toast ── */
        .toast-msg {
            position: fixed;
            top: 20px; left: 50%;
            transform: translateX(-50%) translateY(-80px);
            background: #22c55e;
            color: #fff;
            font-weight: 700;
            font-size: .95rem;
            padding: 12px 28px;
            border-radius: 50px;
            box-shadow: 0 8px 24px rgba(0,0,0,.3);
            z-index: 9999;
            transition: transform .3s ease;
            white-space: nowrap;
        }
        .toast-msg.show { transform: translateX(-50%) translateY(0); }
        .toast-msg.undo { background: #f59e0b; }

        /* ── Period badge ── */
        .period-display {
            font-size: .8rem;
            color: #94a3b8;
            margin-top: 4px;
        }
    </style>
</head>
<body>

<?php if ($toast): ?>
<div class="toast-msg <?php echo strpos($toast,'↩') !== false ? 'undo' : ''; ?>" id="toast">
    <?php echo e($toast); ?>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var t = document.getElementById('toast');
        setTimeout(function() { t.classList.add('show'); }, 50);
        setTimeout(function() { t.classList.remove('show'); }, 2500);
    });
</script>
<?php endif; ?>

<?php if (!$pin_valid): ?>
<!-- ═══════════════════════════════════════════════════════════ PIN SCREEN -->
<div class="pin-screen">
    <div class="pin-card">
        <div class="pin-logo">⚽</div>
        <h2 style="font-size:1.4rem;font-weight:800;margin-bottom:6px;">Referee Scorekeeper</h2>
        <p style="color:#64748b;font-size:.9rem;margin-bottom:28px;">
            Enter the 4-digit match PIN provided by the admin
        </p>

        <?php if ($error): ?>
            <div style="background:#7f1d1d;color:#fca5a5;padding:10px 16px;border-radius:8px;font-size:.85rem;margin-bottom:16px;">
                <i class="fas fa-exclamation-circle me-1"></i> <?php echo e($error); ?>
            </div>
        <?php endif; ?>

        <form method="GET" action="">
            <input type="number"
                   name="pin"
                   class="pin-input"
                   placeholder="····"
                   maxlength="4"
                   inputmode="numeric"
                   autofocus
                   required>
            <button type="submit" class="pin-btn">
                <i class="fas fa-unlock me-2"></i> Enter Match
            </button>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════════════════ SCORE SCREEN -->
<div class="score-screen pt-3">

    <!-- Match header -->
    <div class="match-header">
        <div>
            <span class="live-badge">
                <span class="live-dot"></span>
                <?php echo $fixture['status'] === 'Live' ? 'LIVE' : 'READY'; ?>
                <?php echo $fixture['live_minute'] ? ' · ' . (int)$fixture['live_minute'] . "'" : ''; ?>
            </span>
        </div>
        <div style="font-size:.8rem;color:#64748b;margin-bottom:8px;">
            <?php echo e($fixture['league_name']); ?> &nbsp;·&nbsp; MD <?php echo e($fixture['matchday']); ?>
        </div>

        <!-- Scoreboard -->
        <div class="scoreboard">
            <div class="team-block">
                <div class="team-name-display"><?php echo e($fixture['home_team']); ?></div>
                <div class="score-number" id="home-score"><?php echo (int)$fixture['home_score']; ?></div>
            </div>
            <div class="score-divider">–</div>
            <div class="team-block">
                <div class="team-name-display"><?php echo e($fixture['away_team']); ?></div>
                <div class="score-number" id="away-score"><?php echo (int)$fixture['away_score']; ?></div>
            </div>
        </div>

        <?php if ($fixture['live_status']): ?>
            <div class="period-display"><?php echo e($fixture['live_status']); ?></div>
        <?php endif; ?>

        <div style="font-size:.75rem;color:#475569;margin-top:8px;">
            <?php echo e(date('H:i', strtotime($fixture['match_time']))); ?>
            <?php echo $fixture['venue'] ? ' &nbsp;·&nbsp; ' . e($fixture['venue']) : ''; ?>
        </div>
    </div>

    <div class="info-panel mb-3">
        <label class="small text-muted">Scorer / player name (optional)</label>
        <input type="text" id="ref-player-name" class="info-input mt-1" maxlength="120" placeholder="e.g. Kamau">
    </div>

    <!-- ── Goal buttons ── -->
    <div class="row g-3 mb-3">
        <!-- Home goal -->
        <div class="col-6">
            <form method="POST" class="ref-action-form">
                <input type="hidden" name="pin"        value="<?php echo e($pin); ?>">
                <input type="hidden" name="fixture_id" value="<?php echo e($fixture['fixture_id']); ?>">
                <input type="hidden" name="action"     value="goal_home">
                <input type="hidden" name="player_name" class="ref-player-hidden" value="">
                <button type="submit" class="goal-btn goal-btn-home">
                    <i class="fas fa-futbol"></i>
                    <?php echo e($fixture['home_team']); ?>
                </button>
            </form>
            <!-- Undo -->
            <?php if ((int)$fixture['home_score'] > 0): ?>
            <form method="POST" class="mt-2 ref-action-form">
                <input type="hidden" name="pin"        value="<?php echo e($pin); ?>">
                <input type="hidden" name="fixture_id" value="<?php echo e($fixture['fixture_id']); ?>">
                <input type="hidden" name="action"     value="undo_home">
                <input type="hidden" name="player_name" class="ref-player-hidden" value="">
                <button type="submit" class="undo-btn">
                    <i class="fas fa-undo me-1"></i> Undo goal
                </button>
            </form>
            <?php endif; ?>
        </div>

        <!-- Away goal -->
        <div class="col-6">
            <form method="POST" class="ref-action-form">
                <input type="hidden" name="pin"        value="<?php echo e($pin); ?>">
                <input type="hidden" name="fixture_id" value="<?php echo e($fixture['fixture_id']); ?>">
                <input type="hidden" name="action"     value="goal_away">
                <input type="hidden" name="player_name" class="ref-player-hidden" value="">
                <button type="submit" class="goal-btn goal-btn-away">
                    <i class="fas fa-futbol"></i>
                    <?php echo e($fixture['away_team']); ?>
                </button>
            </form>
            <!-- Undo -->
            <?php if ((int)$fixture['away_score'] > 0): ?>
            <form method="POST" class="mt-2 ref-action-form">
                <input type="hidden" name="pin"        value="<?php echo e($pin); ?>">
                <input type="hidden" name="fixture_id" value="<?php echo e($fixture['fixture_id']); ?>">
                <input type="hidden" name="action"     value="undo_away">
                <input type="hidden" name="player_name" class="ref-player-hidden" value="">
                <button type="submit" class="undo-btn">
                    <i class="fas fa-undo me-1"></i> Undo goal
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- ── Cards ── -->
    <div class="row g-2 mb-3">
        <?php
        $card_actions = [
            ['card_yellow_home', '🟨 ' . $fixture['home_team'], 'warning'],
            ['card_yellow_away', '🟨 ' . $fixture['away_team'], 'warning'],
            ['card_red_home', '🟥 ' . $fixture['home_team'], 'danger'],
            ['card_red_away', '🟥 ' . $fixture['away_team'], 'danger'],
        ];
        foreach ($card_actions as [$act, $label, $btn]): ?>
        <div class="col-6">
            <form method="POST" class="ref-action-form">
                <input type="hidden" name="pin" value="<?php echo e($pin); ?>">
                <input type="hidden" name="fixture_id" value="<?php echo e($fixture['fixture_id']); ?>">
                <input type="hidden" name="action" value="<?php echo e($act); ?>">
                <input type="hidden" name="player_name" class="ref-player-hidden" value="">
                <button type="submit" class="btn btn-<?php echo $btn; ?> w-100 btn-sm"><?php echo e($label); ?></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Match info (minute + period) ── -->
    <div class="info-panel">
        <form method="POST">
            <input type="hidden" name="pin"        value="<?php echo e($pin); ?>">
            <input type="hidden" name="fixture_id" value="<?php echo e($fixture['fixture_id']); ?>">
            <input type="hidden" name="action"     value="update_info">

            <div class="row g-3">
                <div class="col-5">
                    <label>Minute</label>
                    <input type="number" name="live_minute"
                           class="info-input mt-1"
                           value="<?php echo e($fixture['live_minute'] ?? ''); ?>"
                           min="0" max="120" placeholder="45">
                </div>
                <div class="col-7">
                    <label>Period</label>
                    <select name="live_status" class="info-input mt-1">
                        <?php foreach (['First Half','Half Time','Second Half','Extra Time'] as $p): ?>
                            <option value="<?php echo e($p); ?>"
                                <?php echo ($fixture['live_status'] ?? '') === $p ? 'selected' : ''; ?>>
                                <?php echo e($p); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button type="submit" class="update-info-btn">
                        <i class="fas fa-clock me-1"></i> Update Minute &amp; Period
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- ── Full Time ── -->
    <form method="POST" onsubmit="return confirm('Mark this match as Full Time? This cannot be undone.');">
        <input type="hidden" name="pin"         value="<?php echo e($pin); ?>">
        <input type="hidden" name="fixture_id"  value="<?php echo e($fixture['fixture_id']); ?>">
        <input type="hidden" name="action"      value="update_info">
        <input type="hidden" name="live_status" value="Full Time">
        <input type="hidden" name="live_minute" value="90">
        <button type="submit" class="fulltime-btn"
                onclick="this.form.querySelector('[name=action]').value='update_info'">
            <i class="fas fa-flag-checkered me-2"></i> Full Time — End Match
        </button>
    </form>

    <!-- PIN reminder -->
    <div style="text-align:center;margin-top:24px;padding-bottom:32px;">
        <small style="color:#334155;font-size:.75rem;">
            Match PIN: <strong style="color:#475569;letter-spacing:4px;"><?php echo e($pin); ?></strong>
        </small>
    </div>

</div><!-- /score-screen -->
<?php endif; ?>

<script>
document.querySelectorAll('.ref-action-form').forEach(function (form) {
  form.addEventListener('submit', function () {
    var nameEl = document.getElementById('ref-player-name');
    var hidden = form.querySelector('.ref-player-hidden');
    if (nameEl && hidden) hidden.value = nameEl.value;
    var minEl = document.querySelector('input[name="live_minute"]');
    if (minEl && !form.querySelector('input[name="live_minute"]')) {
      var h = document.createElement('input');
      h.type = 'hidden';
      h.name = 'live_minute';
      h.value = minEl.value;
      form.appendChild(h);
    }
  });
});
</script>
</body>
</html>
