<?php
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

$from = $_GET['from'] ?? date('Y-m-d');
$to = date('Y-m-d', strtotime($from . ' +13 days'));

$bookings = [];
$stmt = $conn->prepare("
    SELECT b.booking_date, b.start_time, b.end_time, b.status,
           f.name AS facility, m.first_name, m.last_name
    FROM bookings b
    LEFT JOIN facilities f ON f.facility_id = b.facility_id
    LEFT JOIN members m ON m.member_id = b.member_id
    WHERE b.booking_date BETWEEN ? AND ?
      AND b.status IN ('Pending','Approved','Confirmed')
    ORDER BY b.booking_date, b.start_time
");
$stmt->bind_param('ss', $from, $to);
$stmt->execute();
$res = $stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $bookings[$row['booking_date']][] = $row;
}
$stmt->close();
$conn->close();
?>
<div class="container-fluid py-4">
    <h1 class="fw-bold fs-4 mb-3">Facility booking calendar (admin)</h1>
    <form method="get" class="mb-4 d-flex gap-2">
        <input type="date" name="from" value="<?php echo e($from); ?>" class="form-control" style="max-width:200px;">
        <button class="btn btn-primary">Show 2 weeks</button>
    </form>
    <div class="row g-3">
        <?php
        $d = $from;
        while ($d <= $to):
            $list = $bookings[$d] ?? [];
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100 <?php echo count($list) > 2 ? 'border-warning border' : ''; ?>">
                <div class="card-header bg-white fw-semibold small"><?php echo e(date('D j M', strtotime($d))); ?> <span class="badge bg-secondary"><?php echo count($list); ?></span></div>
                <ul class="list-group list-group-flush small">
                    <?php if (empty($list)): ?>
                        <li class="list-group-item text-muted">No bookings</li>
                    <?php else: foreach ($list as $b): ?>
                        <li class="list-group-item">
                            <strong><?php echo e(substr($b['start_time'],0,5).'-'.substr($b['end_time'],0,5)); ?></strong>
                            <?php echo e($b['facility']); ?><br>
                            <span class="text-muted"><?php echo e($b['first_name'].' '.$b['last_name']); ?> · <?php echo e($b['status']); ?></span>
                        </li>
                    <?php endforeach; endif; ?>
                </ul>
            </div>
        </div>
        <?php $d = date('Y-m-d', strtotime($d . ' +1 day')); endwhile; ?>
    </div>
</div>
<?php include_once '../includes/footer.php'; ?>
