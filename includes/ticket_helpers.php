<?php

require_once __DIR__ . '/feature_helpers.php';
require_once __DIR__ . '/url.php';

function ticketing_default_price(): float
{
    return defined('TICKET_DEFAULT_PRICE') ? max(0.0, (float) TICKET_DEFAULT_PRICE) : 5.0;
}

function ticketing_schema_ready(mysqli $conn): bool
{
    return db_table_exists($conn, 'fixture_ticket_settings')
        && db_table_exists($conn, 'ticket_orders')
        && db_table_exists($conn, 'tickets');
}

function ticketing_column_nullable(mysqli $conn, string $table, string $column): bool
{
    $sql = "SELECT IS_NULLABLE
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = ?
              AND COLUMN_NAME = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $stmt->bind_result($nullable);
    $stmt->fetch();
    $stmt->close();

    return strtoupper((string) $nullable) === 'YES';
}

function ticketing_ensure_guest_schema(mysqli $conn): bool
{
    $queries = [];

    if (!ticketing_column_nullable($conn, 'ticket_orders', 'member_id')) {
        $queries[] = "ALTER TABLE ticket_orders MODIFY member_id INT NULL";
    }

    if (!ticketing_column_nullable($conn, 'tickets', 'member_id')) {
        $queries[] = "ALTER TABLE tickets MODIFY member_id INT NULL";
    }

    if (!db_column_exists($conn, 'ticket_orders', 'buyer_type')) {
        $queries[] = "ALTER TABLE ticket_orders ADD COLUMN buyer_type VARCHAR(20) NOT NULL DEFAULT 'Member' AFTER member_id";
    }

    if (!db_column_exists($conn, 'ticket_orders', 'buyer_name')) {
        $queries[] = "ALTER TABLE ticket_orders ADD COLUMN buyer_name VARCHAR(160) NULL AFTER buyer_type";
    }

    if (!db_column_exists($conn, 'ticket_orders', 'buyer_email')) {
        $queries[] = "ALTER TABLE ticket_orders ADD COLUMN buyer_email VARCHAR(160) NULL AFTER buyer_name";
    }

    if (!db_column_exists($conn, 'ticket_orders', 'buyer_phone')) {
        $queries[] = "ALTER TABLE ticket_orders ADD COLUMN buyer_phone VARCHAR(40) NULL AFTER buyer_email";
    }

    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            error_log('Ticketing schema update failed: ' . $conn->error . ' SQL: ' . $sql);
            return false;
        }
    }

    return true;
}

function ticketing_ensure_schema(mysqli $conn): bool
{
    if (ticketing_schema_ready($conn)) {
        return true;
    }
    $queries = [
        "CREATE TABLE IF NOT EXISTS fixture_ticket_settings (
            fixture_id INT PRIMARY KEY,
            ticket_price DECIMAL(10, 2) NOT NULL DEFAULT 5.00,
            ticket_capacity INT NULL,
            sales_status VARCHAR(30) NOT NULL DEFAULT 'Open',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            CONSTRAINT fk_ticket_settings_fixture
                FOREIGN KEY (fixture_id) REFERENCES fixtures (fixture_id) ON DELETE CASCADE
        )",
        "CREATE TABLE IF NOT EXISTS ticket_orders (
            order_id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NULL,
            buyer_type VARCHAR(20) NOT NULL DEFAULT 'Member',
            buyer_name VARCHAR(160) NULL,
            buyer_email VARCHAR(160) NULL,
            buyer_phone VARCHAR(40) NULL,
            fixture_id INT NOT NULL,
            supported_team_id INT NULL,
            quantity INT NOT NULL DEFAULT 1,
            unit_price DECIMAL(10, 2) NOT NULL,
            total_amount DECIMAL(10, 2) NOT NULL,
            payment_method VARCHAR(50) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Pending',
            provider_reference VARCHAR(120) NULL,
            provider_checkout_id VARCHAR(120) NULL,
            payment_id INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            paid_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_ticket_orders_member (member_id, created_at),
            KEY idx_ticket_orders_fixture (fixture_id),
            KEY idx_ticket_orders_supported_team (supported_team_id),
            KEY idx_ticket_orders_payment (payment_id),
            UNIQUE KEY uq_ticket_orders_reference (provider_reference),
            UNIQUE KEY uq_ticket_orders_checkout (provider_checkout_id),
            CONSTRAINT fk_ticket_orders_member
                FOREIGN KEY (member_id) REFERENCES members (member_id) ON DELETE CASCADE,
            CONSTRAINT fk_ticket_orders_fixture
                FOREIGN KEY (fixture_id) REFERENCES fixtures (fixture_id) ON DELETE CASCADE,
            CONSTRAINT fk_ticket_orders_supported_team
                FOREIGN KEY (supported_team_id) REFERENCES teams (team_id) ON DELETE SET NULL,
            CONSTRAINT fk_ticket_orders_payment
                FOREIGN KEY (payment_id) REFERENCES payments (payment_id) ON DELETE SET NULL
        )",
        "CREATE TABLE IF NOT EXISTS tickets (
            ticket_id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            fixture_id INT NOT NULL,
            member_id INT NULL,
            supported_team_id INT NULL,
            ticket_code VARCHAR(80) NOT NULL UNIQUE,
            ticket_price DECIMAL(10, 2) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'Valid',
            issued_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            used_at TIMESTAMP NULL DEFAULT NULL,
            KEY idx_tickets_order (order_id),
            KEY idx_tickets_fixture_status (fixture_id, status),
            KEY idx_tickets_member (member_id, issued_at),
            KEY idx_tickets_supported_team (supported_team_id),
            CONSTRAINT fk_tickets_order
                FOREIGN KEY (order_id) REFERENCES ticket_orders (order_id) ON DELETE CASCADE,
            CONSTRAINT fk_tickets_fixture
                FOREIGN KEY (fixture_id) REFERENCES fixtures (fixture_id) ON DELETE CASCADE,
            CONSTRAINT fk_tickets_member
                FOREIGN KEY (member_id) REFERENCES members (member_id) ON DELETE CASCADE,
            CONSTRAINT fk_tickets_supported_team
                FOREIGN KEY (supported_team_id) REFERENCES teams (team_id) ON DELETE SET NULL
        )",
    ];

    foreach ($queries as $sql) {
        if (!$conn->query($sql)) {
            return false;
        }
    }

    return ticketing_schema_ready($conn) && ticketing_ensure_guest_schema($conn);
}

function ticketing_public_url(string $path, array $query = []): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url = $scheme . '://' . $host . app_url($path);

    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

function ticketing_verify_url(string $ticket_code): string
{
    return ticketing_public_url('public/verify_ticket.php', ['code' => $ticket_code]);
}

function ticketing_purchase_url(int $fixture_id): string
{
    return ticketing_public_url('public/fan_tickets.php', ['fixture_id' => $fixture_id]);
}

function ticketing_qr_image_for_text(string $text, int $size = 180): string
{
    $size = max(80, min(400, $size));
    return 'https://quickchart.io/qr?size=' . $size . '&text=' . rawurlencode($text);
}

function ticketing_qr_image_url(string $ticket_code): string
{
    return ticketing_qr_image_for_text(ticketing_verify_url($ticket_code), 180);
}

function ticketing_purchase_qr_image_url(int $fixture_id): string
{
    return ticketing_qr_image_for_text(ticketing_purchase_url($fixture_id), 120);
}

function ticketing_fetch_member(mysqli $conn, int $member_id): ?array
{
    $stmt = $conn->prepare("SELECT member_id, first_name, last_name, email, phone_number FROM members WHERE member_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $member_id);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $member ?: null;
}

function ticketing_fetch_fixture(mysqli $conn, int $fixture_id): ?array
{
    $sql = "SELECT f.fixture_id, f.league_id, f.home_team_id, f.away_team_id,
                   f.match_date, f.match_time, f.venue, f.matchday, f.status,
                   h.name AS home_team, a.name AS away_team,
                   l.name AS league_name, l.season, s.sport_id, s.name AS sport_name
            FROM fixtures f
            JOIN teams h ON h.team_id = f.home_team_id
            JOIN teams a ON a.team_id = f.away_team_id
            JOIN leagues l ON l.league_id = f.league_id
            JOIN sports s ON s.sport_id = l.sport_id
            WHERE f.fixture_id = ?
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $fixture_id);
    $stmt->execute();
    $fixture = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $fixture ?: null;
}

function ticketing_fetch_fixture_ticket_info(mysqli $conn, int $fixture_id): array
{
    $default_price = ticketing_default_price();
    $info = [
        'ticket_price' => $default_price,
        'ticket_capacity' => null,
        'sales_status' => 'Open',
        'sold' => 0,
        'available' => null,
    ];

    if (ticketing_schema_ready($conn)) {
        $stmt = $conn->prepare("SELECT ticket_price, ticket_capacity, sales_status FROM fixture_ticket_settings WHERE fixture_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $fixture_id);
            $stmt->execute();
            if ($row = $stmt->get_result()->fetch_assoc()) {
                $info['ticket_price'] = (float) $row['ticket_price'];
                $info['ticket_capacity'] = $row['ticket_capacity'] === null ? null : (int) $row['ticket_capacity'];
                $info['sales_status'] = $row['sales_status'] ?: 'Open';
            }
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS sold FROM tickets WHERE fixture_id = ? AND status <> 'Cancelled'");
        if ($stmt) {
            $stmt->bind_param('i', $fixture_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $info['sold'] = (int) ($row['sold'] ?? 0);
            $stmt->close();
        }
    }

    if ($info['ticket_capacity'] !== null) {
        $info['available'] = max(0, $info['ticket_capacity'] - $info['sold']);
    }

    return $info;
}

function ticketing_upsert_fixture_settings(mysqli $conn, int $fixture_id, float $price, ?int $capacity, string $sales_status): bool
{
    $allowed = ['Open', 'Closed'];
    if (!in_array($sales_status, $allowed, true)) {
        $sales_status = 'Open';
    }

    $price = max(0, $price);
    $sql = "INSERT INTO fixture_ticket_settings (fixture_id, ticket_price, ticket_capacity, sales_status)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                ticket_price = VALUES(ticket_price),
                ticket_capacity = VALUES(ticket_capacity),
                sales_status = VALUES(sales_status)";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('idis', $fixture_id, $price, $capacity, $sales_status);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function ticketing_fixture_is_saleable(array $fixture, array $ticket_info): bool
{
    if (!in_array($fixture['status'], ['Scheduled', 'Postponed'], true)) {
        return false;
    }

    if ($fixture['match_date'] < date('Y-m-d')) {
        return false;
    }

    if ($ticket_info['sales_status'] !== 'Open') {
        return false;
    }

    return $ticket_info['available'] === null || $ticket_info['available'] > 0;
}

function ticketing_sport_icon(string $sportName): string
{
    static $icons = [
        'Football'      => '⚽',
        'Rugby'       => '🏉',
        'Hockey'      => '🏑',
        'Volleyball'  => '🏐',
        'Chess'       => '♟️',
        'Horse Riding'=> '🏇',
        'Badminton'   => '🏸',
    ];

    return $icons[$sportName] ?? '🏟️';
}

function ticketing_fetch_upcoming_fixtures(mysqli $conn, int $limit = 60): array
{
    $fixtures = [];
    if (!ticketing_schema_ready($conn)) {
        return $fixtures;
    }

    $limit = max(1, min(200, $limit));
    $sql = "SELECT f.fixture_id, f.home_team_id, f.away_team_id, f.match_date, f.match_time,
                   f.venue, f.matchday, f.status,
                   h.name AS home_team, a.name AS away_team,
                   l.name AS league_name, l.season, s.sport_id, s.name AS sport_name
            FROM fixtures f
            JOIN teams h ON h.team_id = f.home_team_id
            JOIN teams a ON a.team_id = f.away_team_id
            JOIN leagues l ON l.league_id = f.league_id
            JOIN sports s ON s.sport_id = l.sport_id
            WHERE f.match_date >= CURDATE()
              AND f.status IN ('Scheduled', 'Postponed')
            ORDER BY s.name, l.name, f.match_date, f.match_time
            LIMIT " . $limit;

    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $row['ticket_info'] = ticketing_fetch_fixture_ticket_info($conn, (int) $row['fixture_id']);
            $fixtures[] = $row;
        }
        $result->free();
    }

    return $fixtures;
}

/** @return list<array{sport_id:int,sport_name:string,fixtures:array}> */
function ticketing_group_fixtures_by_sport(array $fixtures): array
{
    $groups = [];
    foreach ($fixtures as $fixture) {
        $sportId = (int) ($fixture['sport_id'] ?? 0);
        if (!isset($groups[$sportId])) {
            $groups[$sportId] = [
                'sport_id'   => $sportId,
                'sport_name' => (string) ($fixture['sport_name'] ?? 'Other'),
                'fixtures'   => [],
            ];
        }
        $groups[$sportId]['fixtures'][] = $fixture;
    }

    $grouped = array_values($groups);
    usort($grouped, static fn(array $a, array $b): int => strcasecmp($a['sport_name'], $b['sport_name']));

    return $grouped;
}

function ticketing_resolve_selected_fixture(mysqli $conn, array $fixtures, int $selected_fixture_id): ?array
{
    if ($selected_fixture_id <= 0) {
        return null;
    }

    foreach ($fixtures as $fixture) {
        if ((int) $fixture['fixture_id'] === $selected_fixture_id) {
            return $fixture;
        }
    }

    $fixture = ticketing_fetch_fixture($conn, $selected_fixture_id);
    if (!$fixture) {
        return null;
    }

    $fixture['ticket_info'] = ticketing_fetch_fixture_ticket_info($conn, $selected_fixture_id);

    return $fixture;
}

function ticketing_create_order(
    mysqli $conn,
    ?int $member_id,
    int $fixture_id,
    ?int $supported_team_id,
    int $quantity,
    float $unit_price,
    string $payment_method,
    array $buyer = []
): ?int {
    $quantity = max(1, min(10, $quantity));
    $total = round($unit_price * $quantity, 2);
    $buyer_type = $member_id ? 'Member' : 'Fan';
    $buyer_name = trim((string) ($buyer['name'] ?? ''));
    $buyer_email = trim((string) ($buyer['email'] ?? ''));
    $buyer_phone = trim((string) ($buyer['phone'] ?? ''));

    $sql = "INSERT INTO ticket_orders
                (member_id, buyer_type, buyer_name, buyer_email, buyer_phone,
                 fixture_id, supported_team_id, quantity, unit_price, total_amount, payment_method, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param(
        'issssiiidds',
        $member_id,
        $buyer_type,
        $buyer_name,
        $buyer_email,
        $buyer_phone,
        $fixture_id,
        $supported_team_id,
        $quantity,
        $unit_price,
        $total,
        $payment_method
    );
    $success = $stmt->execute();
    $order_id = $success ? (int) $stmt->insert_id : null;
    $stmt->close();

    return $order_id;
}

function ticketing_update_order_provider(mysqli $conn, int $order_id, ?string $reference, ?string $checkout_id): bool
{
    $stmt = $conn->prepare("UPDATE ticket_orders SET provider_reference = ?, provider_checkout_id = ? WHERE order_id = ?");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ssi', $reference, $checkout_id, $order_id);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function ticketing_mark_order_failed(mysqli $conn, int $order_id): void
{
    $stmt = $conn->prepare("UPDATE ticket_orders SET status = 'Failed' WHERE order_id = ? AND status = 'Pending'");
    if ($stmt) {
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $stmt->close();
    }
}

function ticketing_fetch_order(mysqli $conn, int $order_id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM ticket_orders WHERE order_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $order ?: null;
}

function ticketing_fetch_order_by_checkout(mysqli $conn, string $checkout_id): ?array
{
    $stmt = $conn->prepare("SELECT * FROM ticket_orders WHERE provider_checkout_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $checkout_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $order ?: null;
}

function ticketing_order_buyer(array $order, ?array $member = null): array
{
    if ($member) {
        $full_name = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        return [
            'first_name' => $member['first_name'] ?: 'Member',
            'last_name' => $member['last_name'] ?? '',
            'full_name' => $full_name ?: ($member['first_name'] ?: 'Member'),
            'email' => $member['email'] ?? '',
            'phone_number' => $member['phone_number'] ?? '',
            'buyer_type' => 'Member',
        ];
    }

    $name = trim((string) ($order['buyer_name'] ?? ''));
    $first_name = $name !== '' ? preg_split('/\s+/', $name)[0] : 'Fan';

    return [
        'first_name' => $first_name,
        'last_name' => '',
        'full_name' => $name ?: 'Fan',
        'email' => trim((string) ($order['buyer_email'] ?? '')),
        'phone_number' => trim((string) ($order['buyer_phone'] ?? '')),
        'buyer_type' => 'Fan',
    ];
}

function ticketing_order_tickets_exist(mysqli $conn, int $order_id): bool
{
    $stmt = $conn->prepare("SELECT ticket_id FROM tickets WHERE order_id = ? LIMIT 1");
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();

    return $exists;
}

function ticketing_generate_ticket_code(int $order_id, int $index): string
{
    return 'ASC-' . strtoupper(bin2hex(random_bytes(4))) . '-' . $order_id . '-' . $index;
}

function ticketing_fetch_tickets_for_order(mysqli $conn, int $order_id): array
{
    $sql = "SELECT t.*, st.name AS supported_team
            FROM tickets t
            LEFT JOIN teams st ON st.team_id = t.supported_team_id
            WHERE t.order_id = ?
            ORDER BY t.ticket_id";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $tickets = [];
    while ($row = $result->fetch_assoc()) {
        $tickets[] = $row;
    }
    $stmt->close();

    return $tickets;
}

function ticketing_finalize_paid_order(
    mysqli $conn,
    int $order_id,
    ?int $payment_id,
    string $provider_reference,
    string $payment_method,
    bool $send_email = true
): array {
    $order = ticketing_fetch_order($conn, $order_id);
    if (!$order) {
        return ['success' => false, 'error' => 'Ticket order was not found.'];
    }

    if ($order['status'] === 'Paid' && ticketing_order_tickets_exist($conn, $order_id)) {
        return [
            'success' => true,
            'duplicate' => true,
            'tickets' => ticketing_fetch_tickets_for_order($conn, $order_id),
        ];
    }

    $fixture = ticketing_fetch_fixture($conn, (int) $order['fixture_id']);
    $member = !empty($order['member_id']) ? ticketing_fetch_member($conn, (int) $order['member_id']) : null;
    $buyer = ticketing_order_buyer($order, $member);

    if (!$fixture || (!empty($order['member_id']) && !$member)) {
        return ['success' => false, 'error' => 'Could not load ticket fixture or member.'];
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare("UPDATE ticket_orders
                                SET status = 'Paid', payment_id = ?, provider_reference = COALESCE(?, provider_reference),
                                    payment_method = ?, paid_at = NOW()
                                WHERE order_id = ?");
        if (!$stmt) {
            throw new RuntimeException('Could not update ticket order.');
        }
        $stmt->bind_param('issi', $payment_id, $provider_reference, $payment_method, $order_id);
        $stmt->execute();
        $stmt->close();

        if (!ticketing_order_tickets_exist($conn, $order_id)) {
            $stmt = $conn->prepare("INSERT INTO tickets
                    (order_id, fixture_id, member_id, supported_team_id, ticket_code, ticket_price, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'Valid')");
            if (!$stmt) {
                throw new RuntimeException('Could not prepare ticket issue.');
            }

            $quantity = max(1, (int) $order['quantity']);
            $fixture_id = (int) $order['fixture_id'];
            $member_id = empty($order['member_id']) ? null : (int) $order['member_id'];
            $supported_team_id = $order['supported_team_id'] === null ? null : (int) $order['supported_team_id'];
            $price = (float) $order['unit_price'];

            for ($i = 1; $i <= $quantity; $i++) {
                $code = ticketing_generate_ticket_code($order_id, $i);
                $stmt->bind_param('iiiisd', $order_id, $fixture_id, $member_id, $supported_team_id, $code, $price);
                if (!$stmt->execute()) {
                    throw new RuntimeException('Could not issue ticket.');
                }
            }

            $stmt->close();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'error' => $e->getMessage()];
    }

    $tickets = ticketing_fetch_tickets_for_order($conn, $order_id);

    if ($send_email && !empty($buyer['email'])) {
        require_once __DIR__ . '/send_email.php';
        $fixture_label = $fixture['home_team'] . ' vs ' . $fixture['away_team'];
        sendEmail(
            $buyer['email'],
            $buyer['full_name'],
            'Your Match Ticket - ' . $fixture_label . ' | Apex Sports Club',
            ticketing_email_ticket_delivery($buyer, $fixture, $order, $tickets)
        );
    }

    return [
        'success' => true,
        'duplicate' => false,
        'tickets' => $tickets,
    ];
}

function ticketing_email_ticket_delivery(array $member, array $fixture, array $order, array $tickets): string
{
    $ticket_blocks = '';
    foreach ($tickets as $ticket) {
        $verify_url = ticketing_verify_url($ticket['ticket_code']);
        $qr_url = ticketing_qr_image_url($ticket['ticket_code']);
        $supported = $ticket['supported_team'] ?: 'Not selected';
        $ticket_blocks .= "
            <div style='border:1px solid #ddd;border-radius:8px;padding:14px;margin-top:14px'>
                <table style='width:100%;border-collapse:collapse'>
                    <tr>
                        <td style='vertical-align:top'>
                            <p style='margin:0 0 8px'><strong>Ticket Code:</strong> " . htmlspecialchars($ticket['ticket_code']) . "</p>
                            <p style='margin:0 0 8px'><strong>Supported Team:</strong> " . htmlspecialchars($supported) . "</p>
                            <p style='margin:0'><a href='" . htmlspecialchars($verify_url) . "'>Open ticket verification page</a></p>
                        </td>
                        <td style='width:190px;text-align:center'>
                            <img src='" . htmlspecialchars($qr_url) . "' alt='Ticket QR code' width='180' height='180' style='display:block;border:0'>
                        </td>
                    </tr>
                </table>
            </div>";
    }

    return "
    <div style='font-family:Arial,sans-serif;max-width:680px;margin:auto;border:1px solid #ddd;border-radius:8px;overflow:hidden'>
      <div style='background:#0f766e;padding:24px;text-align:center'>
        <h1 style='color:white;margin:0;font-size:24px'>Your Match Ticket Is Confirmed</h1>
      </div>
      <div style='padding:24px'>
        <p>Hi <strong>" . htmlspecialchars($member['first_name'] ?: 'Member') . "</strong>, your ticket purchase is confirmed.</p>
        <table style='width:100%;border-collapse:collapse;margin-top:16px'>
          <tr style='background:#f8f9fa'>
            <td style='padding:10px;border:1px solid #ddd'><strong>Fixture</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars($fixture['home_team'] . ' vs ' . $fixture['away_team']) . "</td>
          </tr>
          <tr>
            <td style='padding:10px;border:1px solid #ddd'><strong>Competition</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars($fixture['sport_name'] . ' - ' . $fixture['league_name']) . "</td>
          </tr>
          <tr style='background:#f8f9fa'>
            <td style='padding:10px;border:1px solid #ddd'><strong>Date and Time</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars(date('d M Y', strtotime($fixture['match_date'])) . ' ' . substr((string) $fixture['match_time'], 0, 5)) . "</td>
          </tr>
          <tr>
            <td style='padding:10px;border:1px solid #ddd'><strong>Venue</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>" . htmlspecialchars($fixture['venue'] ?: 'To be confirmed') . "</td>
          </tr>
          <tr style='background:#f8f9fa'>
            <td style='padding:10px;border:1px solid #ddd'><strong>Total Paid</strong></td>
            <td style='padding:10px;border:1px solid #ddd'>KES " . number_format((float) $order['total_amount'], 2) . "</td>
          </tr>
        </table>
        {$ticket_blocks}
        <p style='margin-top:18px;color:#666;font-size:13px'>Show the QR code at the gate. Each QR code can be validated once.</p>
      </div>
      <div style='background:#f8f9fa;padding:12px;text-align:center;color:#888;font-size:12px'>
        Apex Sports Club
      </div>
    </div>";
}
