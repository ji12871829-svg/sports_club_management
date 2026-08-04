<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once "../includes/csrf.php";
require_once "../includes/activity_log.php";
require_once "../includes/whatsapp.php";
require_once "../includes/roles.php";
require_once __DIR__ . '/../includes/cache.php';

$message = '';
$search_query = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Invalidate the members list cache on any POST that mutates data.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cache_delete('mg_members');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = '<div class="alert alert-danger border-0 shadow-sm rounded-3 mb-3"><i class="fas fa-shield-alt me-2"></i>Security check failed. Please try again.</div>';
    } else {
        $member_id = (int) ($_POST['id'] ?? 0);
        $sql = "DELETE FROM members WHERE member_id = ?";

        if ($member_id > 0 && ($stmt = $conn->prepare($sql))) {
            $stmt->bind_param("i", $member_id);
            if ($stmt->execute()) {
                log_activity($conn, 'Deleted member', 'Members', $member_id);
                cache_delete('mg_members');
                $message = '<div class="alert alert-success border-0 shadow-sm rounded-3 mb-3"><i class="fas fa-check-circle me-2"></i>Member deleted successfully.</div>';
                
                $count_result = $conn->query("SELECT COUNT(*) AS total FROM members");
                $count = $count_result->fetch_assoc()['total'];
                if ((int) $count === 0) {
                    $conn->query("ALTER TABLE members AUTO_INCREMENT = 1");
                }
            } else {
                $message = '<div class="alert alert-danger border-0 shadow-sm rounded-3 mb-3"><i class="fas fa-exclamation-circle me-2"></i>Error deleting member.</div>';
            }
            $stmt->close();
        }
    }
}

// Count total matching members
$count_sql = "SELECT COUNT(*) AS total FROM members";
$count_params = [];
$count_types = '';
if ($search_query !== '') {
    $count_sql .= " WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ?";
    $like = '%' . $search_query . '%';
    $count_types = 'ssss';
    $count_params = [$like, $like, $like, $like];
}
$total_members = 0;
if ($stmt = $conn->prepare($count_sql)) {
    if ($count_params) {
        $stmt->bind_param($count_types, ...$count_params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $total_members = (int)($result->fetch_assoc()['total'] ?? 0);
    $stmt->close();
}
$total_pages = max(1, ceil($total_members / $per_page));

// Fetch all roles for assignment dropdown
$all_roles = [];
$roles_result = $conn->query("SELECT role_id, name, slug FROM roles ORDER BY name");
if ($roles_result) {
    while ($row = $roles_result->fetch_assoc()) {
        $all_roles[] = $row;
    }
    $roles_result->free();
}

// Handle role assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'assign_role') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = '<div class="alert alert-danger border-0 shadow-sm rounded-3 mb-3"><i class="fas fa-shield-alt me-2"></i>Security check failed.</div>';
    } else {
        $target_member_id = (int)($_POST['member_id'] ?? 0);
        $new_role_id = !empty($_POST['role_id']) ? (int)$_POST['role_id'] : null;
        
        $stmt = $conn->prepare("UPDATE members SET role_id = ? WHERE member_id = ?");
        if ($new_role_id !== null) {
            $stmt = $conn->prepare("UPDATE members SET role_id = ? WHERE member_id = ?");
            $stmt->bind_param('ii', $new_role_id, $target_member_id);
        } else {
            $stmt = $conn->prepare("UPDATE members SET role_id = NULL WHERE member_id = ?");
            $stmt->bind_param('i', $target_member_id);
        }
        if ($stmt->execute()) {
            log_activity($conn, 'Updated member role', 'Members', $target_member_id, 'Role ID: ' . ($new_role_id ?: 'none'));
            cache_delete('mg_members');
            $message = '<div class="alert alert-success border-0 shadow-sm rounded-3 mb-3"><i class="fas fa-check-circle me-2"></i>Member role updated successfully.</div>';
        }
        $stmt->close();
    }
}

// Fetch paginated members (include role info) — cached 60s on the default
// (no-search) view, keyed by page. Searches bypass the cache (varying terms).
$members = [];
if ($search_query === '') {
    $members = cache_remember('mg_members:p' . $page, 60, function () use ($conn, $per_page, $offset) {
        $rows = [];
        $sql = "SELECT m.member_id, m.first_name, m.last_name, m.email, m.phone_number, m.address, m.date_joined, m.last_login, m.role_id, r.name AS role_name
                FROM members m
                LEFT JOIN roles r ON r.role_id = m.role_id
                ORDER BY date_joined DESC LIMIT ? OFFSET ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('ii', $per_page, $offset);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
        return $rows;
    });
} else {
    $sql = "SELECT m.member_id, m.first_name, m.last_name, m.email, m.phone_number, m.address, m.date_joined, m.last_login, m.role_id, r.name AS role_name
            FROM members m
            LEFT JOIN roles r ON r.role_id = m.role_id
            WHERE first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ?
            ORDER BY date_joined DESC LIMIT ? OFFSET ?";
    $like = '%' . $search_query . '%';
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('ssssii', $like, $like, $like, $like, $per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $members[] = $row;
        }
        $stmt->close();
    }
}
$conn->close();
?>

<style>
    /* Asc-* classes from admin.css handle all base styling */

    /* Enterprise Table Overrides */
    .table-corporate {
        font-size: 0.9rem;
        margin-bottom: 0;
        vertical-align: middle;
    }

    .table-corporate thead th {
        background-color: #f8fafc;
        color: #475569;
        font-weight: 700;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border-bottom: 1px solid #e2e8f0;
        padding: 1rem 1.25rem;
    }

    .table-corporate tbody td {
        padding: 1rem 1.25rem;
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
    }

    .table-corporate tbody tr:last-child td {
        border-bottom: none;
    }

    .table-corporate tbody tr {
        transition: background-color 0.15s ease;
    }

    .table-corporate tbody tr:hover {
        background-color: #f8fafc;
    }

    /* Unified Component Tokens */
    .badge-id-corporate {
        font-family: SFMono-Regular, Menlo, Monaco, Consolas, monospace;
        font-weight: 600;
        color: #475569;
        background-color: #f1f5f9;
        padding: 0.2rem 0.4rem;
        border-radius: 4px;
        font-size: 0.8rem;
    }

    .meta-contact-corporate {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        color: #334155;
    }

    .meta-contact-corporate i {
        color: #94a3b8;
        width: 14px;
        text-align: center;
    }

    .text-name-corporate {
        color: #0f172a;
        font-weight: 600;
    }

    /* Unified Actions Framework */
    .btn-corporate {
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.4rem 0.8rem;
        border-radius: 6px;
        transition: all 0.15s ease;
        border: 1px solid transparent;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    .btn-corporate-danger {
        background-color: #fef2f2;
        border: 1px solid #fecaca;
        color: #dc2626 !important;
        cursor: pointer;
    }

    .btn-corporate-danger:hover {
        background-color: #fee2e2;
        border-color: #fca5a5;
    }
</style>

<div class="container-fluid py-5 px-4" style="max-width: 1400px;">
    
    <div class="row page-header-corporate align-items-end">
        <div class="col-md-12 d-flex justify-content-between align-items-end flex-wrap gap-3">
            <div>
                <div class="brand-accent-line"></div>
                <h1 class="corporate-title mb-2">Membership Directory</h1>
                <p class="text-muted mb-0">Search, view, and manage all registered club members.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <form method="get" class="d-flex align-items-center gap-2">
                    <div class="input-group input-group-sm" style="max-width: 280px;">
                        <input type="text" name="search" class="form-control form-control-sm" 
                               placeholder="Search members..." 
                               value="<?php echo htmlspecialchars($search_query); ?>"
                               style="border-radius:6px 0 0 6px;font-size:0.85rem;">
                        <button class="btn btn-outline-secondary btn-sm" type="submit" 
                                style="border-radius:0 6px 6px 0;font-size:0.85rem;">
                            <i class="fas fa-search"></i>
                        </button>
                        <?php if ($search_query !== ''): ?>
                            <a href="manage_members.php" class="btn btn-outline-secondary btn-sm ms-1"
                               style="border-radius:6px;font-size:0.85rem;" title="Clear search">
                                <i class="fas fa-times"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
                <span class="badge bg-white text-dark border font-monospace px-3 py-2 small shadow-sm" style="border-radius: 6px;">
                    ACTIVE RECORDS: <?php echo $total_members; ?>
                </span>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            
            <?php if (!empty($message)): ?>
                <div class="mb-4">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <div class="corporate-block-wrapper">
                <div class="table-responsive">
                    <table class="table table-corporate">
                        <thead>
                            <tr>
                                <th style="width: 110px;">Ref ID</th>
                                <th>Full Name Identifier</th>
                                <th>Electronic Mail Node</th>
                                <th>Telephone Line</th>
                                <th>Physical Registry Address</th>
                                <th>Registration Date</th>
                                <th>Last Active</th>
                                <th>Role Assignment</th>
                                <th class="text-center" style="width: 120px;">Control Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($members) > 0): ?>
                                <?php foreach ($members as $member): ?>
                                    <tr>
                                        <td>
                                            <span class="badge-id-corporate">#<?php echo (int) $member['member_id']; ?></span>
                                        </td>
                                        <td>
                                            <div class="text-name-corporate">
                                                <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="meta-contact-corporate">
                                                <i class="fas fa-envelope"></i>
                                                <span><?php echo htmlspecialchars($member['email']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="meta-contact-corporate">
                                                <i class="fas fa-phone"></i>
                                                <span><?php echo htmlspecialchars($member['phone_number']); ?></span>
                                                <?php if ($member['phone_number']): ?>
                                                    &nbsp;<?php echo wa_button($member['phone_number'], '', 'Hi ' . htmlspecialchars($member['first_name']) . ', this is Apex Sports Club.', 'sm'); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-secondary text-truncate" style="max-width: 260px;" title="<?php echo htmlspecialchars($member['address']); ?>">
                                                <?php echo htmlspecialchars($member['address']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="text-secondary font-monospace" style="font-size: 0.85rem;">
                                                <i class="far fa-calendar-alt me-1 text-muted"></i>
                                                <?php echo htmlspecialchars($member['date_joined']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($member['last_login'])): 
                                                $last_ts = strtotime($member['last_login']);
                                                $days = floor((time() - $last_ts) / 86400);
                                                if ($days === 0) echo '<span class="badge bg-success-subtle text-success-emphasis" style="font-size:0.7rem;">Today</span>';
                                                elseif ($days === 1) echo '<span class="badge bg-warning-subtle text-warning-emphasis" style="font-size:0.7rem;">Yesterday</span>';
                                                elseif ($days < 7) echo '<span class="badge bg-warning-subtle text-warning-emphasis" style="font-size:0.7rem;">' . $days . ' days ago</span>';
                                                elseif ($days < 30) echo '<span class="badge bg-light text-secondary" style="font-size:0.7rem;">' . floor($days / 7) . ' weeks ago</span>';
                                                else echo '<span class="badge bg-danger-subtle text-danger-emphasis" style="font-size:0.7rem;">' . floor($days / 30) . ' months ago</span>';
                                            else: ?>
                                                <span class="text-muted" style="font-size:0.75rem;">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" class="m-0 d-flex gap-1 align-items-center" style="max-width:200px;">
                                                <?php echo csrf_field('admin_csrf'); ?>
                                                <input type="hidden" name="action" value="assign_role">
                                                <input type="hidden" name="member_id" value="<?php echo (int) $member['member_id']; ?>">
                                                <select name="role_id" class="form-select form-select-sm" style="font-size:0.75rem;padding:0.2rem 1.5rem 0.2rem 0.4rem;" onchange="this.form.submit()">
                                                    <option value="">— None —</option>
                                                    <?php foreach ($all_roles as $r): ?>
                                                        <option value="<?php echo (int)$r['role_id']; ?>" 
                                                            <?php echo ((int)$member['role_id'] === (int)$r['role_id']) ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($r['name']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </form>
                                        </td>
                                        <td class="text-center">
                                            <form method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to completely drop this membership directory record? This action cannot be undone.');">
                                                <?php echo csrf_field('admin_csrf'); ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?php echo (int) $member['member_id']; ?>">
                                                <button type="submit" class="btn-corporate btn-corporate-danger shadow-sm">
                                                    <i class="fas fa-trash-alt me-1"></i>Drop Node
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="p-5 text-center text-muted" style="background-color: #ffffff;">
                                        <div class="mb-3" style="font-size: 2.5rem;"><i class="fas fa-users text-muted"></i></div>
                                        <h5 class="fw-bold text-dark mb-1">No Members Found</h5>
                                        <p class="mb-0 small text-muted">No registered members match your search yet.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="d-flex justify-content-between align-items-center px-3 py-3 border-top">
                    <div class="text-muted small">
                        Showing <?php echo $offset + 1; ?> to <?php echo min($total_members, $offset + $per_page); ?> of <?php echo $total_members; ?> members
                    </div>
                    <nav aria-label="Member page navigation">
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search_query); ?>">Previous</a>
                            </li>
                            <?php 
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            if ($start_page > 1): ?>
                                <li class="page-item"><a class="page-link" href="?page=1&search=<?php echo urlencode($search_query); ?>">1</a></li>
                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <li class="page-item <?php echo ($page == $i) ? 'active' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search_query); ?>"><?php echo $i; ?></a>
                                </li>
                            <?php endfor; ?>
                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                <?php endif; ?>
                                <li class="page-item"><a class="page-link" href="?page=<?php echo $total_pages; ?>&search=<?php echo urlencode($search_query); ?>"><?php echo $total_pages; ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>