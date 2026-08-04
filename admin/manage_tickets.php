<?php
// Make sure session_start(); is invoked inside admin_header.php!
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/ticket_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

if (empty($_SESSION['manage_tickets_csrf'])) {
    $_SESSION['manage_tickets_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['manage_tickets_csrf'];
$message = '';
$schema_ready = ticketing_ensure_schema($conn);

if (!$schema_ready) {
    $message = '<div class="alert alert-danger border-0 shadow-sm">Ticketing tables are not ready. Import <code>ticketing_schema.sql</code>.</div>';
}

if ($schema_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf, $posted)) {
        $message = '<div class="alert alert-danger border-0 shadow-sm">Security check failed. Please refresh and try again.</div>';
    } else {
        $fixture_id = (int) ($_POST['fixture_id'] ?? 0);
        $ticket_price = (float) ($_POST['ticket_price'] ?? ticketing_default_price());
        $capacity_raw = trim($_POST['ticket_capacity'] ?? '');
        $ticket_capacity = $capacity_raw === '' ? null : max(0, (int) $capacity_raw);
        $sales_status = trim($_POST['sales_status'] ?? 'Open');

        if ($fixture_id <= 0) {
            $message = '<div class="alert alert-danger border-0 shadow-sm">Invalid fixture selected.</div>';
        } elseif (ticketing_upsert_fixture_settings($conn, $fixture_id, $ticket_price, $ticket_capacity, $sales_status)) {
            $message = '<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i>Ticket settings updated successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger border-0 shadow-sm">Could not update ticket settings.</div>';
        }
    }
}

$fixtures = [];
if ($schema_ready) {
    $sql = "SELECT f.fixture_id, f.match_date, f.match_time, f.venue, f.status,
                   h.name AS home_team, a.name AS away_team,
                   l.name AS league_name, s.name AS sport_name
            FROM fixtures f
            JOIN teams h ON h.team_id = f.home_team_id
            JOIN teams a ON a.team_id = f.away_team_id
            JOIN leagues l ON l.league_id = f.league_id
            JOIN sports s ON s.sport_id = l.sport_id
            WHERE f.match_date >= CURDATE()
            ORDER BY f.match_date, f.match_time
            LIMIT 80";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $row['ticket_info'] = ticketing_fetch_fixture_ticket_info($conn, (int) $row['fixture_id']);
            $fixtures[] = $row;
        }
        $result->free();
    }
}

$orders = [];
if ($schema_ready) {
    $sql = "SELECT o.order_id, o.quantity, o.unit_price, o.total_amount, o.payment_method,
                   o.status, o.created_at, o.paid_at,
                   o.buyer_type,
                   COALESCE(NULLIF(TRIM(CONCAT(COALESCE(m.first_name, ''), ' ', COALESCE(m.last_name, ''))), ''), o.buyer_name, 'Fan') AS buyer_name,
                   COALESCE(m.email, o.buyer_email) AS email,
                   st.name AS supported_team,
                   f.match_date, f.match_time,
                   h.name AS home_team, a.name AS away_team,
                   l.name AS league_name, s.name AS sport_name
            FROM ticket_orders o
            LEFT JOIN members m ON m.member_id = o.member_id
            JOIN fixtures f ON f.fixture_id = o.fixture_id
            JOIN teams h ON h.team_id = f.home_team_id
            JOIN teams a ON a.team_id = f.away_team_id
            JOIN leagues l ON l.league_id = f.league_id
            JOIN sports s ON s.sport_id = l.sport_id
            LEFT JOIN teams st ON st.team_id = o.supported_team_id
            ORDER BY o.created_at DESC
            LIMIT 60";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        $result->free();
    }
}

$conn->close();
?>

<style>
    body { background-color: #f8fafc !important; color: #334155 !important; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
    .page-header-text { font-size: 1.5rem; font-weight: 700; color: #0f172a; letter-spacing: -0.025em; }
    
    /* Premium Minimal Workspace Cards */
    .workspace-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); overflow: hidden; padding: 1.5rem !important; margin-bottom: 2rem; }
    .card-header-title { font-size: 1rem; font-weight: 600; color: #0f172a; margin-bottom: 1.25rem; display: flex; align-items: center; justify-content: space-between; }
    
    /* Clean Enterprise Inputs & Controls */
    .form-control, .form-select { background-color: #ffffff !important; border: 1px solid #cbd5e1 !important; border-radius: 6px !important; padding: 0.4rem 0.5rem !important; font-size: 0.875rem !important; color: #0f172a !important; }
    .form-control:focus, .form-select:focus { border-color: #0f172a !important; box-shadow: 0 0 0 2px rgba(15, 23, 42, 0.1) !important; outline: 0 !important; }
    .input-group-text { background-color: #f8fafc !important; border: 1px solid #cbd5e1 !important; border-radius: 6px 0 0 6px !important; font-size: 0.75rem !important; font-weight: 600; color: #64748b; }
    .input-group .form-control { border-radius: 0 6px 6px 0 !important; }
    
    /* Structural Data Table System */
    .table-container th { background-color: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #475569; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.75rem 1rem; }
    .table-container td { padding: 0.85rem 1rem; border-bottom: 1px solid #f1f5f9; font-size: 0.875rem; color: #334155; vertical-align: middle; }
    .table-container tr:hover td { background-color: #fafafa; }
    
    /* Workspace Specific Utility Buttons */
    .btn-action-save { background-color: #0f172a !important; color: #ffffff !important; border: none !important; border-radius: 6px !important; font-size: 0.8rem !important; font-weight: 500 !important; padding: 0.4rem 0.75rem !important; transition: background-color 0.1s ease !important; }
    .btn-action-save:hover { background-color: #1e293b !important; color: #ffffff !important; }
    .btn-secondary-outline { border: 1px solid #cbd5e1 !important; background: #ffffff !important; color: #334155 !important; border-radius: 6px !important; font-size: 0.8rem !important; font-weight: 500 !important; padding: 0.4rem 0.75rem !important; text-decoration: none; transition: all 0.1s ease; }
    .btn-secondary-outline:hover { background: #f8fafc !important; color: #0f172a !important; border-color: #94a3b8 !important; }

    /* Transaction & Sales Status Badges */
    .status-pill { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.25rem 0.5rem; font-size: 0.75rem; font-weight: 600; border-radius: 6px; line-height: 1; border: 1px solid transparent; }
    .status-open { background-color: #f0fdf4 !important; color: #16a34a !important; border-color: #bbf7d0; }
    .status-closed { background-color: #fef2f2 !important; color: #dc2626 !important; border-color: #fecaca; }
    .status-pending { background-color: #fffbeb !important; color: #d97706 !important; border-color: #fde68a; }
    
    .type-pill { display: inline-flex; align-items: center; padding: 0.2rem 0.4rem; font-size: 0.7rem; font-weight: 600; border-radius: 4px; line-height: 1; background-color: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
    .qr-thumbnail { transition: transform 0.15s ease; cursor: pointer; }
    .qr-thumbnail:hover { transform: scale(1.05); }
</style>

<div class="container-fluid py-4 px-md-4">
    <div class="d-flex align-items-center justify-content-between gap-3 mb-4">
        <div class="d-flex align-items-center gap-3">
            <div class="rounded-circle d-flex align-items-center justify-content-center bg-white border shadow-sm" style="width:46px;height:46px;">
                <i class="fas fa-ticket-alt text-dark"></i>
            </div>
            <div>
                <h1 class="page-header-text mb-0">Ticket Sales Workspace</h1>
                <p class="text-muted mb-0 small">Configure access pricing and monitor ongoing gateway operations</p>
            </div>
        </div>
        <a href="manage_fixtures.php" class="btn btn-secondary-outline"><i class="fas fa-calendar-alt me-2"></i>Fixtures Setup</a>
    </div>

    <?php if ($message) echo $message; ?>

    <div class="row">
        <div class="col-12">
            
            <div class="workspace-card p-0">
                <div class="card-header-title style-container" style="padding: 1.25rem 1.5rem 0 1.5rem; margin-bottom: 0.75rem;">
                    <span class="fw-semibold"><i class="fas fa-sliders-h text-muted me-2"></i>Fixture Ticket Settings</span>
                </div>
                
                <div class="card-body p-0">
                    <?php if (empty($fixtures)): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fas fa-calendar-times fa-2x mb-2 d-block opacity-50"></i>
                            No upcoming fixtures found for configuration.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive table-container">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Fixture</th>
                                        <th>Date / Schedule</th>
                                        <th class="text-center">Purchase QR</th>
                                        <th>Sold Metrics</th>
                                        <th style="width: 160px;">Price Allocation</th>
                                        <th style="width: 140px;">Capacity Cap</th>
                                        <th style="width: 140px;">Sales Gate</th>
                                        <th class="text-end" style="width: 100px;">Control</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fixtures as $fixture): ?>
                                        <?php $info = $fixture['ticket_info']; ?>
                                        <tr>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrf); ?>">
                                                <input type="hidden" name="fixture_id" value="<?php echo e($fixture['fixture_id']); ?>">
                                                
                                                <td>
                                                    <div class="fw-bold text-dark"><?php echo e($fixture['home_team'] . ' vs ' . $fixture['away_team']); ?></div>
                                                    <div class="text-muted" style="font-size: 0.75rem;"><?php echo e($fixture['sport_name'] . ' · ' . $fixture['league_name']); ?></div>
                                                </td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo e(date('d M Y', strtotime($fixture['match_date']))); ?></div>
                                                    <div class="text-muted small"><?php echo e(substr((string) $fixture['match_time'], 0, 5)); ?> EAT</div>
                                                </td>
                                                <td class="text-center">
                                                    <a href="<?php echo e(ticketing_purchase_url((int) $fixture['fixture_id'])); ?>" target="_blank">
                                                        <img src="<?php echo e(ticketing_purchase_qr_image_url((int) $fixture['fixture_id'])); ?>"
                                                             alt="Fixture purchase QR" width="56" height="56" class="border rounded bg-white p-1 qr-thumbnail">
                                                    </a>
                                                </td>
                                                <td>
                                                    <span class="fw-bold text-dark"><?php echo e($info['sold']); ?></span>
                                                    <?php if ($info['ticket_capacity'] !== null): ?>
                                                        <span class="text-muted" style="font-size: 0.8rem;">/ <?php echo e($info['ticket_capacity']); ?></span>
                                                    <?php else: ?>
                                                        <span class="text-muted" style="font-size: 0.75rem;">/ ∞</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <span class="input-group-text">KES</span>
                                                        <input type="number" step="0.01" min="0" name="ticket_price" class="form-control form-control-sm fw-semibold"
                                                               value="<?php echo e($info['ticket_price']); ?>">
                                                    </div>
                                                </td>
                                                <td>
                                                    <input type="number" min="0" name="ticket_capacity" class="form-control form-control-sm text-center"
                                                           placeholder="Unlimited"
                                                           value="<?php echo $info['ticket_capacity'] === null ? '' : e($info['ticket_capacity']); ?>">
                                                </td>
                                                <td>
                                                    <select name="sales_status" class="form-select form-select-sm fw-semibold <?php echo $info['sales_status'] === 'Open' ? 'text-success' : 'text-danger'; ?>">
                                                        <option value="Open" <?php echo $info['sales_status'] === 'Open' ? 'selected' : ''; ?>>Open</option>
                                                        <option value="Closed" <?php echo $info['sales_status'] === 'Closed' ? 'selected' : ''; ?>>Closed</option>
                                                    </select>
                                                </td>
                                                <td class="text-end">
                                                    <button type="submit" class="btn btn-action-save btn-sm">Update</button>
                                                </td>
                                            </form>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="workspace-card p-0">
                <div class="card-header-title style-container" style="padding: 1.25rem 1.5rem 0 1.5rem; margin-bottom: 0.75rem;">
                    <span class="fw-semibold"><i class="fas fa-history text-muted me-2"></i>Recent Ticket Orders Ledger</span>
                </div>
                
                <div class="card-body p-0">
                    <?php if (empty($orders)): ?>
                        <div class="text-center py-5 text-muted small">
                            <i class="fas fa-receipt fa-2x mb-2 d-block opacity-50"></i>
                            No transactional orders processed in the system database yet.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive table-container">
                            <table class="table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 70px;">ID</th>
                                        <th>Buyer Credentials</th>
                                        <th>Target Event Fixture</th>
                                        <th>Affiliation</th>
                                        <th class="text-center">Qty</th>
                                        <th>Total Settlement</th>
                                        <th>Gateway Method</th>
                                        <th>Status Badge</th>
                                        <th class="text-end">Timestamp</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order): ?>
                                        <tr>
                                            <td class="text-muted font-monospace" style="font-size: 0.8rem;">#<?php echo e($order['order_id']); ?></td>
                                            <td>
                                                <div class="fw-bold text-dark d-inline-block"><?php echo e($order['buyer_name']); ?></div>
                                                <span class="type-pill ms-1"><?php echo e($order['buyer_type']); ?></span>
                                                <div class="text-muted small" style="font-size: 0.75rem;"><?php echo e($order['email']); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-dark"><?php echo e($order['home_team'] . ' vs ' . $order['away_team']); ?></div>
                                                <div class="text-muted" style="font-size: 0.75rem;"><?php echo e(date('d M Y', strtotime($order['match_date']))); ?></div>
                                            </td>
                                            <td>
                                                <span class="text-slate-700 font-monospace" style="font-size: 0.8rem;"><?php echo e($order['supported_team'] ?: 'Neutral / Fan'); ?></span>
                                            </td>
                                            <td class="text-center fw-bold text-dark"><?php echo e($order['quantity']); ?></td>
                                            <td class="fw-bold text-dark">KES <?php echo number_format((float) $order['total_amount'], 2); ?></td>
                                            <td class="font-monospace text-muted" style="font-size: 0.8rem;"><?php echo e($order['payment_method']); ?></td>
                                            <td>
                                                <span class="status-pill <?php echo $order['status'] === 'Paid' ? 'status-open' : ($order['status'] === 'Pending' ? 'status-pending' : 'status-closed'); ?>">
                                                    <?php echo e($order['status']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end text-muted small">
                                                <div><?php echo e(date('d M Y', strtotime($order['created_at']))); ?></div>
                                                <div style="font-size: 0.72rem;"><?php echo e(date('H:i', strtotime($order['created_at']))); ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
        </div>
    </div>
</div>

<?php include_once '../includes/footer.php'; ?>