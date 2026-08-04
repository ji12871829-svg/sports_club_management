<?php

require_once __DIR__ . '/feature_helpers.php';

const ASC_PASSWORD_RESET_TTL_SECONDS = 3600;

function asc_password_reset_ready(mysqli $conn): bool
{
    return db_table_exists($conn, 'password_reset_tokens');
}

function asc_password_reset_hash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Create a one-hour reset token; returns the plain token for the email link.
 */
function asc_create_password_reset_token(mysqli $conn, int $member_id): ?string
{
    if (!asc_password_reset_ready($conn) || $member_id <= 0) {
        return null;
    }

    $token = bin2hex(random_bytes(32));
    $hash = asc_password_reset_hash($token);
    $expires = date('Y-m-d H:i:s', time() + ASC_PASSWORD_RESET_TTL_SECONDS);

    $del = $conn->prepare('DELETE FROM password_reset_tokens WHERE member_id = ?');
    $del->bind_param('i', $member_id);
    $del->execute();
    $del->close();

    $stmt = $conn->prepare(
        'INSERT INTO password_reset_tokens (member_id, token_hash, expires_at) VALUES (?, ?, ?)'
    );
    $stmt->bind_param('iss', $member_id, $hash, $expires);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? $token : null;
}

/**
 * @return array{member_id:int,first_name:string,email:string,token_id:int}|null
 */
function asc_verify_password_reset_token(mysqli $conn, string $token): ?array
{
    if (!asc_password_reset_ready($conn) || strlen($token) < 32) {
        return null;
    }

    $hash = asc_password_reset_hash($token);
    $stmt = $conn->prepare(
        "SELECT t.token_id, m.member_id, m.first_name, m.email
         FROM password_reset_tokens t
         INNER JOIN members m ON m.member_id = t.member_id
         WHERE t.token_hash = ? AND t.used_at IS NULL AND t.expires_at > NOW()
         LIMIT 1"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return [
        'token_id'   => (int) $row['token_id'],
        'member_id'  => (int) $row['member_id'],
        'first_name' => (string) $row['first_name'],
        'email'      => (string) $row['email'],
    ];
}

function asc_complete_password_reset(mysqli $conn, int $token_id, int $member_id, string $newPassword): bool
{
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('UPDATE members SET password = ? WHERE member_id = ?');
        $stmt->bind_param('si', $passwordHash, $member_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not update password.');
        }
        $stmt->close();

        $stmt = $conn->prepare(
            'UPDATE password_reset_tokens SET used_at = NOW() WHERE token_id = ? AND member_id = ?'
        );
        $stmt->bind_param('ii', $token_id, $member_id);
        if (!$stmt->execute()) {
            throw new RuntimeException('Could not mark token used.');
        }
        $stmt->close();

        $del = $conn->prepare('DELETE FROM password_reset_tokens WHERE member_id = ?');
        $del->bind_param('i', $member_id);
        $del->execute();
        $del->close();

        $conn->commit();

        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Password reset failed: ' . $e->getMessage());

        return false;
    }
}

/**
 * Send reset email if member exists. Always returns true for UX (no email enumeration).
 */
function asc_request_password_reset(mysqli $conn, string $email): bool
{
    require_once __DIR__ . '/send_email.php';
    require_once __DIR__ . '/url.php';

    $email = trim(strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return true;
    }

    $stmt = $conn->prepare(
        'SELECT member_id, first_name, email FROM members WHERE LOWER(email) = ? LIMIT 1'
    );
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $member = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$member || !asc_password_reset_ready($conn)) {
        return true;
    }

    $token = asc_create_password_reset_token($conn, (int) $member['member_id']);
    if ($token === null) {
        return true;
    }

    $resetUrl = app_absolute_url_for_email('public/reset_password.php', ['token' => $token]);
    $sent = sendEmail(
        $member['email'],
        $member['first_name'],
        'Reset your Apex Sports Club password',
        emailPasswordReset($member['first_name'], $resetUrl)
    );

    if (!$sent) {
        error_log('Password reset email failed for member_id=' . $member['member_id']);
    }

    return true;
}
