<?php
/**
 * includes/activity_log.php
 * Lightweight helper to record admin actions to the activity log.
 *
 * Usage (anywhere after session + db are loaded):
 *   log_activity($conn, 'Deleted member', 'Members', $member_id);
 *   log_activity($conn, 'Updated fixture score', 'Fixtures', $fixture_id, 'Set 2-1 vs Team B');
 */

/**
 * Record one admin action.
 *
 * @param mysqli $conn        Active DB connection
 * @param string $action      Short verb phrase  e.g. "Deleted member"
 * @param string $module      Section name       e.g. "Members", "Fixtures", "Payments"
 * @param int|null $record_id ID of affected row (optional)
 * @param string $description Extra detail       (optional)
 */
function log_activity(
    mysqli $conn,
    string $action,
    string $module,
    ?int   $record_id   = null,
    string $description = ''
): void {
    // Silently skip if the table doesn't exist yet (pre-migration)
    $check = $conn->query("SHOW TABLES LIKE 'admin_activity_log'");
    if (!$check || $check->num_rows === 0) return;

    $admin_id    = $_SESSION['admin_id']    ?? null;
    $admin_email = $_SESSION['admin_email'] ?? null;
    $ip          = $_SERVER['REMOTE_ADDR']  ?? null;
    $ua          = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

    $stmt = $conn->prepare("
        INSERT INTO admin_activity_log
            (admin_id, admin_email, action, module, description, record_id, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;

    $stmt->bind_param(
        "issssiss",
        $admin_id, $admin_email, $action, $module,
        $description, $record_id, $ip, $ua
    );
    $stmt->execute();
    $stmt->close();
}
