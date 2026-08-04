<?php
// ============================================================
//  public/delete_account.php
//  Member self-service account deletion.
//  Cascades: bookings, payments, churn_risk, and member record.
//  Requires confirmation via password verification.
// ============================================================
session_start();

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: login.php");
    exit;
}

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/input_sanitize.php';
require_once '../includes/csrf.php';

$member_id = (int) $_SESSION["member_id"];
$member_name = $_SESSION["first_name"] ?? '';

$error = '';
$success = '';
$password_confirm = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Verify CSRF
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'delete_account_csrf')) {
        $error = 'Security check failed. Please refresh and try again.';
    }

    // Verify password
    if (empty($error)) {
        $password_confirm = $_POST['password_confirm'] ?? '';
        $stmt = $conn->prepare("SELECT password FROM members WHERE member_id = ?");
        $stmt->bind_param("i", $member_id);
        $stmt->execute();
        $stmt->bind_result($hashed_password);
        $stmt->fetch();
        $stmt->close();

        if (!password_verify($password_confirm, $hashed_password)) {
            $error = 'Incorrect password. Account deletion cancelled.';
        }
    }

    // Process deletion
    if (empty($error)) {
        $conn->begin_transaction();
        try {
            // Delete churn risk data
            $conn->prepare("DELETE FROM member_churn_risk WHERE member_id = ?")->execute([$member_id]);

            // Delete activity log entries
            $conn->prepare("DELETE FROM activity_log WHERE record_id = ? AND module = 'Members'")->execute([$member_id]);

            // Delete bookings (cascaded by FK, but explicit for safety)
            $conn->prepare("UPDATE bookings SET member_id = NULL, coach_id = NULL WHERE member_id = ?")->execute([$member_id]);

            // Anonymize payments (keep financial records, remove member link)
            $conn->prepare("UPDATE payments SET member_id = NULL, description = CONCAT('[Deleted account #', ?, '] ', COALESCE(description, '')) WHERE member_id = ?")->execute([$member_id, $member_id]);

            // Delete the member record
            $stmt = $conn->prepare("DELETE FROM members WHERE member_id = ?");
            $stmt->bind_param("i", $member_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            // Destroy session
            $_SESSION = [];
            session_destroy();

            $success = true;
            // Redirect to home with confirmation
            header("location: index.php?account_deleted=1");
            exit;
        } catch (Exception $e) {
            $conn->rollback();
            $error = 'An error occurred while deleting your account. Please contact support.';
            error_log('Account deletion failed for member #' . $member_id . ': ' . $e->getMessage());
        }
    }
    $conn->close();
}
?>
<?php include '../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h2 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Delete Account</h2>
            </div>
            <div class="card-body">
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <div class="alert alert-warning">
                    <strong><i class="fas fa-info-circle me-2"></i>What happens when you delete your account:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Your personal profile will be permanently deleted</li>
                        <li>Your bookings will be anonymized (no longer linked to you)</li>
                        <li>Your payment records will be kept for accounting purposes but anonymized</li>
                        <li>Your churn/analytics data will be deleted</li>
                        <li>This action <strong>cannot be undone</strong></li>
                    </ul>
                </div>

                <p class="mb-3">To confirm deletion, please enter your password below.</p>

                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                    <?php echo csrf_field('delete_account_csrf'); ?>

                    <div class="mb-3">
                        <label for="password_confirm" class="form-label">Your Password</label>
                        <input type="password" name="password_confirm" id="password_confirm"
                               class="form-control" required
                               placeholder="Enter your password to confirm deletion">
                    </div>

                    <div class="mb-3 d-flex gap-2">
                        <button type="submit" class="btn btn-danger"
                                onclick="return confirm('Are you absolutely sure? This will permanently delete your account and all associated data. This cannot be undone.');">
                            <i class="fas fa-trash me-1"></i> Permanently Delete My Account
                        </button>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-1"></i> Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>