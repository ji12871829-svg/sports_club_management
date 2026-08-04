<?php
/**
 * includes/notifications.php
 * Helper to create admin notifications from anywhere in the system.
 *
 * Usage:
 *   notify($conn, 'damage_report', 'New damage report', 'Equipment damaged', 'manage_damage_reports.php');
 */
function notify(
    mysqli $conn,
    string $type,
    string $title,
    string $message  = '',
    string $link_url = '',
    ?int   $admin_id = null
): void {
    $check = $conn->query("SHOW TABLES LIKE 'admin_notifications'");
    if (!$check || $check->num_rows === 0) return;
    $stmt = $conn->prepare("
        INSERT INTO admin_notifications (type, title, message, link_url, admin_id)
        VALUES (?, ?, ?, ?, ?)
    ");
    if (!$stmt) return;
    $stmt->bind_param("ssssi", $type, $title, $message, $link_url, $admin_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Get unread notification count (for bell icon in nav).
 */
function get_unread_notification_count(mysqli $conn): int {
    $check = $conn->query("SHOW TABLES LIKE 'admin_notifications'");
    if (!$check || $check->num_rows === 0) return 0;
    $res = $conn->query("SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0");
    return $res ? (int)$res->fetch_row()[0] : 0;
}
