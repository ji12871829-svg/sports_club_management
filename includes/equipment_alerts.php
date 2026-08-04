<?php

require_once __DIR__ . '/feature_helpers.php';

function asc_low_stock_equipment(mysqli $conn, int $limit = 10): array
{
    if (!$result = $conn->query('SHOW COLUMNS FROM equipment LIKE "reorder_level"')) {
        return [];
    }
    if ($result->num_rows === 0) {
        $result->free();
        return [];
    }
    $result->free();

    $sql = "SELECT equipment_id, name, quantity, reorder_level, `condition`
            FROM equipment
            WHERE quantity <= reorder_level
            ORDER BY quantity ASC, name ASC
            LIMIT " . (int) $limit;

    $rows = [];
    if ($res = $conn->query($sql)) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }

    return $rows;
}
