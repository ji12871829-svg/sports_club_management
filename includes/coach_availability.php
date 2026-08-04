<?php

require_once __DIR__ . '/feature_helpers.php';

function asc_coach_calendar_ready(mysqli $conn): bool
{
    return db_table_exists($conn, 'coach_availability');
}

function asc_coach_available_at(mysqli $conn, int $coach_id, string $date, string $time): bool
{
    if (!asc_coach_calendar_ready($conn)) {
        return true;
    }

    $dow = (int) date('w', strtotime($date));

    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM coach_availability_exceptions
         WHERE coach_id = ? AND exception_date = ? AND is_available = 0'
    );
    $stmt->bind_param('is', $coach_id, $date);
    $stmt->execute();
    $stmt->bind_result($blocked);
    $stmt->fetch();
    $stmt->close();
    if ($blocked > 0) {
        return false;
    }

    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM coach_availability
         WHERE coach_id = ? AND day_of_week = ? AND is_available = 1
           AND start_time <= ? AND end_time > ?'
    );
    $stmt->bind_param('iiss', $coach_id, $dow, $time, $time);
    $stmt->execute();
    $stmt->bind_result($slots);
    $stmt->fetch();
    $stmt->close();

    if ($slots > 0) {
        return true;
    }

    $stmt = $conn->prepare('SELECT COUNT(*) FROM coach_availability WHERE coach_id = ?');
    $stmt->bind_param('i', $coach_id);
    $stmt->execute();
    $stmt->bind_result($configured);
    $stmt->fetch();
    $stmt->close();

    return $configured === 0;
}
