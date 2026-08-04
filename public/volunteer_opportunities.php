<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once '../config/db_connect.php';
require_once '../includes/volunteer_management.php';
require_once __DIR__ . '/../includes/csrf.php';

// Check if user is logged in
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$member_id = $_SESSION['member_id'];
$volunteer_mgr = new VolunteerManagement($conn);

// Handle assignment
$message = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['assign_task']) && csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
    $task_id = intval($_POST['task_id']);
    $result = $volunteer_mgr->assignVolunteer($task_id, $member_id);
    $message = $result['message'];
}

// Get available tasks
$available_tasks = $volunteer_mgr->getAvailableTasks(20);

// Get member's volunteer stats
$member_stats = $volunteer_mgr->getMemberVolunteerStats($member_id);

// Get member info
$stmt = $conn->prepare("SELECT first_name, last_name FROM members WHERE member_id = ?");
$stmt->bind_param("i", $member_id);
$stmt->execute();
$member = $stmt->get_result()->fetch_assoc();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Volunteer Opportunities - Apex Sports Club</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .volunteer-container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .stat-card h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            text-transform: uppercase;
            opacity: 0.9;
        }

        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
        }

        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .tasks-section h2 {
            margin-top: 40px;
            margin-bottom: 20px;
            color: #333;
        }

        .task-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 15px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 20px;
            align-items: center;
        }

        .task-info h3 {
            margin: 0 0 10px 0;
            color: #333;
        }

        .task-meta {
            display: flex;
            gap: 20px;
            margin: 10px 0;
            flex-wrap: wrap;
        }

        .task-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 14px;
            color: #666;
        }

        .task-description {
            color: #666;
            font-size: 14px;
            margin: 10px 0;
        }

        .task-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .badge-linesman {
            background: #e3f2fd;
            color: #1976d2;
        }

        .badge-referee {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-refreshments {
            background: #fff3e0;
            color: #e65100;
        }

        .badge-setup {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .badge-cleanup {
            background: #fce4ec;
            color: #c2185b;
        }

        .badge-medical {
            background: #ffe0b2;
            color: #e65100;
        }

        .slots-info {
            text-align: center;
            padding: 10px 15px;
            background: #f5f5f5;
            border-radius: 5px;
            margin: 10px 0;
        }

        .slots-available {
            font-size: 18px;
            font-weight: bold;
            color: #2e7d32;
        }

        .slots-full {
            color: #c62828;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-primary:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .no-tasks {
            text-align: center;
            padding: 40px;
            color: #666;
        }

        .no-tasks p {
            font-size: 16px;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="volunteer-container">
        <h1>Volunteer Opportunities</h1>
        <p>Help us make Apex Sports Club amazing! Sign up for volunteer tasks and earn recognition and rewards.</p>

        <?php if ($message): ?>
            <div class="message <?php echo strpos($message, 'Successfully') !== false ? 'success' : 'error'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Volunteer Stats -->
        <?php if ($member_stats): ?>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total Hours</h3>
                    <div class="value"><?php echo number_format($member_stats['total_hours'] ?? 0, 1); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Tasks Completed</h3>
                    <div class="value"><?php echo $member_stats['tasks_completed'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Upcoming Tasks</h3>
                    <div class="value"><?php echo $member_stats['tasks_upcoming'] ?? 0; ?></div>
                </div>
                <div class="stat-card">
                    <h3>Volunteer Credits</h3>
                    <div class="value"><?php echo number_format($member_stats['total_credits'] ?? 0, 0); ?></div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Available Tasks -->
        <div class="tasks-section">
            <h2>Available Volunteer Tasks</h2>

            <?php if (empty($available_tasks)): ?>
                <div class="no-tasks">
                    <p>No volunteer opportunities available at the moment.</p>
                    <p>Check back soon!</p>
                </div>
            <?php else: ?>
                <?php foreach ($available_tasks as $task): ?>
                    <div class="task-card">
                        <div class="task-info">
                            <h3><?php echo htmlspecialchars($task['task_name']); ?></h3>
                            <p class="task-description"><?php echo htmlspecialchars($task['task_description'] ?? ''); ?></p>
                            
                            <div class="task-meta">
                                <div class="task-meta-item">
                                    <span class="task-badge badge-<?php echo $task['task_type']; ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $task['task_type'])); ?>
                                    </span>
                                </div>
                                <div class="task-meta-item">
                                    📅 <?php echo date('M d, Y', strtotime($task['match_date'])); ?>
                                </div>
                                <div class="task-meta-item">
                                    🏆 <?php echo htmlspecialchars($task['home_team'] . ' vs ' . $task['away_team']); ?>
                                </div>
                            </div>

                            <div class="slots-info">
                                <?php if ($task['slots_available'] > 0): ?>
                                    <div class="slots-available">
                                        <?php echo $task['slots_available']; ?> slot<?php echo $task['slots_available'] != 1 ? 's' : ''; ?> available
                                    </div>
                                <?php else: ?>
                                    <div class="slots-full">
                                        ✓ Task is full
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div>
                            <form method="POST" style="margin: 0;">
                                <?php echo csrf_field('member_csrf'); ?>
                                <input type="hidden" name="task_id" value="<?php echo $task['task_id']; ?>">
                                <button type="submit" name="assign_task" class="btn btn-primary" 
                                    <?php echo $task['slots_available'] <= 0 ? 'disabled' : ''; ?>>
                                    <?php echo $task['slots_available'] > 0 ? 'Sign Up' : 'Full'; ?>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div style="margin-top: 40px; text-align: center;">
            <a href="member_profile.php" class="btn btn-secondary">Back to Profile</a>
        </div>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
