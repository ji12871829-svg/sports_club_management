<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$member_id = (int) $_SESSION['member_id'];
$tables_ready = db_table_exists($conn, 'membership_plans') && db_table_exists($conn, 'member_memberships');
$active_membership = null;
$plans = [];
$history = [];

if ($tables_ready) {
    $active_membership = get_active_membership($conn, $member_id);

    $sql = "SELECT plan_id, name, price, duration_days, description
            FROM membership_plans
            WHERE status = 'Active'
            ORDER BY price";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $plans[] = $row;
        }
        $result->free();
    }

    $sql = "SELECT mm.start_date, mm.end_date, mm.status, mp.name AS plan_name, mp.price
            FROM member_memberships mm
            JOIN membership_plans mp ON mp.plan_id = mm.plan_id
            WHERE mm.member_id = ?
            ORDER BY mm.end_date DESC";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $history[] = $row;
        }
        $stmt->close();
    }
}

$conn->close();
?>

<?php include '../includes/header.php'; ?>

<!-- Corporate Minimalist Design Token Layer -->
<style>
    body {
        background-color: #f8fafc !important;
        color: #334155 !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    .main-workspace-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
    }

    .workspace-header {
        background-color: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.5rem 2rem;
    }

    .workspace-title {
        font-size: 1.5rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.5px;
    }

    /* Status Notifications */
    .custom-alert {
        border-radius: 8px;
        padding: 1rem 1.25rem;
        font-size: 0.92rem;
        border: 1px solid transparent;
    }
    .alert-migration-warning {
        background-color: #fffbec;
        border-color: #ffe0b2;
        color: #b76e00;
    }
    .alert-tier-active {
        background-color: #f0fdf4;
        border-color: #bbf7d0;
        color: #166534;
    }
    .alert-tier-none {
        background-color: #f0f9ff;
        border-color: #bae6fd;
        color: #0369a1;
    }

    /* Pricing Grid System */
    .pricing-card {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        background: #ffffff;
        transition: transform 0.15s ease, box-shadow 0.15s ease;
    }
    .pricing-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        border-color: #cbd5e1;
    }
    .tier-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #0f172a;
    }
    .tier-rate {
        font-size: 1.6rem;
        font-weight: 800;
        color: #2563eb;
        letter-spacing: -0.5px;
    }
    .tier-duration {
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.5px;
        color: #64748b;
    }
    .tier-description {
        font-size: 0.88rem;
        color: #475569;
        line-height: 1.5;
    }
    .btn-action-provision {
        background-color: #0f172a;
        color: #ffffff;
        border: none;
        font-weight: 600;
        font-size: 0.88rem;
        padding: 0.6rem 1rem;
        border-radius: 6px;
        transition: background-color 0.15s ease;
        text-align: center;
        text-decoration: none;
    }
    .btn-action-provision:hover {
        background-color: #1e293b;
        color: #ffffff;
    }

    /* Sub-Section Elements */
    .section-subtitle {
        font-size: 1.1rem;
        font-weight: 700;
        color: #0f172a;
        letter-spacing: -0.3px;
    }

    /* Institutional Ledger Table */
    .ledger-table-wrapper {
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        overflow: hidden;
    }
    .ledger-table {
        margin-bottom: 0;
        font-size: 0.9rem;
    }
    .ledger-table thead th {
        background-color: #f8fafc;
        color: #64748b;
        font-weight: 700;
        font-size: 0.75rem;
        text-uppercase: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #e2e8f0;
        padding: 0.75rem 1rem;
    }
    .ledger-table tbody td {
        padding: 0.85rem 1rem;
        color: #334155;
        vertical-align: middle;
        border-bottom: 1px solid #f1f5f9;
    }
    .ledger-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    /* Semantic Badges */
    .ledger-status-badge {
        font-size: 0.75rem;
        font-weight: 700;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        display: inline-block;
    }
    .status-active { background-color: #dcfce7; color: #15803d; }
    .status-expired { background-color: #f1f5f9; color: #475569; }
    .status-pending { background-color: #fef9c3; color: #a16207; }
</style>

<div class="container py-4">
    <div class="row justify-content-center">
        <div class="col-lg-11">
            <div class="card main-workspace-card">
                <div class="card-header workspace-header">
                    <h2 class="workspace-title mb-0">Membership Framework Workspace</h2>
                </div>
                <div class="card-body p-4 p-md-5">
                    <?php if (!$tables_ready): ?>
                        <div class="custom-alert alert-migration-warning mb-4">
                            <strong>System Infrastructure Notice:</strong> Membership modules are deployed within the filesystem environment, however the downstream target database scheme architecture lacks context. Please execute <code>feature_upgrades.sql</code> inside phpMyAdmin execution console to initialize metadata frameworks.
                        </div>
                    <?php else: ?>
                        <?php if ($active_membership): ?>
                            <div class="custom-alert alert-tier-active mb-4">
                                <i class="fas fa-check-circle me-2"></i><strong>Active Registry Record:</strong> System acknowledges active profile subscription tier for <b><?php echo e($active_membership['plan_name']); ?></b> valid through <b><?php echo e(date('d M Y', strtotime($active_membership['end_date']))); ?></b>.
                            </div>
                        <?php else: ?>
                            <div class="custom-alert alert-tier-none mb-4">
                                <i class="fas fa-info-circle me-2"></i><strong>No Subscription Tracked:</strong> System profile does not correlate with an active membership context. Provision a network operational tier below to authorize entry parameters.
                            </div>
                        <?php endif; ?>

                        <!-- Catalog Architecture List -->
                        <div class="row g-4 mb-5">
                            <?php foreach ($plans as $plan): ?>
                                <div class="col-xl-3 col-md-6">
                                    <div class="card pricing-card h-100">
                                        <div class="card-body p-4 d-flex flex-column">
                                            <h5 class="tier-title mb-2"><?php echo e($plan['name']); ?></h5>
                                            <div class="mb-1">
                                                <span class="tier-rate">KES <?php echo number_format((float) $plan['price'], 2); ?></span>
                                            </div>
                                            <div class="tier-duration text-uppercase mb-3"><?php echo e($plan['duration_days']); ?> Days Allocation</div>
                                            <p class="tier-description flex-grow-1 mb-4"><?php echo e($plan['description']); ?></p>
                                            <a class="btn-action-provision" href="payments.php?plan_id=<?php echo e($plan['plan_id']); ?>">
                                                Select Plan
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <hr class="my-4" style="border-color: #e2e8f0;">

                        <!-- Historic Transaction Matrix -->
                        <div class="mb-2">
                            <h5 class="section-subtitle mb-3">Historical Allocation Ledger</h5>
                            <?php if (empty($history)): ?>
                                <p class="text-muted small">No historic contextual allocations discovered for current member workspace entry profile reference.</p>
                            <?php else: ?>
                                <div class="table-responsive ledger-table-wrapper">
                                    <table class="table ledger-table">
                                        <thead>
                                            <tr>
                                                <th>Subscription Tier Parameter</th>
                                                <th>Authorization Entry</th>
                                                <th>Expiration Boundary</th>
                                                <th>Operational State</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($history as $item): ?>
                                                <?php 
                                                    $status_lower = strtolower($item['status']);
                                                    $badge_class = 'status-expired';
                                                    if ($status_lower === 'active') {
                                                        $badge_class = 'status-active';
                                                    } elseif ($status_lower === 'pending') {
                                                        $badge_class = 'status-pending';
                                                    }
                                                ?>
                                                <tr>
                                                    <td style="font-weight: 600; color: #0f172a;"><?php echo e($item['plan_name']); ?></td>
                                                    <td><?php echo e(date('d M Y', strtotime($item['start_date']))); ?></td>
                                                    <td><?php echo e(date('d M Y', strtotime($item['end_date']))); ?></td>
                                                    <td>
                                                        <span class="ledger-status-badge <?php echo $badge_class; ?>">
                                                            <?php echo e($item['status']); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>