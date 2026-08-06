<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once __DIR__ . '/../includes/input_sanitize.php';

$facility_id = (int) ($_GET['facility_id'] ?? 0);
$from_date = $_GET['from_date'] ?? date('Y-m-d');
$to_date = date('Y-m-d', strtotime($from_date . ' +30 days'));
$facilities = [];
$bookings_by_date = [];

if ($result = $conn->query("SELECT facility_id, name, location FROM facilities ORDER BY name")) {
    while ($row = $result->fetch_assoc()) {
        $facilities[] = $row;
    }
    $result->free();
}

$sql = "SELECT b.booking_id, b.booking_date, b.start_time, b.end_time, b.status,
               f.name AS facility_name, f.location AS facility_location,
               s.name AS sport_name,
               m.first_name, m.last_name
        FROM bookings b
        LEFT JOIN facilities f ON f.facility_id = b.facility_id
        LEFT JOIN sports s ON s.sport_id = b.sport_id
        LEFT JOIN members m ON m.member_id = b.member_id
        WHERE b.booking_date BETWEEN ? AND ?
          AND b.status IN ('Pending', 'Approved', 'Confirmed')
          AND (? = 0 OR b.facility_id = ?)
        ORDER BY b.booking_date ASC, b.start_time ASC";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param('ssii', $from_date, $to_date, $facility_id, $facility_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $bookings_by_date[$row['booking_date']][] = $row;
    }
    $stmt->close();
}

$conn->close();
?>

<?php include '../includes/header.php'; ?>

<style>
    body { 
        background-color: #f8fafc !important; 
        color: #334155 !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    
    .page-header-corporate {
        border-bottom: 1px solid #e2e8f0;
        margin-bottom: 2.5rem;
        padding-bottom: 1.25rem;
    }

    .corporate-title {
        color: #0f172a;
        font-weight: 700;
        letter-spacing: -0.5px;
    }

    .brand-accent-line {
        width: 40px;
        height: 4px;
        background-color: #1d5c8f;
        border-radius: 2px;
        margin-bottom: 1rem;
    }

    /* Enterprise Structuring Containers */
    .corporate-filter-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        padding: 1.5rem;
        margin-bottom: 2rem;
    }

    .corporate-block-wrapper {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 2rem;
    }

    .block-header-bar {
        background-color: #f8fafc;
        padding: 1.25rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .date-title-text {
        color: #0f172a;
        font-weight: 700;
        font-size: 1.1rem;
        margin-bottom: 0;
    }

    /* Form Controls Override */
    .form-label-corporate {
        font-size: 0.825rem;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.5rem;
    }

    .form-select-corporate, .form-control-corporate {
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        padding: 0.5rem 0.75rem;
        font-size: 0.925rem;
        color: #1e293b;
        background-color: #ffffff;
        transition: border-color 0.15s ease;
    }

    .form-select-corporate:focus, .form-control-corporate:focus {
        border-color: #1d5c8f;
        box-shadow: 0 0 0 1px #1d5c8f;
        outline: 0;
    }

    /* Data Presentation Grid Elements */
    .table-corporate {
        margin-bottom: 0;
    }

    .table-corporate thead th {
        background-color: #ffffff;
        color: #64748b;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 1rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
    }

    .table-corporate tbody td {
        padding: 1rem 1.5rem;
        vertical-align: middle;
        color: #334155;
        font-size: 0.925rem;
        border-bottom: 1px solid #f1f5f9;
    }

    .table-corporate tbody tr:last-child td {
        border-bottom: none;
    }

    .table-corporate tbody tr {
        transition: background-color 0.15s ease-in-out;
    }

    .table-corporate tbody tr:hover {
        background-color: #f8fafc;
    }

    .text-primary-dark {
        color: #0f172a;
        font-weight: 600;
    }

    .time-badge {
        font-family: SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        font-weight: 600;
        color: #1d5c8f;
        background-color: #e8f1f8;
        padding: 0.25rem 0.5rem;
        border-radius: 4px;
        font-size: 0.85rem;
    }

    /* Unified Actions Framework */
    .btn-corporate {
        font-size: 0.875rem;
        font-weight: 600;
        padding: 0.5rem 1.25rem;
        border-radius: 6px;
        transition: all 0.15s ease;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    .btn-corporate-primary {
        background-color: #1d5c8f;
        color: #ffffff !important;
    }

    .btn-corporate-primary:hover {
        background-color: #14497a;
    }

    .btn-corporate-secondary {
        background-color: #ffffff;
        border: 1px solid #cbd5e1;
        color: #475569 !important;
    }

    .btn-corporate-secondary:hover {
        background-color: #f8fafc;
        border-color: #94a3b8;
    }

    /* Contextual Allocation Badges */
    .status-pill-corporate {
        font-size: 0.775rem;
        font-weight: 600;
        padding: 0.25rem 0.625rem;
        border-radius: 50px;
        display: inline-block;
        border: 1px solid transparent;
    }

    .status-pill-pending {
        background-color: #fffbeb;
        color: #b45309;
        border-color: #fde68a;
    }

    .status-pill-approved {
        background-color: #f0fdf4;
        color: #16a34a;
        border-color: #bbf7d0;
    }

    .status-pill-confirmed {
        background-color: #ecfeff;
        color: #0891b2;
        border-color: #c5f6fa;
    }

    .corporate-empty-box {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 4rem 2rem;
        color: #64748b;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
    }
</style>

<div class="container py-5">
    
    <div class="row page-header-corporate align-items-end">
        <div class="col-md-12 d-flex justify-content-between align-items-end flex-wrap gap-3">
            <div>
                <div class="brand-accent-line"></div>
                <h1 class="corporate-title mb-2">Booking Calendar</h1>
                <p class="text-muted mb-0">Monitor spatial utilization parameters, structural time allocations, and reservation workflows.</p>
            </div>
            <div>
                <a href="booking.php" class="btn-corporate btn-corporate-primary shadow-sm">
                    New Reservation
                </a>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="corporate-filter-card">
                <form method="get" class="row g-3 align-items-end">
                    <div class="col-lg-5 col-md-4">
                        <label for="facility_id" class="form-label-corporate">Target Facility Structure</label>
                        <select name="facility_id" id="facility_id" class="form-select form-select-corporate w-100">
                            <option value="0">All Complex Facilities</option>
                            <?php foreach ($facilities as $facility): ?>
                                <option value="<?php echo e($facility['facility_id']); ?>" <?php echo $facility_id === (int) $facility['facility_id'] ? 'selected' : ''; ?>>
                                    <?php echo e($facility['name'] . ' &mdash; ' . $facility['location']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4 col-md-4">
                        <label for="from_date" class="form-label-corporate">Temporal Window Start</label>
                        <input type="date" name="from_date" id="from_date" class="form-control form-control-corporate w-100" value="<?php echo e($from_date); ?>">
                    </div>
                    <div class="col-lg-3 col-md-4">
                        <button type="submit" class="btn-corporate btn-corporate-secondary w-100">
                            Apply Filters
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="row pt-2">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 style="color: #0f172a; font-weight: 700; font-size: 1.25rem; margin: 0;">
                    Temporal Window: 30-Day Outlook
                </h4>
            </div>

            <?php if (empty($bookings_by_date)): ?>
                <div class="text-center corporate-empty-box">
                    <h4 class="font-weight-bold mb-2" style="color: #0f172a;">No Structural Allocations Mapped</h4>
                    <p class="mb-0 mx-auto text-muted" style="max-width: 460px;">There are currently no active or confirmed asset reservations configured within this requested temporal window.</p>
                </div>
            <?php else: ?>
                <?php foreach ($bookings_by_date as $date => $items): ?>
                    <div class="corporate-block-wrapper">
                        
                        <div class="block-header-bar">
                            <h3 class="date-title-text">
                                <?php echo e(date('l, d F Y', strtotime($date))); ?>
                            </h3>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-corporate table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th style="width: 18%;">Time Bound</th>
                                        <th style="width: 32%;">Facility Context</th>
                                        <th style="width: 20%;">Sport Modality</th>
                                        <th style="width: 18%;">Allocated User</th>
                                        <th style="width: 12%;" class="text-end">Status Token</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($items as $booking): ?>
                                        <?php 
                                            $status_class = 'status-pill-' . strtolower($booking['status']); 
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="time-badge">
                                                    <?php echo e(date('H:i', strtotime($booking['start_time'])) . ' - ' . date('H:i', strtotime($booking['end_time']))); ?>
                                                </span>
                                            </td>
                                            <td class="text-primary-dark">
                                                <?php echo e($booking['facility_name']); ?> 
                                                <span class="d-block text-muted small font-weight-normal" style="font-size: 0.8rem; margin-top: 2px;">
                                                    <?php echo e($booking['facility_location']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="text-muted font-weight-medium"><?php echo e($booking['sport_name']); ?></span>
                                            </td>
                                            <td>
                                                <span style="color: #475569; font-weight: 500;"><?php echo e($booking['first_name'] . ' ' . $booking['last_name']); ?></span>
                                            </td>
                                            <td class="text-end">
                                                <span class="status-pill-corporate <?php echo $status_class; ?>">
                                                    <?php echo e($booking['status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php include '../includes/footer.php'; ?>