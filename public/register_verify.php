<?php
require_once '../includes/session_config.php';
asc_session_start();

require_once '../config/db_connect.php';
require_once '../includes/send_email.php';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pending = $_SESSION['pending_registration'] ?? null;
if (!$pending) {
    header('Location: register.php');
    exit;
}

$otp_err = '';
$general_err = '';
$notice = $_SESSION['registration_notice'] ?? '';
unset($_SESSION['registration_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $general_err = 'Session expired. Please refresh and try again.';
    } elseif (isset($_POST['resend_otp'])) {
        $otp_code = (string) random_int(100000, 999999);
        $pending['otp_hash'] = password_hash($otp_code, PASSWORD_DEFAULT);
        $pending['otp_expires'] = time() + 900;
        $pending['otp_sent_at'] = time();
        $_SESSION['pending_registration'] = $pending;

        if (sendEmail(
            $pending['email'],
            $pending['first_name'] . ' ' . $pending['last_name'], 
            'Your Apex Sports Club verification code',
            emailRegistrationOtp($pending['first_name'], $otp_code)
        )) {
            $notice = 'A new verification code has been sent to ' . htmlspecialchars($pending['email'], ENT_QUOTES, 'UTF-8') . '.';
        } else {
            $general_err = 'Unable to resend verification code. Please try again later.';
        }
    } else {
        $otp_code = trim($_POST['otp'] ?? '');

        if ($otp_code === '') {
            $otp_err = 'Enter the 6-digit verification code.';
        } elseif (time() > ($pending['otp_expires'] ?? 0)) {
            $otp_err = 'This verification code has expired. Request a new one.';
        } elseif (!password_verify($otp_code, $pending['otp_hash'])) {
            $otp_err = 'Invalid verification code. Check the email and try again.';
        } else {
            $referrer_id = null;
            if (!empty($pending['ref_code'])) {
                $refStmt = $conn->prepare('SELECT member_id FROM members WHERE referral_code = ? LIMIT 1');
                if ($refStmt) {
                    $refStmt->bind_param('s', $pending['ref_code']);
                    $refStmt->execute();
                    $refRow = $refStmt->get_result()->fetch_assoc();
                    if ($refRow) {
                        $referrer_id = (int) $refRow['member_id'];
                    }
                    $refStmt->close();
                }
            }

            $insertSql = "INSERT INTO members (first_name,last_name,email,password,phone_number,address,referred_by) VALUES (?,?,?,?,?,?,?)";
            $insertStmt = $conn->prepare($insertSql);
            if ($insertStmt) {
                $insertStmt->bind_param(
                    'ssssssi',
                    $pending['first_name'],
                    $pending['last_name'],
                    $pending['email'],
                    $pending['password'],
                    $pending['phone_number'],
                    $pending['address'],
                    $referrer_id
                );

                if ($insertStmt->execute()) {
                    $new_member_id = $conn->insert_id;

                    if ($referrer_id) {
                        $chk_ref = $conn->prepare("SELECT id FROM member_referrals WHERE referee_email = ? AND referrer_id = ? LIMIT 1");
                        if ($chk_ref) {
                            $chk_ref->bind_param('si', $pending['email'], $referrer_id);
                            $chk_ref->execute();
                            $ref_row = $chk_ref->get_result()->fetch_assoc();
                            $chk_ref->close();

                            if ($ref_row) {
                                $upd_ref = $conn->prepare("UPDATE member_referrals SET referee_member_id = ?, status = 'joined' WHERE id = ?");
                                if ($upd_ref) {
                                    $upd_ref->bind_param('ii', $new_member_id, $ref_row['id']);
                                    $upd_ref->execute();
                                    $upd_ref->close();
                                }
                            } else {
                                $ins_ref = $conn->prepare("INSERT INTO member_referrals (referrer_id, referee_email, referee_member_id, code, status) VALUES (?, ?, ?, ?, 'joined')");
                                if ($ins_ref) {
                                    $ins_ref->bind_param('isis', $referrer_id, $pending['email'], $new_member_id, $pending['ref_code']);
                                    $ins_ref->execute();
                                    $ins_ref->close();
                                }
                            }
                        }
                    }

                    sendEmail(
                        $pending['email'],
                        $pending['first_name'] . ' ' . $pending['last_name'], 
                        'Welcome to Apex Sports Club 🏆',
                        emailWelcome($pending['first_name'])
                    );

                    unset($_SESSION['pending_registration']);
                    header('Location: login.php?registered=1');
                    exit;
                }

                if ($conn->errno === 1062) {
                    $general_err = 'This email address is already registered. Please log in instead.';
                } else {
                    $general_err = 'Something went wrong while completing registration. Please try again.';
                }

                $insertStmt->close();
            } else {
                $general_err = 'Unable to complete registration. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Verify Registration | Apex Sports Club</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>
<style>
:root{--primary:#1d5c8f;--primary-dark:#14497a;}*{margin:0;padding:0;box-sizing:border-box;}body{min-height:100vh;font-family:'Inter',sans-serif;background:linear-gradient(rgba(15,23,42,.84),rgba(15,23,42,.92)),url('../sports.jpeg') center center/cover no-repeat fixed;display:flex;align-items:center;justify-content:center;padding:40px 20px;} .auth-card{background:rgba(255,255,255,.08);backdrop-filter:blur(18px);border:1px solid rgba(255,255,255,.12);border-radius:30px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.35);width:min(520px,100%);} .auth-body{padding:42px;} .logo-badge{width:64px;height:64px;border-radius:20px;background:linear-gradient(135deg,#1d5c8f,#5b96c4);display:flex;align-items:center;justify-content:center;color:white;font-size:24px;margin-bottom:26px;box-shadow:0 16px 34px rgba(29,92,143,.35);} .heading{color:white;font-size:2rem;font-weight:800;letter-spacing:-1px;margin-bottom:10px;} .subheading{color:rgba(255,255,255,.7);line-height:1.7;margin-bottom:34px;font-size:.94rem;} .form-group{margin-bottom:22px;} .form-label{display:block;color:rgba(255,255,255,.88);font-size:.84rem;font-weight:600;margin-bottom:10px;} .input-wrapper{position:relative;} .input-wrapper i{position:absolute;top:50%;left:18px;transform:translateY(-50%);color:rgba(255,255,255,.4);font-size:14px;} .form-control{width:100%;height:56px;border:none;border-radius:18px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.08);padding:0 18px 0 50px;color:white;font-size:.95rem;transition:.2s ease;} .form-control:focus{outline:none;border-color:rgba(96,165,250,.6);background:rgba(255,255,255,.12);box-shadow:0 0 0 4px rgba(29,92,143,.15);} .form-control.is-invalid{border-color:#ef4444;} .invalid-feedback{margin-top:8px;color:#fca5a5;font-size:.8rem;} .auth-button{width:100%;border:none;border-radius:18px;background:#1d5c8f;color:white;font-size:.95rem;font-weight:700;padding:16px;cursor:pointer;transition:.2s ease;} .auth-button:hover{background:#14497a;} .bottom-text{margin-top:24px;color:rgba(255,255,255,.7);font-size:.92rem;text-align:center;} .bottom-text a{color:white;text-decoration:none;font-weight:700;} .alert-modern{padding:16px;border-radius:16px;background:rgba(220,38,38,.12);color:#fee2e2;margin-bottom:24px;border:1px solid rgba(248,113,113,.18);} .alert-success{padding:16px;border-radius:16px;background:rgba(34,197,94,.12);color:#dcfce7;margin-bottom:24px;border:1px solid rgba(74,222,128,.18);} .small-text{color:rgba(255,255,255,.7);font-size:.88rem;margin-top:8px;}
</style>
</head>
<body>
<div class="auth-card">
    <div class="auth-body">
        <div class="logo-badge">ASC</div>
        <h1 class="heading">Verify your account</h1>
        <p class="subheading">We emailed a 6-digit code to <?php echo htmlspecialchars($pending['email'], ENT_QUOTES, 'UTF-8'); ?>.</p>

        <?php if (!empty($notice)): ?>
            <div class="alert-success"><?php echo $notice; ?></div>
        <?php endif; ?>

        <?php if (!empty($general_err)): ?>
            <div class="alert-modern"><?php echo $general_err; ?></div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="form-group">
                <label class="form-label">Verification code</label>
                <div class="input-wrapper">
                    <i class="fas fa-key"></i>
                    <input type="text" name="otp" class="form-control <?php echo (!empty($otp_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($_POST['otp'] ?? ''); ?>" autocomplete="one-time-code">
                </div>
                <div class="invalid-feedback"><?php echo $otp_err; ?></div>
                <div class="small-text">Enter the 6-digit code from your email. Code expires in 15 minutes.</div>
            </div>

            <button type="submit" class="auth-button">Verify account</button>
        </form>

        <form method="POST" style="margin-top:14px;">
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
            <button type="submit" name="resend_otp" class="auth-button" style="background:#0f172a;">Resend code</button>
        </form>

        <div class="bottom-text">
            Already have an account? <a href="login.php">Sign in</a>
        </div>
    </div>
</div>
</body>
</html>
