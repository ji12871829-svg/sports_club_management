<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/promo_codes.php';
require_once '../includes/csrf.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'promo_csrf') && asc_promo_ready($conn)) {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $desc = trim($_POST['description'] ?? '');
    $dtype = $_POST['discount_type'] ?? 'percent';
    $dval = (float) ($_POST['discount_value'] ?? 0);
    $min = (float) ($_POST['min_amount'] ?? 0);
    $vf = !empty($_POST['valid_from']) ? $_POST['valid_from'] : null;
    $vu = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
    $max = $_POST['max_uses'] !== '' ? (int) $_POST['max_uses'] : null;
    $status = $_POST['status'] ?? 'Active';

    $stmt = $conn->prepare(
        'INSERT INTO promo_codes (code, description, discount_type, discount_value, min_amount, valid_from, valid_until, max_uses, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('sssddssis', $code, $desc, $dtype, $dval, $min, $vf, $vu, $max, $status);
    $message = $stmt->execute() ? '<div class="alert alert-success m-0 mb-3">Promo code created successfully.</div>' : '<div class="alert alert-danger m-0 mb-3">Could not create promo code. Code might already exist.</div>';
    $stmt->close();
}

$codes = asc_promo_ready($conn) ? $conn->query('SELECT * FROM promo_codes ORDER BY created_at DESC')->fetch_all(MYSQLI_ASSOC) : [];
?>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <h2 class="mb-0">🎟️ Discount &amp; Promo Codes</h2>
    <span class="badge bg-primary">Currency: KES</span>
  </div>

  <?php echo $message; ?>

  <?php if (!asc_promo_ready($conn)): ?>
    <div class="alert alert-warning">Run migration <code>011_competition_and_ops_features.sql</code> first.</div>
  <?php else: ?>
  <div class="row g-4">
    
    <div class="col-xl-4">
      <div class="card shadow-sm">
        <div class="card-header bg-dark text-white fw-bold">Create New Promo Code</div>
        <div class="card-body">
          <form method="post" class="row g-3">
            <?php echo csrf_field('promo_csrf'); ?>
            
            <div class="col-12">
              <label class="form-label small fw-bold">Voucher Code</label>
              <input name="code" class="form-control text-uppercase fw-bold" placeholder="e.g. WINNER50" required>
            </div>

            <div class="col-12">
              <label class="form-label small fw-bold">Description / Campaign</label>
              <input name="description" class="form-control" placeholder="e.g. Mashujaa Day special discount">
            </div>
            
            <div class="col-md-6">
              <label class="form-label small fw-bold">Type</label>
              <select name="discount_type" class="form-select">
                <option value="percent">Percentage (%)</option>
                <option value="fixed">Fixed Amount (KES)</option>
              </select>
            </div>
            
            <div class="col-md-6">
              <label class="form-label small fw-bold">Discount Value</label>
              <input name="discount_value" type="number" step="0.01" min="0" class="form-control" placeholder="0.00" required>
            </div>
            
            <div class="col-md-6">
              <label class="form-label small fw-bold">Min Basket Value</label>
              <input name="min_amount" type="number" step="0.01" min="0" class="form-control" value="0.00">
            </div>
            
            <div class="col-md-6">
              <label class="form-label small fw-bold">Usage Limit (Max Uses)</label>
              <input name="max_uses" type="number" min="1" class="form-control" placeholder="Unlimited">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-bold">Valid From</label>
              <input name="valid_from" type="date" class="form-control">
            </div>

            <div class="col-md-6">
              <label class="form-label small fw-bold">Valid Until</label>
              <input name="valid_until" type="date" class="form-control">
            </div>

            <div class="col-12">
              <label class="form-label small fw-bold">Initial Status</label>
              <select name="status" class="form-select">
                <option value="Active">Active</option>
                <option value="Suspended">Suspended</option>
              </select>
            </div>
            
            <div class="col-12 pt-2">
              <button class="btn btn-primary w-100">Generate Coupon</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    
    <div class="col-xl-8">
      <div class="card shadow-sm">
        <div class="card-header bg-light fw-bold">Active & Historical Campaigns</div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th>Code</th>
                <th>Details / Eligibility</th>
                <th>Discount Rate</th>
                <th class="text-center">Redemptions</th>
                <th class="text-center">Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($codes)): ?>
                <tr>
                  <td colspan="5" class="text-center text-muted py-4">No promotional codes found in database.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($codes as $c): ?>
                  <tr>
                    <td><code class="fw-bold fs-6 text-dark bg-light px-2 py-1 border rounded"><?php echo e($c['code']); ?></code></td>
                    <td>
                      <div class="fw-semibold text-dark mb-0"><?php echo e($c['description'] ?: 'No description provided'); ?></div>
                      <small class="text-muted d-block">
                        Minimum Order: <strong>KES <?php echo number_format((float)$c['min_amount'], 2); ?></strong>
                        <?php if($c['valid_from'] || $c['valid_until']): ?>
                          <br>🗓️ <?php echo e($c['valid_from'] ?? 'Always'); ?> to <?php echo e($c['valid_until'] ?? 'Forever'); ?>
                        <?php endif; ?>
                      </small>
                    </td>
                    <td class="fw-semibold text-success">
                      <?php if ($c['discount_type'] === 'percent'): ?>
                        <?php echo e($c['discount_value']); ?>% Off
                      <?php else: ?>
                        KES <?php echo number_format((float)$c['discount_value'], 2); ?> Off
                      <?php endif; ?>
                    </td>
                    <td class="text-center fw-medium">
                      <?php echo (int) ($c['uses_count'] ?? 0); ?> 
                      <span class="text-muted small">/ <?php echo $c['max_uses'] ? (int) $c['max_uses'] : '∞'; ?></span>
                    </td>
                    <td class="text-center">
                      <?php 
                        switch(strtolower($c['status'])) {
                            case 'active':
                                echo '<span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">Active</span>';
                                break;
                            case 'suspended':
                                echo '<span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">Suspended</span>';
                                break;
                            case 'expired':
                                echo '<span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">Expired</span>';
                                break;
                            default:
                                echo '<span class="badge bg-secondary px-2 py-1">' . e($c['status']) . '</span>';
                        }
                      ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>
  <?php endif; ?>
</div>

<?php $conn->close(); ?>