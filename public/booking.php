<?php
// ============================================================
//  public/booking.php
//  APIs Added:
//    ✅ Brevo Email API — sends booking confirmation email
// ============================================================
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/send_email.php';

$member_id = $_SESSION["member_id"];
$sport_id = $facility_id = $booking_date = $start_time = $end_time = "";
$sport_id_err = $facility_id_err = $booking_date_err = $start_time_err = $end_time_err = "";
$booking_success = $booking_error = "";

// Pre-select from query string if coming from sport/facility page
$preselect_sport    = intval($_GET['sport_id']    ?? 0);
$preselect_facility = intval($_GET['facility_id'] ?? 0);
$preselect_coach    = intval($_GET['coach_id']    ?? 0);

// Fetch sports
$sports = [];
$sql_sports = "SELECT sport_id, name FROM sports ORDER BY name";
if ($result = $conn->query($sql_sports)) {
    while ($row = $result->fetch_assoc()) $sports[] = $row;
    $result->free();
}

// Fetch facilities
$facilities = [];
$sql_facilities = "SELECT facility_id, name, location FROM facilities ORDER BY name";
if ($result = $conn->query($sql_facilities)) {
    while ($row = $result->fetch_assoc()) $facilities[] = $row;
    $result->free();
}

// Fetch coaches
$coaches = [];
$sql_coaches = "SELECT coach_id, first_name, last_name, specialization FROM coaches ORDER BY first_name";
if ($result = $conn->query($sql_coaches)) {
    while ($row = $result->fetch_assoc()) $coaches[] = $row;
    $result->free();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ── Validate sport ────────────────────────────────────────
    if (empty(trim($_POST["sport_id"]))) {
        $sport_id_err = "Please select a sport.";
    } else {
        $sport_id = trim($_POST["sport_id"]);
    }

    // ── Validate facility ─────────────────────────────────────
    if (empty(trim($_POST["facility_id"]))) {
        $facility_id_err = "Please select a facility.";
    } else {
        $facility_id = trim($_POST["facility_id"]);
    }

    // ── Validate date ─────────────────────────────────────────
    if (empty(trim($_POST["booking_date"]))) {
        $booking_date_err = "Please select a date.";
    } elseif (strtotime($_POST["booking_date"]) < strtotime(date('Y-m-d'))) {
        $booking_date_err = "Please select a future date.";
    } else {
        $booking_date = trim($_POST["booking_date"]);
    }

    // ── Validate times ────────────────────────────────────────
    if (empty(trim($_POST["start_time"]))) {
        $start_time_err = "Please select a start time.";
    } else {
        $start_time = trim($_POST["start_time"]);
    }

    if (empty(trim($_POST["end_time"]))) {
        $end_time_err = "Please select an end time.";
    } elseif (!empty($start_time) && $_POST["end_time"] <= $start_time) {
        $end_time_err = "End time must be after start time.";
    } else {
        $end_time = trim($_POST["end_time"]);
    }

    $coach_id = !empty($_POST["coach_id"]) ? trim($_POST["coach_id"]) : null;

    if (empty($sport_id_err) && empty($facility_id_err) && empty($booking_date_err)
        && empty($start_time_err) && empty($end_time_err)) {

        $sql = "INSERT INTO bookings (member_id, facility_id, coach_id, sport_id, booking_date, start_time, end_time, status)
                VALUES (?,?,?,?,?,?,?,'Pending')";

        if ($stmt = $conn->prepare($sql)) {
            $coach_id = $coach_id !== "" ? $coach_id : null;

            $stmt->bind_param(
                "iiissss",
                $member_id,
                $facility_id,
                $coach_id,
                $sport_id,
                $booking_date,
                $start_time,
                $end_time
            );

            if ($stmt->execute()) {
                $booking_success = "Booking submitted successfully! You will receive a confirmation email.";

                // ── Send Confirmation Email via Brevo ─────────
                sendBookingConfirmationFromPost(
                    $_POST,
                    $_SESSION['email'],
                    $_SESSION['first_name'],
                    $sports,
                    $facilities
                );

                header("Location: view_bookings.php");
                exit;
            } else {
                $booking_error = "Something went wrong. Please try again. " . $stmt->error;
            }
            $stmt->close();
        }
    }
    $conn->close();
}
?>

<?php include '../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-calendar-plus me-2"></i>Make a New Booking</h2>
            </div>
            <div class="card-body">

                <?php if (!empty($booking_success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i><?php echo $booking_success; ?>
                        <br><small>📧 A confirmation email has been sent to <strong><?php echo htmlspecialchars($_SESSION['email']); ?></strong></small>
                    </div>
                <?php endif; ?>

                <?php if (!empty($booking_error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $booking_error; ?>
                    </div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">

                    <div class="mb-3">
                        <label for="sport_id" class="form-label">Sport <span class="text-danger">*</span></label>
                        <select name="sport_id" id="sport_id"
                                class="form-select <?php echo (!empty($sport_id_err)) ? 'is-invalid' : ''; ?>">
                            <option value="">— Select a Sport —</option>
                            <?php foreach ($sports as $sport): ?>
                                <option value="<?php echo $sport['sport_id']; ?>"
                                    <?php echo ($sport_id == $sport['sport_id'] || $preselect_sport == $sport['sport_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($sport['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="invalid-feedback"><?php echo $sport_id_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="facility_id" class="form-label">Facility <span class="text-danger">*</span></label>
                        <select name="facility_id" id="facility_id"
                                class="form-select <?php echo (!empty($facility_id_err)) ? 'is-invalid' : ''; ?>">
                            <option value="">— Select a Facility —</option>
                            <?php foreach ($facilities as $facility): ?>
                                <option value="<?php echo $facility['facility_id']; ?>"
                                    <?php echo ($facility_id == $facility['facility_id'] || $preselect_facility == $facility['facility_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($facility['name'] . ' (' . $facility['location'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="invalid-feedback"><?php echo $facility_id_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="coach_id" class="form-label">Coach <span class="text-muted">(Optional)</span></label>
                        <select name="coach_id" id="coach_id" class="form-select">
                            <option value="">— No Coach / Self-Practice —</option>
                            <?php foreach ($coaches as $coach): ?>
                                <option value="<?php echo $coach['coach_id']; ?>"
                                    <?php echo ($preselect_coach == $coach['coach_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($coach['first_name'] . ' ' . $coach['last_name']
                                        . ' — ' . $coach['specialization']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label for="booking_date" class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" name="booking_date" id="booking_date"
                               class="form-control <?php echo (!empty($booking_date_err)) ? 'is-invalid' : ''; ?>"
                               value="<?php echo $booking_date; ?>"
                               min="<?php echo date('Y-m-d'); ?>">
                        <span class="invalid-feedback"><?php echo $booking_date_err; ?></span>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="start_time" class="form-label">Start Time <span class="text-danger">*</span></label>
                            <input type="time" name="start_time" id="start_time"
                                   class="form-control <?php echo (!empty($start_time_err)) ? 'is-invalid' : ''; ?>"
                                   value="<?php echo $start_time; ?>"
                                   min="00:00">
                            <span class="invalid-feedback"><?php echo $start_time_err; ?></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="end_time" class="form-label">End Time <span class="text-danger">*</span></label>
                            <input type="time" name="end_time" id="end_time"
                                   class="form-control <?php echo (!empty($end_time_err)) ? 'is-invalid' : ''; ?>"
                                   value="<?php echo $end_time; ?>"
                                   max="23:59">
                            <span class="invalid-feedback"><?php echo $end_time_err; ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-calendar-check me-1"></i>Book Now
                        </button>
                        <a href="view_bookings.php" class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-list me-1"></i>My Bookings
                        </a>
                    </div>

                    <p class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i>
                        A confirmation email will be sent to <strong><?php echo htmlspecialchars($_SESSION['email']); ?></strong> after booking.
                    </p>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>