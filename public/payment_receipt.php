<?php
/**
 * public/payment_receipt.php
 * Generates a printable/downloadable HTML receipt for any payment.
 * Accessible by the member who made the payment or any admin.
 *
 * URL: payment_receipt.php?id=PAYMENT_ID
 *      payment_receipt.php?id=PAYMENT_ID&print=1   (auto-opens print dialog)
 */
require_once '../includes/session_config.php';
require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/url.php';
require_once __DIR__ . '/../includes/input_sanitize.php';
asc_session_start();

// ── Auth ──────────────────────────────────────────────────────────────────────
$is_member = isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true;
$is_admin  = isset($_SESSION['admin_loggedin']) && $_SESSION['admin_loggedin'] === true;

if (!$is_member && !$is_admin) {
    header('Location: login.php'); exit;
}

$payment_id = (int)($_GET['id'] ?? 0);
if ($payment_id <= 0) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:40px;">Receipt not found.</p>');
}

// ── Fetch payment ─────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT p.*,
           m.first_name, m.last_name, m.email, m.phone_number, m.address
    FROM payments p
    JOIN members m ON m.member_id = p.member_id
    WHERE p.payment_id = ?
");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment = $stmt->get_result()->fetch_assoc();
$stmt->close();
$conn->close();

if (!$payment) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:40px;">Receipt not found.</p>');
}

// Members can only view their own receipts
if ($is_member && !$is_admin) {
    if ((int)$payment['member_id'] !== (int)$_SESSION['member_id']) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:40px;">Access denied.</p>');
    }
}

// ── Build receipt data ────────────────────────────────────────────────────────
$club_name    = defined('CLUB_EMAIL_NAME') ? CLUB_EMAIL_NAME : 'Apex Sports Club';
$receipt_no   = 'RCP-' . str_pad($payment['payment_id'], 6, '0', STR_PAD_LEFT);
$paid_date    = date('d F Y', strtotime($payment['payment_date']));
$paid_time    = date('H:i', strtotime($payment['payment_date']));
$member_name  = $payment['first_name'] . ' ' . $payment['last_name'];
$auto_print   = isset($_GET['print']);

$status_color = match(strtolower($payment['payment_status'] ?? 'paid')) {
    'completed', 'paid', 'success' => '#10b981',
    'pending'                       => '#f59e0b',
    default                         => '#6b7280',
};
$status_label = ucfirst(strtolower($payment['payment_status'] ?? 'Paid'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo e($receipt_no); ?> — <?php echo e($club_name); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            padding: 40px 20px;
        }

        .receipt-wrap {
            max-width: 600px;
            margin: 0 auto;
        }

        /* Action bar (hidden when printing) */
        .action-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 10px 20px; border-radius: 8px; font-size: 14px;
            font-weight: 600; cursor: pointer; border: none; text-decoration: none;
            transition: opacity .15s;
        }
        .btn:hover { opacity: .85; }
        .btn-primary { background: #0f172a; color: #fff; }
        .btn-outline { background: #fff; color: #475569; border: 1px solid #e2e8f0; }

        /* Receipt card */
        .receipt {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            overflow: hidden;
        }

        /* Header strip */
        .receipt-header {
            background: #0f172a;
            padding: 32px 36px 28px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        .club-name {
            font-size: 22px; font-weight: 800; letter-spacing: -.3px;
        }
        .club-tagline {
            font-size: 12px; color: rgba(255,255,255,.55); margin-top: 3px;
        }
        .receipt-label {
            text-align: right;
        }
        .receipt-label .word {
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 2px; color: rgba(255,255,255,.5);
        }
        .receipt-label .number {
            font-size: 18px; font-weight: 700; color: #fff; margin-top: 2px;
        }

        /* Status banner */
        .status-banner {
            padding: 14px 36px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 15px;
            color: #fff;
        }
        .status-dot {
            width: 10px; height: 10px;
            border-radius: 50%;
            background: rgba(255,255,255,.7);
        }

        /* Body */
        .receipt-body { padding: 32px 36px; }

        /* Amount hero */
        .amount-hero {
            text-align: center;
            padding: 28px 0 24px;
            border-bottom: 1px solid #f1f5f9;
            margin-bottom: 28px;
        }
        .amount-hero .label {
            font-size: 12px; text-transform: uppercase;
            letter-spacing: 1.5px; color: #94a3b8; margin-bottom: 6px;
        }
        .amount-hero .amount {
            font-size: 48px; font-weight: 900;
            color: #0f172a; letter-spacing: -1px; line-height: 1;
        }
        .amount-hero .currency {
            font-size: 24px; font-weight: 600;
            color: #64748b; vertical-align: super;
            margin-right: 4px;
        }

        /* Details grid */
        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px 24px;
            margin-bottom: 28px;
        }
        .detail-item .label {
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 1px; color: #94a3b8; margin-bottom: 4px;
        }
        .detail-item .value {
            font-size: 14px; font-weight: 600; color: #0f172a;
        }
        .detail-item .value.muted { font-weight: 400; color: #475569; }

        /* Description box */
        .desc-box {
            background: #f8fafc;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 28px;
        }
        .desc-box .label {
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 1px; color: #94a3b8; margin-bottom: 6px;
        }
        .desc-box .value {
            font-size: 14px; color: #334155; line-height: 1.6;
        }

        /* Member section */
        .member-section {
            border-top: 1px solid #f1f5f9;
            padding-top: 24px;
            margin-bottom: 28px;
        }
        .member-section .section-title {
            font-size: 11px; text-transform: uppercase;
            letter-spacing: 1px; color: #94a3b8; margin-bottom: 14px;
        }
        .member-row {
            display: flex; align-items: center; gap: 14px;
        }
        .member-avatar {
            width: 44px; height: 44px; border-radius: 50%;
            background: #0f172a; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; font-weight: 700; flex-shrink: 0;
        }
        .member-name { font-weight: 700; font-size: 15px; }
        .member-email { font-size: 13px; color: #64748b; margin-top: 1px; }

        /* Footer */
        .receipt-footer {
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            padding: 20px 36px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .receipt-footer .note {
            font-size: 12px; color: #94a3b8; line-height: 1.5;
        }
        .receipt-footer .ref {
            font-size: 11px; color: #cbd5e1;
            font-family: monospace;
        }

        /* Print styles */
        @media print {
            body { background: #fff; padding: 0; }
            .action-bar { display: none !important; }
            .receipt { box-shadow: none; border-radius: 0; }
        }

        @media (max-width: 480px) {
            .receipt-header { flex-direction: column; gap: 12px; }
            .receipt-label { text-align: left; }
            .detail-grid { grid-template-columns: 1fr; }
            .receipt-body { padding: 24px 20px; }
            .amount-hero .amount { font-size: 36px; }
        }
    </style>
</head>
<body>

<div class="receipt-wrap">

    <!-- Action bar -->
    <div class="action-bar">
        <button class="btn btn-primary" onclick="window.print()">
            🖨️ Print Receipt
        </button>
        <a href="<?php echo e(app_url('public/payments.php')); ?>" class="btn btn-outline">
            ← Back to Payments
        </a>
        <?php if ($is_admin): ?>
        <a href="<?php echo e(app_url('admin/manage_payments.php')); ?>" class="btn btn-outline">
            Admin: Payments
        </a>
        <?php endif; ?>
    </div>

    <!-- Receipt card -->
    <div class="receipt">

        <!-- Header -->
        <div class="receipt-header">
            <div>
                <div class="club-name">⚽ <?php echo e($club_name); ?></div>
                <div class="club-tagline">Official Payment Receipt</div>
            </div>
            <div class="receipt-label">
                <div class="word">Receipt</div>
                <div class="number"><?php echo e($receipt_no); ?></div>
            </div>
        </div>

        <!-- Status banner -->
        <div class="status-banner" style="background:<?php echo $status_color; ?>;">
            <div class="status-dot"></div>
            <?php echo e($status_label); ?>
            &nbsp;·&nbsp; <?php echo e($paid_date); ?> at <?php echo e($paid_time); ?>
        </div>

        <!-- Body -->
        <div class="receipt-body">

            <!-- Amount hero -->
            <div class="amount-hero">
                <div class="label">Amount Paid</div>
                <div class="amount">
                    <span class="currency">KES</span><?php echo number_format((float)$payment['amount'], 2); ?>
                </div>
            </div>

            <!-- Detail grid -->
            <div class="detail-grid">
                <div class="detail-item">
                    <div class="label">Payment Method</div>
                    <div class="value"><?php echo e($payment['payment_method'] ?? 'N/A'); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Date & Time</div>
                    <div class="value"><?php echo e($paid_date); ?> <span style="font-weight:400;color:#64748b;"><?php echo e($paid_time); ?></span></div>
                </div>
                <div class="detail-item">
                    <div class="label">Receipt Number</div>
                    <div class="value" style="font-family:monospace;"><?php echo e($receipt_no); ?></div>
                </div>
                <div class="detail-item">
                    <div class="label">Status</div>
                    <div class="value" style="color:<?php echo $status_color; ?>;">● <?php echo e($status_label); ?></div>
                </div>
                <?php if (!empty($payment['provider_reference'])): ?>
                <div class="detail-item">
                    <div class="label">Provider Reference</div>
                    <div class="value muted" style="font-family:monospace;font-size:12px;">
                        <?php echo e($payment['provider_reference']); ?>
                    </div>
                </div>
                <?php endif; ?>
                <?php if (!empty($payment['source'])): ?>
                <div class="detail-item">
                    <div class="label">Payment Source</div>
                    <div class="value muted"><?php echo e(ucfirst($payment['source'])); ?></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Description -->
            <?php if (!empty($payment['description'])): ?>
            <div class="desc-box">
                <div class="label">Description</div>
                <div class="value"><?php echo e($payment['description']); ?></div>
            </div>
            <?php endif; ?>

            <!-- Member -->
            <div class="member-section">
                <div class="section-title">Paid By</div>
                <div class="member-row">
                    <div class="member-avatar">
                        <?php echo strtoupper(substr($payment['first_name'], 0, 1) . substr($payment['last_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <div class="member-name"><?php echo e($member_name); ?></div>
                        <div class="member-email"><?php echo e($payment['email']); ?></div>
                        <?php if ($payment['phone_number']): ?>
                            <div class="member-email"><?php echo e($payment['phone_number']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- /receipt-body -->

        <!-- Footer -->
        <div class="receipt-footer">
            <div class="note">
                This is an official receipt from <?php echo e($club_name); ?>.<br>
                Please keep this for your records.
            </div>
            <div class="ref">
                ID: <?php echo e($payment['payment_id']); ?>
            </div>
        </div>

    </div><!-- /receipt -->
</div>

<?php if ($auto_print): ?>
<script>window.onload = function() { setTimeout(function() { window.print(); }, 300); };</script>
<?php endif; ?>

</body>
</html>
