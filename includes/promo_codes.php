<?php

require_once __DIR__ . '/feature_helpers.php';

function asc_promo_ready(mysqli $conn): bool
{
    return db_table_exists($conn, 'promo_codes');
}

function asc_validate_promo_code(mysqli $conn, string $code, float $amount): array
{
    if (!asc_promo_ready($conn)) {
        return ['ok' => false, 'error' => 'Promo codes are not enabled.', 'discount' => 0.0, 'promo_id' => 0];
    }

    $code = strtoupper(trim($code));
    if ($code === '') {
        return ['ok' => false, 'error' => 'Enter a promo code.', 'discount' => 0.0, 'promo_id' => 0];
    }

    $stmt = $conn->prepare(
        "SELECT promo_id, discount_type, discount_value, min_amount, max_uses, uses_count, valid_from, valid_until, status
         FROM promo_codes WHERE code = ? LIMIT 1"
    );
    $stmt->bind_param('s', $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || $row['status'] !== 'Active') {
        return ['ok' => false, 'error' => 'Invalid or inactive promo code.', 'discount' => 0.0, 'promo_id' => 0];
    }

    $today = date('Y-m-d');
    if ($row['valid_from'] && $today < $row['valid_from']) {
        return ['ok' => false, 'error' => 'This promo code is not active yet.', 'discount' => 0.0, 'promo_id' => 0];
    }
    if ($row['valid_until'] && $today > $row['valid_until']) {
        return ['ok' => false, 'error' => 'This promo code has expired.', 'discount' => 0.0, 'promo_id' => 0];
    }
    if ($row['max_uses'] !== null && (int) $row['uses_count'] >= (int) $row['max_uses']) {
        return ['ok' => false, 'error' => 'This promo code has reached its usage limit.', 'discount' => 0.0, 'promo_id' => 0];
    }
    if ($amount < (float) $row['min_amount']) {
        return ['ok' => false, 'error' => 'Order amount is below the minimum for this code.', 'discount' => 0.0, 'promo_id' => 0];
    }

    $discount = $row['discount_type'] === 'percent'
        ? round($amount * ((float) $row['discount_value'] / 100), 2)
        : min($amount, (float) $row['discount_value']);

    return [
        'ok' => true,
        'error' => '',
        'discount' => $discount,
        'promo_id' => (int) $row['promo_id'],
        'code' => $code,
    ];
}

function asc_redeem_promo_code(mysqli $conn, int $promo_id): void
{
    if (!asc_promo_ready($conn) || $promo_id <= 0) {
        return;
    }

    $stmt = $conn->prepare('UPDATE promo_codes SET uses_count = uses_count + 1 WHERE promo_id = ?');
    $stmt->bind_param('i', $promo_id);
    $stmt->execute();
    $stmt->close();
}
