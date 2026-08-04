<?php

require_once __DIR__ . '/feature_helpers.php';

function asc_maintenance_ready(mysqli $conn): bool
{
    return db_table_exists($conn, 'facility_maintenance');
}

function asc_facility_blocked_for_booking(mysqli $conn, int $facility_id, string $date, string $start_time, string $end_time): bool
{
    if (!asc_maintenance_ready($conn)) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM facility_maintenance
         WHERE facility_id = ?
           AND blocks_bookings = 1
           AND status IN ('Scheduled','In Progress')
           AND ? BETWEEN start_date AND end_date
           AND (
                start_time IS NULL OR end_time IS NULL
                OR (start_time < ? AND end_time > ?)
           )"
    );
    $stmt->bind_param('isss', $facility_id, $date, $end_time, $start_time);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return $count > 0;
}
