<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/coach_availability.php';
require_once '../includes/csrf.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';
$days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'coach_avail_csrf') && asc_coach_calendar_ready($conn)) {
    
    // ACTION: Add Weekly Recurring Slot
    if (($_POST['action'] ?? '') === 'add_slot') {
        $stmt = $conn->prepare(
            'INSERT INTO coach_availability (coach_id, day_of_week, start_time, end_time, is_available, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $coach_id = (int) $_POST['coach_id'];
        $dow = (int) $_POST['day_of_week'];
        $avail = (int) ($_POST['is_available'] ?? 1);
        $notes = trim($_POST['notes'] ?? '');
        
        // Standardize time formatting securely to H:i:s
        $start_time = !empty($_POST['start_time']) ? date('H:i:s', strtotime($_POST['start_time'])) : null;
        $end_time = !empty($_POST['end_time']) ? date('H:i:s', strtotime($_POST['end_time'])) : null;

        if ($start_time && $end_time) {
            $stmt->bind_param('iissis', $coach_id, $dow, $start_time, $end_time, $avail, $notes);
            $message = $stmt->execute() ? '<div class="alert alert-success">Weekly slot saved successfully.</div>' : '<div class="alert alert-danger">Could not save slot.</div>';
        } else {
            $message = '<div class="alert alert-danger">Invalid times provided.</div>';
        }
        $stmt->close();

    // ACTION: Add Custom Date Exception
    } elseif (($_POST['action'] ?? '') === 'add_exception') {
        $stmt = $conn->prepare(
            'INSERT INTO coach_availability_exceptions (coach_id, exception_date, is_available, reason)
             VALUES (?, ?, ?, ?)'
        );
        $coach_id = (int) $_POST['coach_id'];
        $avail = (int) ($_POST['is_available'] ?? 0); // Read directly from updated selector
        $reason = trim($_POST['reason'] ?? '');
        
        $stmt->bind_param('isis', $coach_id, $_POST['exception_date'], $avail, $reason);
        $message = $stmt->execute() ? '<div class="alert alert-success">Date exception saved successfully.</div>' : '<div class="alert alert-danger">Could not save exception.</div>';
        $stmt->close();
    }
}

// Fetch lists
$coaches = $conn->query('SELECT coach_id, first_name, last_name FROM coaches ORDER BY first_name')->fetch_all(MYSQLI_ASSOC);
$slots = asc_coach_calendar_ready($conn)
    ? $conn->query(
        'SELECT ca.*, c.first_name, c.last_name FROM coach_availability ca
         JOIN coaches c ON c.coach_id = ca.coach_id ORDER BY c.first_name, ca.day_of_week, ca.start_time'
    )->fetch_all(MYSQLI_ASSOC)
    : [];
?>

<div class="container-fluid py-4">
  <h2 class="mb-4">Coach Availability Management</h2>
  <?php echo $message; ?>
  
  <?php if (!asc_coach_calendar_ready($conn)): ?>
    <div class="alert alert-warning">Run migration 011 first to initialize availability tracking tables.</div>
  <?php else: ?>
  <div class="row g-4">
    
    <div class="col-md-5">
      
      <div class="card mb-4 shadow-sm">
        <div class="card-header bg-primary text-white font-weight-bold">Add Weekly Recurring Slot</div>
        <div class="card-body">
          <form method="post">
            <?php echo csrf_field('coach_avail_csrf'); ?>
            <input type="hidden" name="action" value="add_slot">
            
            <div class="mb-3">
              <label class="form-label small text-muted">Select Coach</label>
              <select name="coach_id" class="form-select" required>
                <?php foreach ($coaches as $c): ?>
                  <option value="<?php echo (int) $c['coach_id']; ?>"><?php echo e($c['first_name'] . ' ' . $c['last_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="mb-3">
              <label class="form-label small text-muted">Day of Week</label>
              <select name="day_of_week" class="form-select">
                <?php foreach ($days as $i => $d): ?>
                  <option value="<?php echo $i; ?>"><?php echo e($d); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="row mb-3">
              <div class="col">
                <label class="form-label small text-muted">Start Time</label>
                <input type="time" name="start_time" class="form-control" required>
              </div>
              <div class="col">
                <label class="form-label small text-muted">End Time</label>
                <input type="time" name="end_time" class="form-control" required>
              </div>
            </div>
            
            <div class="mb-3">
              <label class="form-label small text-muted">Availability Status</label>
              <select name="is_available" class="form-select">
                <option value="1">Available (Active Working Hours)</option>
                <option value="0">Unavailable (Default Blocked Window)</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label small text-muted">Notes / Details</label>
              <input type="text" name="notes" class="form-control" placeholder="e.g. Senior Team Pitch Practice Only">
            </div>
            
            <button class="btn btn-primary btn-sm px-3">Add Weekly Slot</button>
          </form>
        </div>
      </div>
      
      <div class="card shadow-sm">
        <div class="card-header bg-warning text-dark font-weight-bold">Specific Date Exception</div>
        <div class="card-body">
          <form method="post">
            <?php echo csrf_field('coach_avail_csrf'); ?>
            <input type="hidden" name="action" value="add_exception">
            
            <div class="mb-3">
              <label class="form-label small text-muted">Select Coach</label>
              <select name="coach_id" class="form-select" required>
                <?php foreach ($coaches as $c): ?>
                  <option value="<?php echo (int) $c['coach_id']; ?>"><?php echo e($c['first_name'] . ' ' . $c['last_name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="mb-3">
              <label class="form-label small text-muted">Exception Date</label>
              <input type="date" name="exception_date" class="form-control" required>
            </div>

            <div class="mb-3">
              <label class="form-label small text-muted">Status For This Date</label>
              <select name="is_available" class="form-select">
                <option value="0" selected>Unavailable (Block Entire Day)</option>
                <option value="1">Available (Special Custom Override Day)</option>
              </select>
            </div>
            
            <div class="mb-3">
              <label class="form-label small text-muted">Reason / Log Note</label>
              <input type="text" name="reason" class="form-control" placeholder="e.g. Madaraka Day Break / Personal Leave" required>
            </div>
            
            <button class="btn btn-warning btn-sm px-3">Save Exception Rule</button>
          </form>
        </div>
      </div>
      
    </div>
    
    <div class="col-md-7">
      <div class="card shadow-sm">
        <div class="card-header bg-dark text-white font-weight-bold">Configured Active Slot Configurations</div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
              <thead class="table-secondary">
                <tr>
                  <th>Coach Profile</th>
                  <th>Day Range</th>
                  <th>Operational Hours</th>
                  <th>Status</th>
                  <th>Internal Notes</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($slots)): ?>
                  <tr>
                    <td colspan="5" class="text-center text-muted py-3">No active recurring slot records configured yet.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($slots as $s): ?>
                    <tr>
                      <td><strong><?php echo e($s['first_name'] . ' ' . $s['last_name']); ?></strong></td>
                      <td><span class="badge bg-light text-dark border"><?php echo e($days[(int) $s['day_of_week']] ?? ''); ?></span></td>
                      <td><code><?php echo e(substr($s['start_time'], 0, 5) . ' – ' . substr($s['end_time'], 0, 5)); ?></code></td>
                      <td>
                        <span class="badge <?php echo $s['is_available'] ? 'bg-success' : 'bg-danger'; ?>">
                          <?php echo $s['is_available'] ? 'Available' : 'Blocked / Off'; ?>
                        </span>
                      </td>
                      <td><small class="text-muted"><?php echo $s['notes'] ? e($s['notes']) : '<em>None</em>'; ?></small></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
    
  </div>
  <?php endif; ?>
</div>

<?php 
$conn->close();
include_once '../includes/footer.php'; 
?>