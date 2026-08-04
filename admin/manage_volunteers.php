<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';

require_once __DIR__ . '/../includes/input_sanitize.php';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'event') {
        $stmt = $conn->prepare("INSERT INTO volunteer_events (title,description,event_date,event_time,venue,slots_needed) VALUES (?,?,?,?,?,?)");
        $title = trim($_POST['title'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $date = $_POST['event_date'];
        $time = $_POST['event_time'] ?: null;
        $venue = trim($_POST['venue'] ?? '');
        $slots = (int)$_POST['slots_needed'];
        $stmt->bind_param('sssssi', $title, $desc, $date, $time, $venue, $slots);
        $stmt->execute();
        $stmt->close();
        $message = '<div class="alert alert-success">Event created.</div>';
    } elseif ($action === 'confirm') {
        $sid = (int)$_POST['signup_id'];
        $conn->query("UPDATE volunteer_signups SET status='Confirmed' WHERE signup_id=$sid");
        $message = '<div class="alert alert-success">Signup confirmed.</div>';
    }
}

$events = $conn->query("SELECT * FROM volunteer_events ORDER BY event_date DESC")->fetch_all(MYSQLI_ASSOC);
$signups = $conn->query("
    SELECT s.*, e.title, e.event_date, m.first_name, m.last_name
    FROM volunteer_signups s
    JOIN volunteer_events e ON e.event_id = s.event_id
    JOIN members m ON m.member_id = s.member_id
    ORDER BY s.signed_up_at DESC LIMIT 50
")->fetch_all(MYSQLI_ASSOC);
$conn->close();
?>
<div class="container-fluid py-4">
    <h1 class="fw-bold fs-4 mb-3">Volunteer events</h1>
    <?php echo $message; ?>
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm p-3">
                <h6 class="fw-bold">New event</h6>
                <form method="post">
                    <input type="hidden" name="action" value="event">
                    <input name="title" class="form-control mb-2" placeholder="Title" required>
                    <textarea name="description" class="form-control mb-2" rows="2"></textarea>
                    <input type="date" name="event_date" class="form-control mb-2" required>
                    <input type="time" name="event_time" class="form-control mb-2">
                    <input name="venue" class="form-control mb-2" placeholder="Venue">
                    <input type="number" name="slots_needed" class="form-control mb-2" value="10" min="1">
                    <button class="btn btn-primary w-100">Create</button>
                </form>
            </div>
        </div>
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3 p-3">
                <h6 class="fw-bold">Events</h6>
                <ul class="list-group list-group-flush">
                    <?php foreach ($events as $ev): ?>
                        <li class="list-group-item"><?php echo e($ev['title']); ?> · <?php echo e($ev['event_date']); ?> · <?php echo (int)$ev['slots_needed']; ?> slots</li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>Event</th><th>Member</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($signups as $s): ?>
                            <tr>
                                <td><?php echo e($s['title']); ?></td>
                                <td><?php echo e($s['first_name'].' '.$s['last_name']); ?></td>
                                <td><?php echo e($s['status']); ?></td>
                                <td><?php if ($s['status']==='Registered'): ?><form method="post"><input type="hidden" name="action" value="confirm"><input type="hidden" name="signup_id" value="<?php echo (int)$s['signup_id']; ?>"><button class="btn btn-sm btn-success">Confirm</button></form><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
