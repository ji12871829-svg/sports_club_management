<?php
require_once __DIR__ . '/../includes/session_config.php';
asc_session_start();
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/api_config.php';
require_once __DIR__ . '/../includes/feature_helpers.php';
require_once __DIR__ . '/../includes/renewal_helpers.php';
require_once __DIR__ . '/../includes/paystack_recurring.php';
require_once __DIR__ . '/../includes/csrf.php';

// Check if user is logged in
if (!isset($_SESSION['member_id'])) {
    header('Location: login.php');
    exit;
}

$member_id = (int)$_SESSION['member_id'];
$message = '';
$message_type = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify($_POST['csrf_token'] ?? '', 'member_csrf')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_settings') {
        $auto_renew_enabled = isset($_POST['auto_renew_enabled']) ? 1 : 0;
        $renewal_days_before = (int)($_POST['renewal_days_before'] ?? 7);

        if ($renewal_days_before < 1 || $renewal_days_before > 30) {
            $renewal_days_before = 7;
        }

        $result = upsert_renewal_settings(
            $conn,
            $member_id,
            (bool)$auto_renew_enabled,
            $renewal_days_before,
            null
        );

        if ($result['success']) {
            $message = 'Auto-renewal settings updated successfully.';
            $message_type = 'success';
        } else {
            $message = 'Failed to update settings. Please try again.';
            $message_type = 'danger';
        }
    } elseif ($action === 'disable_authorization') {
        $auth_id = (int)($_POST['authorization_id'] ?? 0);

        if ($auth_id > 0) {
            // Get authorization details
            $sql = "SELECT authorization_code, member_id FROM paystack_authorizations WHERE authorization_id = ? AND member_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param('ii', $auth_id, $member_id);
                $stmt->execute();
                $result = $stmt->get_result();
                $auth = $result->fetch_assoc();
                $stmt->close();

                if ($auth) {
                    // Disable in Paystack
                    $member_data = get_member_data($conn, $member_id);
                    if ($member_data) {
                        $disable_result = paystackDisableAuthorization(
                            $auth['authorization_code'],
                            $member_data['email']
                        );

                        if (!empty($disable_result['status'])) {
                            // Mark as inactive in DB
                            $update_sql = "UPDATE paystack_authorizations SET status = 'Inactive' WHERE authorization_id = ?";
                            if ($update_stmt = $conn->prepare($update_sql)) {
                                $update_stmt->bind_param('i', $auth_id);
                                $update_stmt->execute();
                                $update_stmt->close();
                            }

                            $message = 'Payment method removed successfully.';
                            $message_type = 'success';
                        } else {
                            $message = 'Failed to remove payment method. Please try again.';
                            $message_type = 'danger';
                        }
                    }
                }
            }
        }
    }
}

// Get member data
$member_data = get_member_data($conn, $member_id);
$active_membership = get_active_membership($conn, $member_id);
$renewal_settings = get_renewal_settings($conn, $member_id);
$paystack_auth = get_paystack_authorization($conn, $member_id);

// Get renewal history
$renewal_history = [];
if (db_table_exists($conn, 'membership_renewal_logs')) {
    $sql = "
        SELECT
            renewal_log_id,
            renewal_date,
            amount,
            status,
            error_message,
            completed_at
        FROM membership_renewal_logs
        WHERE member_id = ?
        ORDER BY renewal_date DESC
        LIMIT 10
    ";

    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $renewal_history = $result->fetch_all(MYSQLI_ASSOC) ?: [];
        $stmt->close();
    }
}

function get_member_data($conn, $member_id) {
    $sql = "SELECT member_id, email, first_name, last_name FROM members WHERE member_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        $stmt->bind_param('i', $member_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $data = $result->fetch_assoc();
        $stmt->close();
        return $data;
    }
    return null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Auto-Renewal Settings - Apex Sports Club</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>

<div class="container py-5">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <h1 class="mb-4">Auto-Renewal Settings</h1>

            <?php if ($message): ?>
                <div class="alert alert-<?php echo htmlspecialchars($message_type); ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Current Membership Status -->
            <?php if ($active_membership): ?>
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">Current Membership</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Plan:</strong> <?php echo htmlspecialchars($active_membership['plan_name']); ?></p>
                                <p><strong>Start Date:</strong> <?php echo htmlspecialchars($active_membership['start_date']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>End Date:</strong> <?php echo htmlspecialchars($active_membership['end_date']); ?></p>
                                <p><strong>Status:</strong> <span class="badge bg-success">Active</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-info">
                    You don't have an active membership. <a href="memberships.php">Browse membership plans</a>
                </div>
            <?php endif; ?>

            <!-- Auto-Renewal Settings -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Auto-Renewal Settings</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <?php echo csrf_field('member_csrf'); ?>
                        <input type="hidden" name="action" value="update_settings">

                        <div class="mb-3 form-check">
                            <input
                                type="checkbox"
                                class="form-check-input"
                                id="auto_renew_enabled"
                                name="auto_renew_enabled"
                                value="1"
                                <?php echo ($renewal_settings && $renewal_settings['auto_renew_enabled']) ? 'checked' : ''; ?>
                            >
                            <label class="form-check-label" for="auto_renew_enabled">
                                Enable automatic membership renewal
                            </label>
                            <small class="d-block text-muted mt-2">
                                When enabled, your membership will automatically renew on the expiry date using your saved payment method.
                            </small>
                        </div>

                        <div class="mb-3">
                            <label for="renewal_days_before" class="form-label">Send renewal reminder (days before expiry)</label>
                            <input
                                type="number"
                                class="form-control"
                                id="renewal_days_before"
                                name="renewal_days_before"
                                min="1"
                                max="30"
                                value="<?php echo ($renewal_settings && $renewal_settings['renewal_days_before']) ? (int)$renewal_settings['renewal_days_before'] : 7; ?>"
                            >
                            <small class="text-muted">We'll send you a reminder email this many days before your membership expires.</small>
                        </div>

                        <button type="submit" class="btn btn-success">Save Settings</button>
                    </form>
                </div>
            </div>

            <!-- Saved Payment Methods -->
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0">Saved Payment Methods</h5>
                </div>
                <div class="card-body">
                    <?php if ($paystack_auth): ?>
                        <div class="alert alert-info">
                            <strong>Saved Card:</strong> <?php echo htmlspecialchars($paystack_auth['card_brand']); ?> ending in <?php echo htmlspecialchars($paystack_auth['last_four_digits']); ?>
                            <br>
                            <small>Last used: <?php echo $paystack_auth['last_used_at'] ? date('M d, Y', strtotime($paystack_auth['last_used_at'])) : 'Never'; ?></small>
                        </div>

                        <form method="POST" action="" style="display: inline;">
                            <?php echo csrf_field('member_csrf'); ?>
                            <input type="hidden" name="action" value="disable_authorization">
                            <input type="hidden" name="authorization_id" value="<?php echo (int)$paystack_auth['authorization_id']; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm" onclick="return confirm('Are you sure you want to remove this payment method?');">
                                Remove Payment Method
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="text-muted">No saved payment methods. To enable auto-renewal, make a payment through the <a href="payments.php">payments page</a>.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Renewal History -->
            <?php if (!empty($renewal_history)): ?>
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">Renewal History</h5>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($renewal_history as $log): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($log['renewal_date'])); ?></td>
                                        <td>KES <?php echo number_format($log['amount'], 2); ?></td>
                                        <td>
                                            <?php
                                            $status_class = match($log['status']) {
                                                'Success' => 'success',
                                                'Failed' => 'danger',
                                                'Pending' => 'warning',
                                                default => 'secondary'
                                            };
                                            ?>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($log['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($log['error_message']): ?>
                                                <small class="text-danger"><?php echo htmlspecialchars($log['error_message']); ?></small>
                                            <?php elseif ($log['paystack_reference']): ?>
                                                <small class="text-muted">Ref: <?php echo htmlspecialchars($log['paystack_reference']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <div class="mt-4">
                <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
