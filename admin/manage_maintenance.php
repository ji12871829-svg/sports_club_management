<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/facility_maintenance.php';
require_once '../includes/csrf.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'maint_csrf') && asc_maintenance_ready($conn)) {
    $stmt = $conn->prepare(
        'INSERT INTO facility_maintenance (facility_id, start_date, end_date, start_time, end_time, reason, status, blocks_bookings)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    
    $fid = (int) $_POST['facility_id'];
    $start_date = $_POST['start_date'];
    $end_date = $_POST['end_date'];
    
    // Normalize optional times or fallback safely to NULL strings
    $st = (!empty($_POST['start_time'])) ? date('H:i:s', strtotime($_POST['start_time'])) : null;
    $et = (!empty($_POST['end_time'])) ? date('H:i:s', strtotime($_POST['end_time'])) : null;
    
    $reason = trim($_POST['reason'] ?? '');
    $status = $_POST['status'] ?? 'Scheduled';
    $blocks = isset($_POST['blocks_bookings']) ? (int)$_POST['blocks_bookings'] : 1;
    
    // Bind parameters safely. (Using types 'issssssi' handles standard string conversion or null inputs dynamically)
    $stmt->bind_param('issssssi', $fid, $start_date, $end_date, $st, $et, $reason, $status, $blocks);
    $message = $stmt->execute() ? '<div class="alert alert-success">Facility maintenance window scheduled successfully.</div>' : '<div class="alert alert-danger">Save failed. Please check field configurations.</div>';
    $stmt->close();
}

$facilities = $conn->query('SELECT facility_id, name FROM facilities ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$rows = asc_maintenance_ready($conn)
    ? $conn->query(
        'SELECT m.*, f.name AS facility_name FROM facility_maintenance m
         JOIN facilities f ON f.facility_id = m.facility_id
         ORDER BY m.start_date DESC LIMIT 40'
    )->fetch_all(MYSQLI_ASSOC)
    : [];
?>

<div class="container-fluid py-4">
  <h2 class="mb-4">Facility Maintenance Scheduler</h2>
  <?php echo $message; ?>
  
  <?php if (!asc_maintenance_ready($conn)): ?>
    <div class="alert alert-warning">Run migration 011 first to initialize facility management database parameters.</div>
  <?php else: ?>
  
  <div class="card mb-4 shadow-sm">
    <div class="card-header bg-dark text-white font-weight-bold">Schedule Maintenance Window</div>
    <div class="card-body">
      <form method="post">
        <?php echo csrf_field('maint_csrf'); ?>
        <input type="hidden" name="action" value="add_maintenance">
        
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label small text-muted">Target Facility</label>
            <select name="facility_id" class="form-select" required>
              <?php foreach ($facilities as $f): ?>
                <option value="<?php echo (int) $f['facility_id']; ?>"><?php echo e($f['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          
          <div class="col-md-8">
            <label class="form-label small text-muted">Maintenance Reason / Description</label>
            <input type="text" name="reason" class="form-control" placeholder="e.g. Annual pitch aeration, floodlight repairs, or surface cleaning" required>
          </div>

          <div class="col-md-3">
            <label class="form-label small text-muted">Start Date</label>
            <input type="date" name="start_date" class="form-control" required>
          </div>
          
          <div class="col-md-3">
            <label class="form-label small text-muted">End Date</label>
            <input type="date" name="end_date" class="form-control" required>
          </div>

          <div class="col-md-3">
            <label class="form-label small text-muted">Start Time (Optional)</label>
            <input type="time" name="start_time" class="form-control">
          </div>
          
          <div class="col-md-3">
            <label class="form-label small text-muted">End Time (Optional)</label>
            <input type="time" name="end_time" class="form-control">
          </div>

          <div class="col-md-4">
            <label class="form-label small text-muted">Operational Lifecycle Status</label>
            <select name="status" class="form-select">
              <option value="Scheduled" selected>Scheduled</option>
              <option value="In Progress">In Progress</option>
              <option value="Completed">Completed</option>
              <option value="Cancelled">Cancelled</option>
            </select>
          </div>

          <div class="col-md-5 d-flex align-items-center pt-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="blocks_bookings" id="blocksBookings" value="1" checked>
              <label class="form-check-label small text-secondary" for="blocksBookings">
                <strong>Block Bookings:</strong> Automatically reject public bookings during this window
              </label>
            </div>
          </div>

          <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Log Maintenance Block</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-header bg-light font-weight-bold">Downtime Logs & Maintenance Timeline</div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-striped table-hover align-middle mb-0">
          <thead class="table-secondary">
            <tr>
              <th>Facility Asset Name</th>
              <th>Calendar Window Boundaries</th>
              <th>Task Descriptions / Reason</th>
              <th>Booking Status</th>
              <th>Lifecycle</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($rows)): ?>
              <tr>
                <td colspan="5" class="text-center text-muted py-3">No active facility maintenance operations logged yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><strong><?php echo e($r['facility_name']); ?></strong></td>
                  <td>
                    <span><?php echo e(date('d M Y', strtotime($r['start_date']))); ?> → <?php echo e(date('d M Y', strtotime($r['end_date']))); ?></span>
                    <?php if (!empty($r['start_time']) || !empty($r['end_time'])): ?>
                      <br><small class="text-muted">Clock: <code><?php echo e($r['start_time'] ? substr($r['start_time'], 0, 5) : '00:00'); ?> - <?php echo e($r['end_time'] ? substr($r['end_time'], 0, 5) : '23:59'); ?></code></small>
                    <?php endif; ?>
                  </td>
                  <td><?php echo e($r['reason']); ?></td>
                  <td>
                    <?php if ($r['blocks_bookings']): ?>
                      <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Blocked</span>
                    <?php else: ?>
                      <span class="badge bg-success-subtle text-success border border-success-subtle">Open Override</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php 
                      $statusClass = 'bg-secondary';
                      if ($r['status'] === 'Scheduled') $statusClass = 'bg-info text-dark';
                      if ($r['status'] === 'In Progress') $statusClass = 'bg-warning text-dark';
                      if ($r['status'] === 'Completed') $statusClass = 'bg-success';
                    ?>
                    <span class="badge <?php echo $statusClass; ?>"><?php echo e($r['status']); ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php 
$conn->close();
include_once '../includes/footer.php'; 
?>