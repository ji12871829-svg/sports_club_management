<?php

require_once __DIR__ . '/feature_helpers.php';

function mpesa_amounts_match(float $expected, $received): bool
{
    return (int) round($expected) === (int) round((float) $received);
}

function mpesa_get_client_ip(): string {
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

function mpesa_validate_ip(string $ip): bool {
    if (defined('MPESA_ENV') && MPESA_ENV !== 'production') {
        return true;
    }

    $ip_long = ip2long($ip);
    if ($ip_long === false) {
        return false;
    }

    // Safaricom callback IP subnets and hosts
    $start = ip2long('196.201.214.200');
    $end = ip2long('196.201.214.207');
    if ($ip_long >= $start && $ip_long <= $end) {
        return true;
    }

    $allowed_ips = [
        '196.201.213.114',
        '196.201.214.208',
        '196.201.213.44',
        '196.201.212.74',
        '196.201.212.129',
        '196.201.212.132',
        '196.201.212.136',
        '196.201.212.138',
        '196.201.212.140',
        '196.201.212.144',
        '196.201.212.146',
        '196.201.212.147',
        '196.201.212.152'
    ];

    return in_array($ip, $allowed_ips, true);
}

function mpesa_ensure_schema(mysqli $conn): bool
{
    if (db_table_exists($conn, 'mpesa_pending')) {
        return true;
    }
    $sql = "CREATE TABLE IF NOT EXISTS mpesa_pending (
        pending_id INT AUTO_INCREMENT PRIMARY KEY,
        checkout_request_id VARCHAR(120) NOT NULL,
        member_id INT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        description VARCHAR(255) NOT NULL DEFAULT '',
        source VARCHAR(50) NOT NULL DEFAULT 'member_portal',
        ticket_order_id INT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_mpesa_checkout (checkout_request_id),
        KEY idx_mpesa_member (member_id),
        KEY idx_mpesa_ticket_order (ticket_order_id)
    )";

    return (bool) $conn->query($sql);
}

/**
 * Parse JSON metadata appended to mpesa_pending.description as "| meta:{...}".
 */
function mpesa_parse_pending_meta(string $description): array
{
    if (preg_match('/\| meta:(\{.+\})$/', $description, $matches)) {
        $decoded = json_decode($matches[1], true);

        return is_array($decoded) ? $decoded : [];
    }

    return [];
}

function mpesa_create_pending(
    mysqli $conn,
    string $checkout_request_id,
    float $amount,
    string $description,
    string $source = 'member_portal',
    ?int $member_id = null,
    ?int $ticket_order_id = null,
    ?array $meta = null
): bool {
    if (!mpesa_ensure_schema($conn) || $checkout_request_id === '') {
        return false;
    }

    if ($meta !== null && $meta !== []) {
        $description = rtrim($description) . ' | meta:' . json_encode($meta);
    }

    $sql = "INSERT INTO mpesa_pending
                (checkout_request_id, member_id, amount, description, source, ticket_order_id, status)
            VALUES (?, ?, ?, ?, ?, ?, 'Pending')";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sidssi', $checkout_request_id, $member_id, $amount, $description, $source, $ticket_order_id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function mpesa_fetch_pending_by_checkout(mysqli $conn, string $checkout_request_id): ?array
{
    if (!mpesa_ensure_schema($conn) || $checkout_request_id === '') {
        return null;
    }

    $stmt = $conn->prepare("SELECT * FROM mpesa_pending WHERE checkout_request_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $checkout_request_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function mpesa_mark_pending_completed(mysqli $conn, int $pending_id): void
{
    $stmt = $conn->prepare("UPDATE mpesa_pending SET status = 'Completed' WHERE pending_id = ?");
    if ($stmt) {
        $stmt->bind_param('i', $pending_id);
        $stmt->execute();
        $stmt->close();
    }
}

function mpesa_log_callback(array $data): void
{
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }

    $line = date('Y-m-d H:i:s') . ' — ' . json_encode($data) . PHP_EOL;
    file_put_contents($dir . '/mpesa.log', $line, FILE_APPEND | LOCK_EX);
}
