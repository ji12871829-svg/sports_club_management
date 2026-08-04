<?php
// ============================================================
//  includes/roles.php
//  Role-Based Access Control (RBAC) helpers
// ============================================================

/**
 * Check if a user has a specific permission.
 * Works for both members and admins.
 *
 * @param mysqli $conn   Active DB connection
 * @param string $permission_code Permission code (e.g. 'members.view')
 * @param string $user_type        'admin' or 'member'
 * @param int    $user_id          The user's ID
 * @return bool
 */
function asc_has_permission(mysqli $conn, string $permission_code, string $user_type = 'admin', int $user_id = 0): bool
{
    // Super Admin always has all permissions
    if ($user_type === 'admin') {
        $stmt = $conn->prepare("
            SELECT 1 FROM admins a
            JOIN roles r ON r.role_id = a.role_id
            WHERE a.admin_id = ? AND r.slug = 'super-admin'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                return true;
            }
            $stmt->close();
        }
    }

    // Administrator role also has all (unless super-admin already caught)
    if ($user_type === 'admin') {
        $stmt = $conn->prepare("
            SELECT 1 FROM admins a
            JOIN roles r ON r.role_id = a.role_id
            WHERE a.admin_id = ? AND r.slug = 'administrator'
            LIMIT 1
        ");
        if ($stmt) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();
                return true;
            }
            $stmt->close();
        }
    }

    // Check via role_permissions
    $table = $user_type === 'admin' ? 'admins' : 'members';
    $id_col = $user_type === 'admin' ? 'admin_id' : 'member_id';

    $sql = "
        SELECT 1
        FROM {$table} u
        JOIN role_permissions rp ON rp.role_id = u.role_id
        JOIN permissions p ON p.permission_id = rp.permission_id
        WHERE u.{$id_col} = ? AND p.code = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('is', $user_id, $permission_code);
    $stmt->execute();
    $result = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $result;
}

/**
 * Get a user's role information.
 */
function asc_get_user_role(mysqli $conn, string $user_type = 'admin', int $user_id = 0): ?array
{
    $table = $user_type === 'admin' ? 'admins' : 'members';
    $id_col = $user_type === 'admin' ? 'admin_id' : 'member_id';

    $sql = "
        SELECT r.role_id, r.name, r.slug, r.description
        FROM {$table} u
        JOIN roles r ON r.role_id = u.role_id
        WHERE u.{$id_col} = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $result ?: null;
}

/**
 * Get all roles with their permission counts.
 */
function asc_get_all_roles(mysqli $conn): array
{
    $roles = [];
    $result = $conn->query("
        SELECT r.*, COUNT(rp.permission_id) AS permission_count
        FROM roles r
        LEFT JOIN role_permissions rp ON rp.role_id = r.role_id
        GROUP BY r.role_id
        ORDER BY r.role_id
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }
        $result->free();
    }
    return $roles;
}

/**
 * Get all permissions, optionally filtered by module.
 */
function asc_get_all_permissions(mysqli $conn, ?string $module = null): array
{
    $perms = [];
    $sql = "SELECT * FROM permissions";
    $params = [];
    $types = '';

    if ($module !== null) {
        $sql .= " WHERE module = ? ORDER BY module, name";
        $types = 's';
        $params[] = $module;
    } else {
        $sql .= " ORDER BY module, name";
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];

    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $perms[] = $row;
    }
    $stmt->close();
    return $perms;
}

/**
 * Get all permission modules.
 */
function asc_get_permission_modules(mysqli $conn): array
{
    $modules = [];
    $result = $conn->query("SELECT DISTINCT module FROM permissions WHERE module != '' ORDER BY module");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $modules[] = $row['module'];
        }
        $result->free();
    }
    return $modules;
}

/**
 * Get permissions assigned to a role.
 */
function asc_get_role_permissions(mysqli $conn, int $role_id): array
{
    $perms = [];
    $stmt = $conn->prepare("
        SELECT p.permission_id, p.code, p.name, p.module
        FROM role_permissions rp
        JOIN permissions p ON p.permission_id = rp.permission_id
        WHERE rp.role_id = ?
        ORDER BY p.module, p.name
    ");
    if ($stmt) {
        $stmt->bind_param('i', $role_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $perms[] = $row;
        }
        $stmt->close();
    }
    return $perms;
}

/**
 * Check if a role slug exists.
 */
function asc_role_exists(mysqli $conn, string $slug): bool
{
    $stmt = $conn->prepare("SELECT 1 FROM roles WHERE slug = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Get user counts by role.
 */
function asc_get_role_counts(mysqli $conn): array
{
    $counts = [];
    $result = $conn->query("
        SELECT r.role_id, r.name, r.slug,
               COUNT(DISTINCT a.admin_id) AS admin_count,
               COUNT(DISTINCT m.member_id) AS member_count
        FROM roles r
        LEFT JOIN admins a ON a.role_id = r.role_id
        LEFT JOIN members m ON m.role_id = r.role_id
        GROUP BY r.role_id
        ORDER BY r.role_id
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $counts[] = $row;
        }
        $result->free();
    }
    return $counts;
}
