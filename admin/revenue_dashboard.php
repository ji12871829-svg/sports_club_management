<?php
/**
 * admin/revenue_dashboard.php
 * Revenue charts — monthly income, payment methods, membership plans.
 */
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

// Monthly revenue — last 12 months
$monthly = $conn->query("
    SELECT DATE_FORMAT(payment_date,'%b %Y') AS month,
           DATE_FORMAT(payment_date,'%Y-%m')  AS sort_key,
           SUM(amount) AS total
    FROM payments
    WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
      AND payment_status IN ('Completed','completed','Success','success')
    GROUP BY sort_key
    ORDER BY sort_key ASC
")->fetch_all(MYSQLI_ASSOC);

// Payment method breakdown
$methods = $conn->query("
    SELECT payment_method, SUM(amount) AS total, COUNT(*) AS count
    FROM payments
    WHERE payment_status IN ('Completed','completed','Success','success')
    GROUP BY payment_method
    ORDER BY total DESC
")->fetch_all(MYSQLI_ASSOC);

// Membership plan breakdown
$plans = $conn->query("
    SELECT mp.name AS plan_name, SUM(p.amount) AS total, COUNT(*) AS count
    FROM payments p
    JOIN member_memberships mm ON mm.member_id = p.member_id
    JOIN membership_plans mp ON mp.plan_id = mm.plan_id
    WHERE p.payment_status IN ('Completed','completed','Success','success')
      AND p.description LIKE '%membership%'
    GROUP BY mp.plan_id
    ORDER BY total DESC
")->fetch_all(MYSQLI_ASSOC);

// Summary stats
$total_revenue    = (float)$conn->query("SELECT SUM(amount) FROM payments WHERE payment_status IN ('Completed','completed','Success','success')")->fetch_row()[0];
$this_month       = (float)$conn->query("SELECT SUM(amount) FROM payments WHERE payment_status IN ('Completed','completed','Success','success') AND MONTH(payment_date)=MONTH(CURDATE()) AND YEAR(payment_date)=YEAR(CURDATE())")->fetch_row()[0];
$last_month       = (float)$conn->query("SELECT SUM(amount) FROM payments WHERE payment_status IN ('Completed','completed','Success','success') AND MONTH(payment_date)=MONTH(DATE_SUB(CURDATE(),INTERVAL 1 MONTH)) AND YEAR(payment_date)=YEAR(DATE_SUB(CURDATE(),INTERVAL 1 MONTH))")->fetch_row()[0];
$total_refunded   = (float)($conn->query("SELECT SUM(amount) FROM refunds WHERE status='Processed'")->fetch_row()[0] ?? 0);

$growth = $last_month > 0 ? round((($this_month - $last_month) / $last_month) * 100, 1) : 0;

$total_expenses = 0.0;
$monthly_expenses = [];
$exp_table = $conn->query("SHOW TABLES LIKE 'club_expenses'");
if ($exp_table && $exp_table->num_rows > 0) {
    $total_expenses = (float)$conn->query("SELECT COALESCE(SUM(amount),0) FROM club_expenses")->fetch_row()[0];
    $monthly_expenses = $conn->query("
        SELECT DATE_FORMAT(expense_date,'%Y-%m') AS sort_key,
               DATE_FORMAT(expense_date,'%b %Y') AS month,
               SUM(amount) AS total
        FROM club_expenses
        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY sort_key ORDER BY sort_key
    ")->fetch_all(MYSQLI_ASSOC);
}

$conn->close();

$monthly_labels = json_encode(array_column($monthly, 'month'));
$monthly_data   = json_encode(array_map(fn($r) => round($r['total'], 2), $monthly));
$expense_by_month = [];
foreach ($monthly_expenses as $row) {
    $expense_by_month[$row['sort_key']] = (float)$row['total'];
}
$combined_labels = json_encode(array_column($monthly, 'month'));
$income_line = json_encode(array_map(fn($r) => round($r['total'], 2), $monthly));
$expense_line = json_encode(array_map(function ($r) use ($expense_by_month) {
    return round($expense_by_month[$r['sort_key'] ?? ''] ?? 0, 2);
}, $monthly));
$method_labels  = json_encode(array_column($methods, 'payment_method'));
$method_data    = json_encode(array_map(fn($r) => round($r['total'], 2), $methods));
$plan_labels    = json_encode(array_column($plans, 'plan_name'));
$plan_data      = json_encode(array_map(fn($r) => round($r['total'], 2), $plans));
?>

<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:#10b981;">
            <i class="fas fa-chart-line text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">Revenue Dashboard</h1>
            <p class="text-muted mb-0 small">Financial overview and income trends</p>
        </div>
        <div class="ms-auto d-flex gap-2">
            <a href="manage_expenses.php" class="btn btn-sm btn-outline-danger"><i class="fas fa-receipt me-1"></i> Expenses</a>
            <a href="export_payments.php" class="btn btn-sm btn-outline-secondary"><i class="fas fa-download me-1"></i> Export CSV</a>
        </div>
    </div>

    <!-- Summary stats -->
    <div class="row g-3 mb-4">
        <?php
        $net = $total_revenue - $total_expenses;
        $stats = [
            ['Total Revenue',    'KES ' . number_format($total_revenue, 2),  '#10b981', 'fa-coins'],
            ['Total Expenses',   'KES ' . number_format($total_expenses, 2), '#ef4444', 'fa-receipt'],
            ['Net (approx)',     'KES ' . number_format($net, 2),          $net >= 0 ? '#2a6ba8' : '#ef4444', 'fa-scale-balanced'],
            ['This Month',       'KES ' . number_format($this_month, 2),     '#1d5c8f', 'fa-calendar'],
        ];
        foreach ($stats as [$label, $value, $color, $icon]):
        ?>
        <div class="col-sm-6 col-xl-3">
            <div class="card border-0 shadow-sm p-4">
                <div class="d-flex align-items-center gap-3">
                    <div class="rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                         style="width:44px;height:44px;background:<?php echo $color; ?>20;">
                        <i class="fas <?php echo $icon; ?>" style="color:<?php echo $color; ?>;"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5"><?php echo e($value); ?></div>
                        <div class="text-muted small"><?php echo e($label); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Charts -->
    <div class="row g-4">
        <!-- Monthly revenue bar chart -->
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-chart-bar me-2 text-primary"></i>Income vs expenses (last 12 months)
                </div>
                <div class="card-body">
                    <canvas id="monthlyChart" height="100"></canvas>
                </div>
            </div>
        </div>

        <!-- Payment methods pie -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-chart-pie me-2 text-success"></i>By Payment Method
                </div>
                <div class="card-body">
                    <canvas id="methodChart" height="160"></canvas>
                    <div class="mt-3">
                        <?php foreach ($methods as $m): ?>
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?php echo e($m['payment_method']); ?></span>
                            <strong>KES <?php echo number_format($m['total'], 0); ?></strong>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Membership plans -->
        <?php if (!empty($plans)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    <i class="fas fa-id-card me-2 text-warning"></i>Revenue by Membership Plan
                </div>
                <div class="card-body">
                    <canvas id="planChart" height="60"></canvas>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const colors = ['#2a6ba8','#10b981','#f59e0b','#ef4444','#2a6ba8','#06b6d4','#84cc16'];

// Monthly bar chart
new Chart(document.getElementById('monthlyChart'), {
    type: 'bar',
    data: {
        labels: <?php echo $combined_labels; ?>,
        datasets: [{
            label: 'Income (KES)',
            data: <?php echo $income_line; ?>,
            backgroundColor: '#10b98155',
        },{
            label: 'Expenses (KES)',
            data: <?php echo $expense_line; ?>,
            backgroundColor: '#ef444455',
            borderColor: '#2a6ba8',
            borderWidth: 2,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => 'KES ' + v.toLocaleString() } } }
    }
});

// Method pie
new Chart(document.getElementById('methodChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo $method_labels; ?>,
        datasets: [{ data: <?php echo $method_data; ?>, backgroundColor: colors }]
    },
    options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});

<?php if (!empty($plans)): ?>
// Plans bar
new Chart(document.getElementById('planChart'), {
    type: 'bar',
    data: {
        labels: <?php echo $plan_labels; ?>,
        datasets: [{
            label: 'Revenue (KES)',
            data: <?php echo $plan_data; ?>,
            backgroundColor: colors,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { callback: v => 'KES ' + v.toLocaleString() } } }
    }
});
<?php endif; ?>
</script>
