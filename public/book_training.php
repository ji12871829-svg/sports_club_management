<?php
// ============================================================
//  public/book_training.php
//  Training session booking — pick coach, date & time slot
// ============================================================
require_once '../includes/session_config.php';
require_once '../includes/csrf.php';
asc_session_start();

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header('location: login.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/feature_helpers.php';
require_once '../includes/coach_availability.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int) $_SESSION['member_id'];
$success = $error = '';

// ── Verify training_sessions table exists (created by migration 044) ────────
$train_table_ok = db_table_exists($conn, 'training_sessions');

// ── Fetch coaches ─────────────────────────────────────────────────────────────
$coaches = [];
$result = $conn->query("SELECT coach_id, first_name, last_name, specialization FROM coaches ORDER BY first_name");
while ($row = $result->fetch_assoc()) $coaches[] = $row;

// ── Handle booking submission ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_session'])) {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'train_csrf')) {
        $error = 'Security check failed. Please refresh.';
    } else {
        $coach_id     = (int) ($_POST['coach_id'] ?? 0);
        $session_date = trim($_POST['session_date'] ?? '');
        $session_time = trim($_POST['session_time'] ?? '');
        $duration     = (int) ($_POST['duration_mins'] ?? 60);
        $notes        = trim($_POST['notes'] ?? '');

        if ($coach_id <= 0 || empty($session_date) || empty($session_time)) {
            $error = 'Please fill in all required fields.';
        } elseif (strtotime($session_date) < strtotime('today')) {
            $error = 'Please select a future date.';
        } elseif (!asc_coach_available_at($conn, $coach_id, $session_date, $session_time)) {
            $error = 'This coach is not available at that time. Please choose a different slot.';
        } else {
            // Check for double booking (same coach, date, overlapping time)
            if (!$train_table_ok) {
                $error = 'The training sessions table is not available. Please contact admin.';
            } else {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM training_sessions
                WHERE coach_id=? AND session_date=? AND session_time=? AND status != 'Cancelled'");
            $stmt->bind_param('iss', $coach_id, $session_date, $session_time);
            $stmt->execute();
            $stmt->bind_result($clash);
            $stmt->fetch();
            $stmt->close();

            if ($clash > 0) {
                $error = 'That slot is already booked. Please choose a different time.';
            } else {
                $stmt = $conn->prepare("INSERT INTO training_sessions
                    (member_id, coach_id, session_date, session_time, duration_mins, notes)
                    VALUES (?,?,?,?,?,?)");
                $stmt->bind_param('iissss', $member_id, $coach_id, $session_date, $session_time, $duration, $notes);
                if ($stmt->execute()) {
                    $success = 'Training session booked! Your coach will confirm shortly.';
                } else {
                    $error = 'Booking failed. Please try again.';
                }
                $stmt->close();
            }
            }
        }
    }
}

// ── Handle cancellation ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_session'])) {
    if ($train_table_ok && csrf_verify($_POST['csrf_token'] ?? '', 'train_csrf')) {
        $sid = (int) ($_POST['session_id'] ?? 0);
        if ($sid > 0) {
            $stmt = $conn->prepare("UPDATE training_sessions SET status='Cancelled'
                WHERE session_id=? AND member_id=? AND status='Pending'");
            $stmt->bind_param('ii', $sid, $member_id);
            $stmt->execute();
            $stmt->close();
            $success = 'Session cancelled.';
        }
    }
}

// ── Fetch member's upcoming sessions ──────────────────────────────────────────
$sessions = [];
if ($train_table_ok) {
$stmt = $conn->prepare("
    SELECT ts.session_id, ts.session_date, ts.session_time, ts.duration_mins, ts.status, ts.notes,
           c.first_name AS coach_first, c.last_name AS coach_last, c.specialization
    FROM training_sessions ts
    JOIN coaches c ON c.coach_id = ts.coach_id
    WHERE ts.member_id = ? AND ts.session_date >= CURDATE()
    ORDER BY ts.session_date ASC, ts.session_time ASC
    LIMIT 20
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Past sessions
$past = [];
$stmt = $conn->prepare("
    SELECT ts.session_date, ts.session_time, ts.duration_mins, ts.status,
           c.first_name AS coach_first, c.last_name AS coach_last
    FROM training_sessions ts
    JOIN coaches c ON c.coach_id = ts.coach_id
    WHERE ts.member_id = ? AND ts.session_date < CURDATE()
    ORDER BY ts.session_date DESC LIMIT 10
");
$stmt->bind_param('i', $member_id);
$stmt->execute();
$past = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
}

$conn->close();
include '../includes/header.php';
?>
<style>
    body { background: #f8fafc !important; }
    .bt-card {
        border: 1px solid #e2e8f0; border-radius: 14px;
        background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.05);
    }
    .section-label {
        font-size: .7rem; font-weight: 700; letter-spacing: .1em;
        text-transform: uppercase; color: #94a3b8; margin-bottom: 1rem;
    }
    .coach-card {
        border: 2px solid #e2e8f0; border-radius: 10px;
        padding: .85rem 1rem; cursor: pointer;
        transition: border-color .15s, background .15s;
        background: #fff;
    }
    .coach-card:hover { border-color: #4f46e5; background: #f5f3ff; }
    .coach-card.selected { border-color: #4f46e5; background: #f5f3ff; }
    .coach-card input[type=radio] { display: none; }
    .coach-avatar {
        width: 42px; height: 42px; border-radius: 50%;
        background: linear-gradient(135deg,#4f46e5,#6366f1);
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 700; font-size: .95rem;
        flex-shrink: 0;
    }
    .coach-name  { font-weight: 600; font-size: .9rem; color: #1e293b; }
    .coach-spec  { font-size: .75rem; color: #64748b; }
    .form-label  { font-size: .85rem; font-weight: 600; color: #475569; }
    .form-control, .form-select {
        border: 1px solid #e2e8f0; border-radius: 8px;
        font-size: .9rem; padding: .55rem .85rem;
    }
    .form-control:focus, .form-select:focus {
        border-color: #4f46e5; box-shadow: 0 0 0 3px rgba(79,70,229,.1);
    }
    .btn-book {
        background: linear-gradient(135deg,#4f46e5,#6366f1);
        color: #fff; border: none; border-radius: 9px;
        padding: .65rem 2rem; font-weight: 600;
    }
    .btn-book:hover { opacity: .87; color: #fff; }
    /* Session list */
    .session-row {
        border: 1px solid #e2e8f0; border-radius: 10px;
        padding: .9rem 1.1rem; margin-bottom: .6rem;
        background: #fff;
        display: flex; align-items: center; gap: 1rem;
        flex-wrap: wrap;
    }
    .s-date { font-weight: 700; font-size: .92rem; color: #1e293b; min-width: 80px; }
    .s-time { font-size: .82rem; color: #64748b; }
    .s-coach { font-size: .85rem; font-weight: 600; color: #334155; }
    .s-spec  { font-size: .75rem; color: #94a3b8; }
    .s-badge {
        font-size: .7rem; padding: .2rem .65rem;
        border-radius: 20px; font-weight: 600; white-space: nowrap;
    }
    .s-badge.Pending   { background:#fef9c3; color:#92400e; }
    .s-badge.Confirmed { background:#dcfce7; color:#166534; }
    .s-badge.Cancelled { background:#fee2e2; color:#991b1b; }
    textarea.form-control { resize: vertical; min-height: 70px; }
</style>

<div class="container py-4" style="max-width: 760px;">

    <div class="d-flex align-items-center gap-3 mb-4">
        <a href="dashboard.php" class="btn btn-sm btn-outline-secondary rounded-pill px-3">
            <i class="fas fa-arrow-left me-1"></i> Back
        </a>
        <div>
            <h4 class="mb-0 fw-bold">Book a Training Session</h4>
            <p class="text-muted small mb-0">Pick a coach, date and time</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success border-0 rounded-3 mb-4">
            <i class="fas fa-check-circle me-2"></i><?php echo e($success); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger border-0 rounded-3 mb-4">
            <i class="fas fa-exclamation-circle me-2"></i><?php echo e($error); ?>
        </div>
    <?php endif; ?>

    <!-- Booking form -->
    <div class="bt-card p-4 mb-4">
        <form method="POST">
            <?php echo csrf_field('train_csrf'); ?>

            <!-- Coach selector -->
            <p class="section-label"><i class="fas fa-user-tie me-1"></i> Choose a Coach</p>
            <?php if (empty($coaches)): ?>
                <p class="text-muted small">No coaches available yet. Ask the admin to add coaches.</p>
            <?php else: ?>
            <div class="row g-2 mb-4">
                <?php foreach ($coaches as $i => $coach): ?>
                <div class="col-md-6">
                    <label class="coach-card d-flex align-items-center gap-3 <?php echo $i === 0 ? 'selected' : ''; ?>"
                           for="coach_<?php echo (int)$coach['coach_id']; ?>">
                        <input type="radio" name="coach_id" id="coach_<?php echo (int)$coach['coach_id']; ?>"
                               value="<?php echo (int)$coach['coach_id']; ?>"
                               <?php echo $i === 0 ? 'checked' : ''; ?>>
                        <div class="coach-avatar"><?php echo e(strtoupper(substr($coach['first_name'],0,1).substr($coach['last_name'],0,1))); ?></div>
                        <div>
                            <div class="coach-name"><?php echo e($coach['first_name'] . ' ' . $coach['last_name']); ?></div>
                            <div class="coach-spec"><?php echo e($coach['specialization'] ?? 'General Coaching'); ?></div>
                        </div>
                    </label>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Date, time, duration -->
            <p class="section-label"><i class="fas fa-calendar-alt me-1"></i> Session Details</p>
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="date" name="session_date" class="form-control"
                           min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                           max="<?php echo date('Y-m-d', strtotime('+60 days')); ?>"
                           required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Start Time <span class="text-danger">*</span></label>
                    <input type="time" name="session_time" class="form-control"
                           min="06:00" max="21:00"
                           value="09:00" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Duration</label>
                    <select name="duration_mins" class="form-select">
                        <option value="30">30 minutes</option>
                        <option value="60" selected>1 hour</option>
                        <option value="90">1.5 hours</option>
                        <option value="120">2 hours</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes <span class="text-muted fw-400 small">(optional)</span></label>
                    <textarea name="notes" class="form-control" placeholder="e.g. Focus on dribbling, upper body strength…"></textarea>
                </div>
            </div>

            <button type="submit" name="book_session" class="btn-book btn w-100">
                <i class="fas fa-calendar-plus me-1"></i> Book Session
            </button>
            <?php endif; ?>
        </form>
    </div>

    <!-- Upcoming sessions -->
    <?php if (!empty($sessions)): ?>
    <p class="section-label"><i class="fas fa-clock me-1"></i> Upcoming Sessions (<?php echo count($sessions); ?>)</p>
    <?php foreach ($sessions as $s): ?>
    <div class="session-row">
        <div class="flex-shrink-0">
            <div class="s-date"><?php echo e(date('d M', strtotime($s['session_date']))); ?></div>
            <div class="s-time"><?php echo e(date('H:i', strtotime($s['session_time']))); ?> · <?php echo (int)$s['duration_mins']; ?>min</div>
        </div>
        <div class="flex-grow-1">
            <div class="s-coach"><i class="fas fa-user-tie me-1 text-primary"></i><?php echo e($s['coach_first'] . ' ' . $s['coach_last']); ?></div>
            <div class="s-spec"><?php echo e($s['specialization'] ?? 'General Coaching'); ?></div>
            <?php if (!empty($s['notes'])): ?>
                <div class="s-spec mt-1"><i class="fas fa-sticky-note me-1"></i><?php echo e(mb_strimwidth($s['notes'], 0, 60, '…')); ?></div>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="s-badge <?php echo e($s['status']); ?>"><?php echo e($s['status']); ?></span>
            <?php if ($s['status'] === 'Pending'): ?>
            <form method="POST" onsubmit="return confirm('Cancel this session?');">
                <?php echo csrf_field('train_csrf'); ?>
                <input type="hidden" name="session_id" value="<?php echo (int)$s['session_id']; ?>">
                <button type="submit" name="cancel_session"
                        class="btn btn-sm btn-outline-danger rounded-pill px-2 py-1" style="font-size:.72rem;">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- Past sessions -->
    <?php if (!empty($past)): ?>
    <p class="section-label mt-4"><i class="fas fa-history me-1"></i> Past Sessions</p>
    <?php foreach ($past as $s): ?>
    <div class="session-row" style="opacity:.6;">
        <div class="flex-shrink-0">
            <div class="s-date"><?php echo e(date('d M Y', strtotime($s['session_date']))); ?></div>
            <div class="s-time"><?php echo e(date('H:i', strtotime($s['session_time']))); ?> · <?php echo (int)$s['duration_mins']; ?>min</div>
        </div>
        <div class="flex-grow-1">
            <div class="s-coach"><?php echo e($s['coach_first'] . ' ' . $s['coach_last']); ?></div>
        </div>
        <span class="s-badge <?php echo e($s['status']); ?>"><?php echo e($s['status']); ?></span>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<script>
// Coach card selection highlight
document.querySelectorAll('.coach-card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.coach-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
    });
});
</script>

<?php include '../includes/footer.php'; ?>
