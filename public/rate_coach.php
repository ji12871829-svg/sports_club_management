<?php
require_once '../includes/session_config.php';
asc_session_start();
if (empty($_SESSION['loggedin'])) { header('Location: login.php'); exit; }
require_once '../config/db_connect.php';
require_once '../includes/csrf.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int)$_SESSION['member_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'rate_csrf')) {
    $coach_id = (int)$_POST['coach_id'];
    $rating = max(1, min(5, (int)$_POST['rating']));
    $comment = trim($_POST['comment'] ?? '');
    $stmt = $conn->prepare("INSERT INTO coach_ratings (coach_id, member_id, rating, comment) VALUES (?,?,?,?)");
    $stmt->bind_param('iiis', $coach_id, $member_id, $rating, $comment);
    if ($stmt->execute()) {
        $message = '<div class="alert alert-success">Thank you for your feedback.</div>';
    } else {
        $message = '<div class="alert alert-warning">You may have already rated this coach recently.</div>';
    }
    $stmt->close();
}

$coaches = $conn->query("SELECT c.coach_id, c.first_name, c.last_name, c.specialization,
    ROUND(AVG(r.rating),1) AS avg_rating, COUNT(r.rating_id) AS reviews
    FROM coaches c
    LEFT JOIN coach_ratings r ON r.coach_id = c.coach_id
    GROUP BY c.coach_id ORDER BY c.first_name")->fetch_all(MYSQLI_ASSOC);
$conn->close();
include '../includes/header.php';
?>
<div class="container py-4" style="max-width:640px;">
    <h2 class="fw-bold mb-3">Rate a coach</h2>
    <?php echo $message; ?>
    <?php foreach ($coaches as $c): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <h5 class="fw-bold mb-1"><?php echo e($c['first_name'].' '.$c['last_name']); ?></h5>
            <p class="text-muted small mb-2"><?php echo e($c['specialization'] ?? 'Coach'); ?> · <?php echo $c['avg_rating'] ? e($c['avg_rating']).'/5 ('.$c['reviews'].' reviews)' : 'No ratings yet'; ?></p>
            <form method="post">
                <?php echo csrf_field('rate_csrf'); ?>
                <input type="hidden" name="coach_id" value="<?php echo (int)$c['coach_id']; ?>">
                <select name="rating" class="form-select form-select-sm mb-2" required>
                    <?php for ($i=5;$i>=1;$i--): ?><option value="<?php echo $i; ?>"><?php echo $i; ?> stars</option><?php endfor; ?>
                </select>
                <textarea name="comment" class="form-control form-control-sm mb-2" rows="2" placeholder="Comment (optional)"></textarea>
                <button class="btn btn-sm btn-primary">Submit rating</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php include '../includes/footer.php'; ?>
