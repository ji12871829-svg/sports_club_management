<?php

require_once __DIR__ . '/feature_helpers.php';
require_once __DIR__ . '/totp.php';

function admin_2fa_schema_ready(mysqli $conn): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    if (!db_table_exists($conn, 'admins')) {
        $ready = false;
    } else {
        $res = $conn->query("SHOW COLUMNS FROM admins LIKE 'totp_enabled'");
        $ready = $res && $res->num_rows > 0;
        if ($res) {
            $res->free();
        }
    }
    return $ready;
}

function admin_2fa_fetch(mysqli $conn, int $admin_id): ?array
{
    if (!admin_2fa_schema_ready($conn)) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT admin_id, email, totp_secret, totp_enabled, totp_confirmed_at, recovery_codes
         FROM admins WHERE admin_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $admin_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function admin_2fa_is_enabled(?array $admin): bool
{
    return $admin && !empty($admin['totp_enabled']) && !empty($admin['totp_secret']);
}

function admin_2fa_generate_recovery_codes(): array
{
    $plain = [];
    $hashed = [];
    for ($i = 0; $i < 8; $i++) {
        $code = strtoupper(bin2hex(random_bytes(4)));
        $plain[] = $code;
        $hashed[] = password_hash($code, PASSWORD_DEFAULT);
    }

    return ['plain' => $plain, 'hashed' => $hashed];
}

function admin_2fa_save_recovery_codes(mysqli $conn, int $admin_id, array $hashedCodes): bool
{
    $json = json_encode(array_values($hashedCodes));
    $stmt = $conn->prepare('UPDATE admins SET recovery_codes = ? WHERE admin_id = ?');
    $stmt->bind_param('si', $json, $admin_id);

    return $stmt->execute() && $stmt->close();
}

function admin_2fa_enable(mysqli $conn, int $admin_id, string $secret, array $hashedRecoveryCodes): bool
{
    $json = json_encode(array_values($hashedRecoveryCodes));
    $stmt = $conn->prepare(
        'UPDATE admins SET totp_secret = ?, totp_enabled = 1, totp_confirmed_at = NOW(), recovery_codes = ?
         WHERE admin_id = ?'
    );
    $stmt->bind_param('ssi', $secret, $json, $admin_id);

    return $stmt->execute() && $stmt->close();
}

function admin_2fa_disable(mysqli $conn, int $admin_id): bool
{
    $stmt = $conn->prepare(
        'UPDATE admins SET totp_secret = NULL, totp_enabled = 0, totp_confirmed_at = NULL, recovery_codes = NULL
         WHERE admin_id = ?'
    );
    $stmt->bind_param('i', $admin_id);

    return $stmt->execute() && $stmt->close();
}

function admin_2fa_verify_recovery_code(mysqli $conn, int $admin_id, string $code): bool
{
    $admin = admin_2fa_fetch($conn, $admin_id);
    if (!$admin || empty($admin['recovery_codes'])) {
        return false;
    }

    $code = strtoupper(preg_replace('/\s+/', '', trim($code)));
    $hashes = json_decode($admin['recovery_codes'], true);
    if (!is_array($hashes)) {
        return false;
    }

    foreach ($hashes as $index => $hash) {
        if (is_string($hash) && password_verify($code, $hash)) {
            unset($hashes[$index]);
            admin_2fa_save_recovery_codes($conn, $admin_id, array_values($hashes));

            return true;
        }
    }

    return false;
}

function admin_2fa_start_pending_session(int $admin_id, string $email): void
{
    $_SESSION['admin_2fa_pending'] = [
        'admin_id' => $admin_id,
        'email' => $email,
        'expires' => time() + 300,
    ];
    unset($_SESSION['admin_loggedin'], $_SESSION['admin_id'], $_SESSION['admin_email']);
}

function admin_2fa_pending_valid(): bool
{
    if (empty($_SESSION['admin_2fa_pending']) || !is_array($_SESSION['admin_2fa_pending'])) {
        return false;
    }
    $pending = $_SESSION['admin_2fa_pending'];
    if (empty($pending['admin_id']) || empty($pending['expires']) || time() > (int) $pending['expires']) {
        unset($_SESSION['admin_2fa_pending']);

        return false;
    }

    return true;
}

function admin_2fa_clear_pending(): void
{
    unset($_SESSION['admin_2fa_pending']);
}

function admin_2fa_complete_login(mysqli $conn, int $admin_id, string $email): void
{
    session_regenerate_id(true);
    admin_2fa_clear_pending();
    $_SESSION['admin_loggedin'] = true;
    $_SESSION['admin_id'] = $admin_id;
    $_SESSION['admin_email'] = $email;
    $_SESSION['admin_2fa_verified_at'] = time();

    require_once __DIR__ . '/activity_log.php';
    log_activity($conn, 'Admin logged in (2FA verified)', 'Auth', $admin_id, 'Login from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function admin_2fa_public_pages(): array
{
    return ['admin_login.php', 'admin_verify_2fa.php'];
}

function admin_2fa_is_public_page(): bool
{
    return in_array(basename($_SERVER['SCRIPT_NAME'] ?? ''), admin_2fa_public_pages(), true);
}
