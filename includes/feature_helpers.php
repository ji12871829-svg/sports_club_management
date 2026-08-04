<?php

function db_table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);

    $sql = "SHOW TABLES LIKE '$table'";
    $result = $conn->query($sql);

    return $result && $result->num_rows > 0;
}

function db_column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?";

    if (!$stmt = $conn->prepare($sql)) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();

    $stmt->close();

    return (int)$count > 0;
}

function record_payment(
    mysqli $conn,
    int $memberId,
    float $amount,
    string $method,
    string $description,
    ?string $reference = null,
    string $source = 'admin',
    string $status = 'Paid'
): array {

    if (!db_table_exists($conn, 'payments')) {
        return [
            'success' => false,
            'error' => 'Payments table does not exist.'
        ];
    }

    $hasReference = db_column_exists($conn, 'payments', 'provider_reference');
    $hasSource = db_column_exists($conn, 'payments', 'source');
    $hasStatus = db_column_exists($conn, 'payments', 'payment_status');

    // Prevent duplicate payment references
    if ($hasReference && !empty($reference)) {

        $sql = "SELECT payment_id
                FROM payments
                WHERE provider_reference = ?
                LIMIT 1";

        if ($stmt = $conn->prepare($sql)) {

            $stmt->bind_param('s', $reference);
            $stmt->execute();
            $stmt->bind_result($existingId);

            if ($stmt->fetch()) {
                $stmt->close();

                return [
                    'success' => true,
                    'payment_id' => (int)$existingId,
                    'duplicate' => true
                ];
            }

            $stmt->close();
        }
    }

    $columns = [
        'member_id',
        'amount',
        'payment_method',
        'description'
    ];

    $types = 'idss';

    $values = [
        $memberId,
        $amount,
        $method,
        $description
    ];

    if ($hasReference) {
        $columns[] = 'provider_reference';
        $types .= 's';
        $values[] = $reference;
    }

    if ($hasSource) {
        $columns[] = 'source';
        $types .= 's';
        $values[] = $source;
    }

    if ($hasStatus) {
        $columns[] = 'payment_status';
        $types .= 's';
        $values[] = $status;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));

    $sql = "INSERT INTO payments (" .
        implode(', ', $columns) .
        ") VALUES ($placeholders)";

    if (!$stmt = $conn->prepare($sql)) {

        return [
            'success' => false,
            'error' => 'Could not prepare payment insert.'
        ];
    }

    // Dynamic bind_param
    $bindParams = [];
    $bindParams[] = &$types;

    foreach ($values as $key => $value) {
        $bindParams[] = &$values[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);

    if (!$stmt->execute()) {

        $error = $stmt->error;
        $stmt->close();

        return [
            'success' => false,
            'error' => $error
        ];
    }

    $paymentId = $stmt->insert_id;

    $stmt->close();

    return [
        'success' => true,
        'payment_id' => (int)$paymentId,
        'duplicate' => false
    ];
}

function get_active_membership(mysqli $conn, int $memberId): ?array
{
    if (
        !db_table_exists($conn, 'member_memberships') ||
        !db_table_exists($conn, 'membership_plans')
    ) {
        return null;
    }

    $sql = "
        SELECT
            mm.membership_id,
            mm.start_date,
            mm.end_date,
            mm.status,
            mp.name AS plan_name,
            mp.price,
            mp.duration_days
        FROM member_memberships mm
        JOIN membership_plans mp
            ON mp.plan_id = mm.plan_id
        WHERE mm.member_id = ?
          AND mm.status = 'Active'
          AND mm.end_date >= CURDATE()
        ORDER BY mm.end_date DESC
        LIMIT 1
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return null;
    }

    $stmt->bind_param('i', $memberId);

    $stmt->execute();

    $result = $stmt->get_result();

    $membership = $result->fetch_assoc() ?: null;

    $stmt->close();

    return $membership;
}

function activate_membership_for_payment(
    mysqli $conn,
    int $memberId,
    int $planId,
    ?int $paymentId = null
): bool {

    if (
        $planId <= 0 ||
        !db_table_exists($conn, 'member_memberships') ||
        !db_table_exists($conn, 'membership_plans')
    ) {
        return false;
    }

    $durationDays = 0;

    $sql = "
        SELECT duration_days
        FROM membership_plans
        WHERE plan_id = ?
          AND status = 'Active'
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return false;
    }

    $stmt->bind_param('i', $planId);

    $stmt->execute();

    $stmt->bind_result($durationDays);

    if (!$stmt->fetch()) {
        $stmt->close();
        return false;
    }

    $stmt->close();

    // Idempotency guard: if this exact payment already created a membership
    // (e.g. a duplicate callback re-entering this path), do not insert again.
    if ($paymentId !== null && $paymentId > 0) {
        $guard = $conn->prepare(
            "SELECT membership_id FROM member_memberships WHERE payment_id = ? LIMIT 1"
        );
        if ($guard) {
            $guard->bind_param('i', $paymentId);
            $guard->execute();
            $guard->bind_result($existingMembershipId);
            $alreadyActivated = $guard->fetch() && $existingMembershipId > 0;
            $guard->close();
            if ($alreadyActivated) {
                return true; // already active from this payment — idempotent
            }
        }
    }

    // Determine membership start date
    $currentMembership = get_active_membership($conn, $memberId);

    $start = new DateTime('today');

    if ($currentMembership) {
        $start = new DateTime($currentMembership['end_date']);
        $start->modify('+1 day');
    }

    $end = clone $start;

    $days = max(1, (int)$durationDays);

    $end->modify('+' . ($days - 1) . ' days');

    $startDate = $start->format('Y-m-d');
    $endDate = $end->format('Y-m-d');

    $sql = "
        INSERT INTO member_memberships (
            member_id,
            plan_id,
            payment_id,
            start_date,
            end_date,
            status
        )
        VALUES (?, ?, ?, ?, ?, 'Active')
    ";

    if (!$stmt = $conn->prepare($sql)) {
        return false;
    }

    $stmt->bind_param(
        'iiiss',
        $memberId,
        $planId,
        $paymentId,
        $startDate,
        $endDate
    );

    $success = $stmt->execute();

    $stmt->close();

    return $success;
}

/**
 * Find members holding overlapping Active memberships for the same plan —
 * typically a double-activated payment or a manual data-entry error.
 * Sequential renewals (one period ending before the next starts) are NOT
 * flagged. Rows with NULL start/end dates are skipped by the overlap
 * comparison (NULL comparisons evaluate to false) — intended, since an
 * overlap cannot be proven without dates. Add an index on
 * (member_id, plan_id, status) if this table ever grows large.
 *
 * @return array<int, array{
 *   member_id: int, first_name: string, last_name: string,
 *   plan_id: int, plan_name: ?string, overlap_count: int
 * }>
 */
function find_duplicate_memberships(mysqli $conn): array
{
    if (!db_table_exists($conn, 'member_memberships')) {
        return [];
    }

    $sql = "SELECT m.member_id, m.first_name, m.last_name, mm.plan_id,
                   p.name AS plan_name, COUNT(*) AS overlap_count
            FROM member_memberships mm
            JOIN members m ON m.member_id = mm.member_id
            LEFT JOIN membership_plans p ON p.plan_id = mm.plan_id
            WHERE mm.status = 'Active'
              AND EXISTS (
                  SELECT 1 FROM member_memberships mm2
                  WHERE mm2.member_id = mm.member_id
                    AND mm2.plan_id = mm.plan_id
                    AND mm2.status = 'Active'
                    AND mm2.membership_id <> mm.membership_id
                    AND mm2.start_date <= mm.end_date
                    AND mm2.end_date >= mm.start_date
              )
            GROUP BY m.member_id, m.first_name, m.last_name, mm.plan_id, p.name
            ORDER BY m.last_name, m.first_name, mm.plan_id";

    $rows = [];
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();
    }
    return $rows;
}
