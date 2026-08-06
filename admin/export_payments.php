<?php
/**
 * admin/export_payments.php
 * Export payments as CSV for accounting/tax purposes.
 */
ob_start(); // Buffer the admin header HTML so the CSV download branch stays clean.
include_once '../includes/admin_header.php';
require_once '../config/db_connect.php';
require_once '../includes/activity_log.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

// Handle CSV download — prefer POST (CSRF-protected via the admin header
// central enforcement). The GET fallback is kept for backward compatibility.
$is_download = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'download')
            || (($_GET['action'] ?? '') === 'download');
if ($is_download) {
    $from = $_POST['from'] ?? $_GET['from'] ?? date('Y-01-01');
    $to   = $_POST['to']   ?? $_GET['to']   ?? date('Y-m-d');

    $stmt = $conn->prepare("
        SELECT p.payment_id, CONCAT(m.first_name,' ',m.last_name) AS member_name,
               m.email, p.amount, p.payment_method, p.description,
               p.payment_status, p.payment_date, p.provider_reference
        FROM payments p
        JOIN members m ON m.member_id = p.member_id
        WHERE DATE(p.payment_date) BETWEEN ? AND ?
        ORDER BY p.payment_date DESC
    ");
    $stmt->bind_param("ss", $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    log_activity($conn, 'Exported payments CSV', 'Payments', null, "From: $from To: $to");
    $conn->close();

    // Discard any HTML already emitted by admin_header.php before the CSV.
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="payments_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Payment ID','Member Name','Email','Amount (KES)','Method','Description','Status','Date','Reference']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['payment_id'], $r['member_name'], $r['email'],
            number_format($r['amount'], 2), $r['payment_method'],
            $r['description'], $r['payment_status'],
            $r['payment_date'], $r['provider_reference'] ?? ''
        ]);
    }
    fclose($out);
    exit;
}

// Summary for the form
$earliest = $conn->query("SELECT MIN(DATE(payment_date)) FROM payments")->fetch_row()[0] ?? date('Y-01-01');
$conn->close();
?>

<div class="container-fluid py-4" style="max-width:680px;">
    <div class="d-flex align-items-center gap-3 mb-4">
        <div class="rounded-circle d-flex align-items-center justify-content-center"
             style="width:48px;height:48px;background:#1d5c8f;">
            <i class="fas fa-file-csv text-white"></i>
        </div>
        <div>
            <h1 class="mb-0 fw-bold fs-4">Export Payments</h1>
            <p class="text-muted mb-0 small">Download payment records as CSV for accounting</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <form method="POST" action="">
                <input type="hidden" name="action" value="download">
                <?php echo csrf_field('admin_csrf'); ?>
                <div class="row g-3 mb-4">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">From Date</label>
                        <input type="date" name="from" class="form-control"
                               value="<?php echo date('Y-01-01'); ?>"
                               min="<?php echo e($earliest); ?>"
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">To Date</label>
                        <input type="date" name="to" class="form-control"
                               value="<?php echo date('Y-m-d'); ?>"
                               min="<?php echo e($earliest); ?>"
                               max="<?php echo date('Y-m-d'); ?>">
                    </div>
                </div>

                <div class="alert alert-light border mb-4 small">
                    <strong>The CSV includes:</strong> Payment ID, Member Name, Email, Amount, Method, Description, Status, Date, Provider Reference
                </div>

                <!-- Quick range buttons -->
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <?php
                    $ranges = [
                        'This Month'     => [date('Y-m-01'), date('Y-m-d')],
                        'Last Month'     => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last month'))],
                        'This Year'      => [date('Y-01-01'), date('Y-m-d')],
                        'Last 30 Days'   => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
                        'Last 90 Days'   => [date('Y-m-d', strtotime('-90 days')), date('Y-m-d')],
                    ];
                    foreach ($ranges as $label => [$from, $to]):
                    ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary"
                            onclick="setRange('<?php echo $from; ?>','<?php echo $to; ?>')">
                        <?php echo $label; ?>
                    </button>
                    <?php endforeach; ?>
                </div>

                <button type="submit" class="btn btn-primary w-100 btn-lg">
                    <i class="fas fa-download me-2"></i>Download CSV
                </button>
            </form>
        </div>
    </div>
</div>

<script>
function setRange(from, to) {
    document.querySelector('[name=from]').value = from;
    document.querySelector('[name=to]').value   = to;
}
</script>
