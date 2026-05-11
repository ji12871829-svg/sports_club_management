<?php
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";

$message = '';

// Handle delete operation
if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
    $member_id = $_GET['id'];
    $sql = "DELETE FROM members WHERE member_id = ?";
    
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param("i", $member_id);
        if ($stmt->execute()) {
            $message = '<div class="alert alert-success">Member deleted successfully.</div>';
            // Reset AUTO_INCREMENT when all members have been deleted
            $count_result = $conn->query("SELECT COUNT(*) AS total FROM members");
            $count = $count_result->fetch_assoc()['total'];
            if ((int)$count === 0) {
                $conn->query("ALTER TABLE members AUTO_INCREMENT = 1");
            }
        } else {
            $message = '<div class="alert alert-danger">Error deleting member: ' . $stmt->error . '</div>';
        }
        $stmt->close();
    }
}

// Fetch all members
$members = [];
$sql = "SELECT member_id, first_name, last_name, email, phone_number, address, date_joined FROM members";
if ($result = $conn->query($sql)) {
    while ($row = $result->fetch_assoc()) {
        $members[] = $row;
    }
    $result->free();
}
$conn->close();
?>

<div class="row">
    <div class="col-md-12">
        <div class="card">
            <div class="card-header">
                <h2>Manage Members</h2>
            </div>
            <div class="card-body">
                <?php echo $message; ?>
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>First Name</th>
                            <th>Last Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Address</th>
                            <th>Date Joined</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($members) > 0): ?>
                            <?php foreach ($members as $index => $member): ?>
                                <tr>
                                    <td><?php echo $index + 1; ?></td>
                                    <td><?php echo htmlspecialchars($member['first_name']); ?></td>
                                    <td><?php echo htmlspecialchars($member['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($member['email']); ?></td>
                                    <td><?php echo htmlspecialchars($member['phone_number']); ?></td>
                                    <td><?php echo htmlspecialchars($member['address']); ?></td>
                                    <td><?php echo htmlspecialchars($member['date_joined']); ?></td>
                                    <td>
                                        <a href="manage_members.php?action=delete&id=<?php echo $member['member_id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this member?');">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8">No members found.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
include_once("../includes/footer.php");
?>
