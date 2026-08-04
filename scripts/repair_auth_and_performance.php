<?php

declare(strict_types=1);

/**
 * Repairs common local-development issues after reseeding:
 * - placeholder member password hashes that can never verify
 * - default admin password drift
 * - missing indexes for dashboard/auth lookups
 *
 * Usage:
 *   php scripts/repair_auth_and_performance.php --dry-run
 *   php scripts/repair_auth_and_performance.php --apply
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../config/db_connect.php';

$apply = in_array('--apply', $argv, true);
$dryRun = in_array('--dry-run', $argv, true) || !$apply;

$memberPassword = getenv('ASC_REPAIR_MEMBER_PASSWORD') ?: 'Member@2026!';
$adminPassword = getenv('ASC_REPAIR_ADMIN_PASSWORD') ?: 'Admin@2026!';
$adminEmail = getenv('ASC_REPAIR_ADMIN_EMAIL') ?: 'admin@sportsclub.com';

function table_exists(mysqli $conn, string $table): bool
{
    $escaped = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escaped}'");

    return $result && $result->num_rows > 0;
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $escaped = $conn->real_escape_string($column);
    $result = $conn->query('SHOW COLUMNS FROM ' . sql_name($table) . " LIKE '{$escaped}'");

    return $result && $result->num_rows > 0;
}

function index_exists(mysqli $conn, string $table, string $index): bool
{
    $escaped = $conn->real_escape_string($index);
    $result = $conn->query('SHOW INDEX FROM ' . sql_name($table) . " WHERE Key_name = '{$escaped}'");

    return $result && $result->num_rows > 0;
}

function sql_name(string $name): string
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
        throw new InvalidArgumentException('Unsafe SQL identifier: ' . $name);
    }

    return '`' . $name . '`';
}

function add_index_if_missing(mysqli $conn, string $table, string $index, array $columns, bool $apply): string
{
    if (!table_exists($conn, $table)) {
        return "skipped missing table {$table}.{$index}";
    }

    foreach ($columns as $column) {
        if (!column_exists($conn, $table, $column)) {
            return "skipped missing column {$table}.{$column}";
        }
    }

    if (index_exists($conn, $table, $index)) {
        return "exists {$table}.{$index}";
    }

    if ($apply) {
        $columnSql = implode(', ', array_map('sql_name', $columns));
        $sql = 'CREATE INDEX ' . sql_name($index) . ' ON ' . sql_name($table) . ' (' . $columnSql . ')';
        if (!$conn->query($sql)) {
            throw new RuntimeException("Could not create {$table}.{$index}: " . $conn->error);
        }
    }

    return ($apply ? 'created ' : 'would create ') . "{$table}.{$index}";
}

function count_invalid_member_hashes(mysqli $conn): array
{
    $ids = [];
    $result = $conn->query('SELECT member_id, password FROM members');
    if (!$result) {
        throw new RuntimeException('Could not scan members: ' . $conn->error);
    }

    while ($row = $result->fetch_assoc()) {
        $passwordHash = (string) ($row['password'] ?? '');
        $info = password_get_info($passwordHash);
        if (($info['algoName'] ?? 'unknown') === 'unknown') {
            $ids[] = (int) $row['member_id'];
        }
    }

    $result->free();

    return $ids;
}

function repair_invalid_member_hashes(mysqli $conn, array $memberIds, string $password, bool $apply): int
{
    if (!$apply || $memberIds === []) {
        return 0;
    }

    $stmt = $conn->prepare('UPDATE members SET password = ? WHERE member_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Could not prepare member password update: ' . $conn->error);
    }

    $updated = 0;
    foreach ($memberIds as $memberId) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt->bind_param('si', $hash, $memberId);
        $stmt->execute();
        $updated += $stmt->affected_rows > 0 ? 1 : 0;
    }

    $stmt->close();

    return $updated;
}

function normalized_seed_email(string $email): ?string
{
    if (!str_contains($email, '@')) {
        return null;
    }

    [$local, $domain] = explode('@', $email, 2);
    $domain = strtolower($domain);

    if (!str_ends_with($domain, '.apexsportsclub.local')) {
        return null;
    }

    $normalized = $local . '@' . str_replace('_', '-', $domain);

    return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? $normalized : null;
}

function repair_seed_email_domains(mysqli $conn, bool $apply): array
{
    $result = $conn->query('SELECT member_id, email FROM members ORDER BY member_id');
    if (!$result) {
        throw new RuntimeException('Could not scan member emails: ' . $conn->error);
    }

    $planned = [];
    while ($row = $result->fetch_assoc()) {
        $email = (string) $row['email'];
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $normalized = normalized_seed_email($email);
        if ($normalized === null || $normalized === $email) {
            continue;
        }

        $planned[] = [
            'member_id' => (int) $row['member_id'],
            'old_email' => $email,
            'new_email' => $normalized,
        ];
    }
    $result->free();

    if (!$apply || $planned === []) {
        return [
            'would_update' => count($planned),
            'updated' => 0,
            'skipped_duplicates' => 0,
        ];
    }

    $existsStmt = $conn->prepare('SELECT member_id FROM members WHERE email = ? AND member_id <> ? LIMIT 1');
    $updateStmt = $conn->prepare('UPDATE members SET email = ? WHERE member_id = ?');
    if (!$existsStmt || !$updateStmt) {
        throw new RuntimeException('Could not prepare email repair statements: ' . $conn->error);
    }

    $updated = 0;
    $skippedDuplicates = 0;
    foreach ($planned as $row) {
        $existsStmt->bind_param('si', $row['new_email'], $row['member_id']);
        $existsStmt->execute();
        $existsStmt->store_result();
        if ($existsStmt->num_rows > 0) {
            $skippedDuplicates++;
            $existsStmt->free_result();
            continue;
        }
        $existsStmt->free_result();

        $updateStmt->bind_param('si', $row['new_email'], $row['member_id']);
        $updateStmt->execute();
        $updated += $updateStmt->affected_rows > 0 ? 1 : 0;
    }

    $existsStmt->close();
    $updateStmt->close();

    return [
        'would_update' => count($planned),
        'updated' => $updated,
        'skipped_duplicates' => $skippedDuplicates,
    ];
}

function ensure_admin_password(mysqli $conn, string $email, string $password, bool $apply): string
{
    if (!table_exists($conn, 'admins')) {
        return 'skipped missing admins table';
    }

    $stmt = $conn->prepare('SELECT admin_id, password FROM admins WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->bind_result($adminId, $currentHash);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$apply) {
        return $found ? "would reset admin {$email}" : "would create admin {$email}";
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($found) {
        $stmt = $conn->prepare('UPDATE admins SET password = ? WHERE admin_id = ?');
        $stmt->bind_param('si', $hash, $adminId);
        $stmt->execute();
        $stmt->close();

        return "reset admin {$email}";
    }

    $stmt = $conn->prepare('INSERT INTO admins (email, password) VALUES (?, ?)');
    $stmt->bind_param('ss', $email, $hash);
    $stmt->execute();
    $stmt->close();

    return "created admin {$email}";
}

$summary = [
    'mode' => $dryRun ? 'dry-run' : 'apply',
    'member_password' => $memberPassword,
    'admin_email' => $adminEmail,
    'admin_password' => $adminPassword,
    'actions' => [],
];

if (!table_exists($conn, 'members')) {
    throw new RuntimeException('Missing members table.');
}

$invalidMemberIds = count_invalid_member_hashes($conn);
$summary['invalid_member_hashes_before'] = count($invalidMemberIds);
$summary['members_repaired'] = repair_invalid_member_hashes($conn, $invalidMemberIds, $memberPassword, $apply);
$summary['seed_email_domain_repair'] = repair_seed_email_domains($conn, $apply);
$summary['actions'][] = ensure_admin_password($conn, $adminEmail, $adminPassword, $apply);

$indexTargets = [
    ['bookings', 'idx_bookings_status_date', ['status', 'booking_date']],
    ['bookings', 'idx_bookings_date_start', ['booking_date', 'start_time']],
    ['payments', 'idx_payments_date', ['payment_date']],
    ['payments', 'idx_payments_status_date', ['payment_status', 'payment_date']],
    ['member_memberships', 'idx_member_memberships_status_end', ['status', 'end_date']],
    ['member_memberships', 'idx_member_memberships_member_status_end', ['member_id', 'status', 'end_date']],
    ['leagues', 'idx_leagues_status', ['status']],
    ['admin_notifications', 'idx_admin_notifications_read_created', ['is_read', 'created_at']],
    ['damage_reports', 'idx_damage_reports_status', ['status']],
];

foreach ($indexTargets as [$table, $index, $columns]) {
    $summary['actions'][] = add_index_if_missing($conn, $table, $index, $columns, $apply);
}

$summary['invalid_member_hashes_after'] = count(count_invalid_member_hashes($conn));

echo json_encode($summary, JSON_PRETTY_PRINT) . PHP_EOL;
