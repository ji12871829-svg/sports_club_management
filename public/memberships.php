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

<style>
    /* Pricing grid (kept local — used only here) */
    .pricing-card {
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        background: #ffffff;
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
    }
    .pricing-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 20px -6px rgba(16, 24, 40, 0.08);
        border-color: #b8d2e8;
    }
    .tier-title { font-size: 1.05rem; font-weight: 700; color: #111827; }
    .tier-rate { font-size: 1.55rem; font-weight: 800; color: #1d5c8f; letter-spacing: -0.5px; }
    .tier-duration { font-size: 0.72rem; font-weight: 700; letter-spacing: 0.08em; color: #6b7280; }
    .tier-description { font-size: 0.86rem; color: #6b7280; line-height: 1.5; }

    .md-alert {
        border-radius: 10px;
        padding: 0.85rem 1.15rem;
        font-size: 0.9rem;
        border: 1px solid transparent;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    .md-alert-green { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
    .md-alert-blue  { background: #f0f9ff; border-color: #bae6fd; color: #0369a1; }
    .md-alert-amber { background: #fffbec; border-color: #ffe0b2; color: #b76e00; }
</style>

<div class="py-4">
    <div class="md-page-head">
        <div>
            <h1 class="md-page-title">Manage Membership</h1>
            <p class="md-page-sub">View your current plan, compare membership tiers, and track your history</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <?php if (!$tables_ready): ?>
                <div class="md-card">
                    <div class="md-card-body">
                        <div class="md-alert md-alert-amber">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span><strong>Not ready yet:</strong> the membership tables are missing. Import <code>feature_upgrades.sql</code> to initialize them.</span>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php if ($active_membership): ?>
                    <div class="md-alert md-alert-green mb-4">
                        <i class="fas fa-check-circle"></i>
                        <span><strong>Active plan:</strong> your <b><?php echo e($active_membership['plan_name']); ?></b> membership is valid through <b><?php echo e(date('d M Y', strtotime($active_membership['end_date']))); ?></b>.</span>
                    </div>
                <?php else: ?>
                    <div class="md-alert md-alert-blue mb-4">
                        <i class="fas fa-info-circle"></i>
                        <span><strong>No active plan:</strong> choose a membership tier below to get started.</span>
                    </div>
                <?php endif; ?>

                <!-- Plan catalog -->
                <div class="row g-4 mb-4">
                    <?php foreach ($plans as $plan): ?>
                        <div class="col-xl-6 col-md-6">
                            <div class="card pricing-card h-100">
                                <div class="card-body p-4 d-flex flex-column">
                                    <h5 class="tier-title mb-2"><?php echo e($plan['name']); ?></h5>
                                    <div class="mb-1">
                                        <span class="tier-rate">KES <?php echo number_format((float) $plan['price'], 2); ?></span>
                                    </div>
                                    <div class="tier-duration text-uppercase mb-3"><?php echo e($plan['duration_days']); ?> Days</div>
                                    <p class="tier-description flex-grow-1 mb-4"><?php echo e($plan['description']); ?></p>
                                    <a class="md-btn md-btn-dark" href="payments.php?plan_id=<?php echo e($plan['plan_id']); ?>">
                                        Select Plan
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Membership history -->
                <div class="md-card">
                    <div class="md-card-head">
                        <div>
                            <h4 class="md-card-title"><i class="fas fa-clock-rotate-left"></i>Membership History</h4>
                            <small class="text-muted"><?php echo count($history); ?> membership record<?php echo count($history) === 1 ? '' : 's'; ?></small>
                        </div>
                    </div>
                    <?php if (empty($history)): ?>
                        <div class="md-empty"><i class="fas fa-medal"></i>No membership history yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table md-table align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Plan</th>
                                        <th>Start</th>
                                        <th>End</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($history as $item): ?>
                                        <?php
                                            $status_lower = strtolower($item['status']);
                                            $pill = $status_lower === 'active' ? 'md-pill-green'
                                                  : ($status_lower === 'pending' ? 'md-pill-amber' : 'md-pill-gray');
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo e($item['plan_name']); ?></td>
                                            <td><?php echo e(date('d M Y', strtotime($item['start_date']))); ?></td>
                                            <td><?php echo e(date('d M Y', strtotime($item['end_date']))); ?></td>
                                            <td><span class="md-pill <?php echo $pill; ?>"><?php echo e($item['status']); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="col-lg-4">
            <?php include __DIR__ . '/../includes/member_quick_actions.php'; ?>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>