<?php
// ============================================================
//  admin/login.php
//  UI Upgrades:
//    🎨 Clean, High-End Admin Interface
// ============================================================
require_once '../includes/session_config.php';
require_once '../includes/admin_2fa.php';
asc_session_start();

if (!empty($_SESSION['admin_loggedin'])) {
    header('location: admin_dashboard.php');
    exit;
}
if (admin_2fa_pending_valid()) {
    header('location: admin_verify_2fa.php');
    exit;
}

require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/csrf.php';
require_once '../includes/rate_limiter.php';

$email = $password = "";
$email_err = $password_err = $login_err = "";

// Processing form data when form is submitted
if($_SERVER["REQUEST_METHOD"] == "POST"){

    // ── CSRF Verification ─────────────────────────────────────
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_login_csrf')) {
        $login_err = 'Security check failed. Please refresh and try again.';
    } else {
        // Check if email is empty
        if(empty(trim($_POST["email"]))){
            $email_err = "Please enter your email.";
        } else{
            $email = trim($_POST["email"]);
        }

        // Check if password is empty
        if(empty(trim($_POST["password"]))){
            $password_err = "Please enter your password.";
        } else{
            $password = trim($_POST["password"]);
        }

        // ── Rate Limiting Check ──────────────────────────────────
        if (empty($email_err)) {
            $rate_check = check_login_attempts($conn, $email);
            if (!$rate_check['allowed']) {
                $login_err = 'Too many failed login attempts. Please try again in 15 minutes.';
            }
        }

        // Validate credentials
        if(empty($email_err) && empty($password_err) && empty($login_err)){
            $sql = "SELECT admin_id, email, password FROM admins WHERE email = ?";
            
            if($stmt = $conn->prepare($sql)){
                $stmt->bind_param("s", $param_email);
                $param_email = $email;
                $db_email = '';
                $hashed_password = '';
                
                if($stmt->execute()){
                    $stmt->store_result();
                    
                    if($stmt->num_rows == 1){
                        $stmt->bind_result($admin_id, $db_email, $hashed_password);
                        if($stmt->fetch() && is_string($hashed_password) && $hashed_password !== ''){
                            if(password_verify($password, $hashed_password)) {
                                clear_login_attempts($conn, $email);

                                $admin2fa = admin_2fa_fetch($conn, (int) $admin_id);
                                if (admin_2fa_schema_ready($conn) && admin_2fa_is_enabled($admin2fa)) {
                                    admin_2fa_start_pending_session((int) $admin_id, $db_email);
                                    $stmt->close();
                                    $conn->close();
                                    header('location: admin_verify_2fa.php');
                                    exit;
                                }

                                session_regenerate_id(true);
                                $_SESSION['admin_loggedin'] = true;
                                $_SESSION['admin_id'] = $admin_id;
                                $_SESSION['admin_email'] = $db_email;

                                require_once '../includes/activity_log.php';
                                log_activity($conn, 'Admin logged in', 'Auth', $admin_id, 'Login from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

                                $stmt->close();
                                $conn->close();
                                header('location: admin_dashboard.php');
                                exit;
                            }
                        }
                        register_login_attempt($conn, $email);
                        $login_err = "Invalid email or password.";
                    } else{
                        register_login_attempt($conn, $email);
                        $login_err = "Invalid email or password.";
                    }
                } else{
                    $login_err = "An unexpected error occurred. Please try again.";
                }
                $stmt->close();
            } else {
                $login_err = "An unexpected error occurred. Please try again.";
            }
        }
    }
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login | Apex Sports Club</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css"/>

<script type="module" src="https://unpkg.com/@splinetool/viewer@1.9.2/build/spline-viewer.js"></script>

<style>
:root {
    --primary: #4f46e5;
    --primary-dark: #4338ca;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    min-height: 100vh;
    font-family: 'Inter', sans-serif;
    background: #0f172a;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 40px 20px;
    overflow-x: hidden;
}

.page-layout {
    width: 100%;
    max-width: 1400px;
    display: grid;
    grid-template-columns: 1fr 560px;
    gap: 40px;
    align-items: center;
    position: relative;
    z-index: 10;
}

.hero-section {
    color: white;
    padding-right: 40px;
}

.spline-container {
    position: fixed;
    top: 0;
    left: 0;
    width: 100vw;
    height: 100vh;
    z-index: 1;
    pointer-events: none;
    overflow: hidden;
}

spline-viewer {
    width: 100%;
    height: 100%;
    display: block;
    position: absolute;
    top: 0;
    left: 0;
}

.hero-badge {
    display: inline-flex;
    padding: 10px 18px;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.08);
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: 2px;
    margin-bottom: 28px;
    text-transform: uppercase;
}

.hero-title {
    font-size: 4.5rem;
    line-height: 1.1;
    font-weight: 800;
    letter-spacing: -3px;
    margin-bottom: 28px;
    background: linear-gradient(to bottom, #fff, #94a3b8);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
}

.hero-text {
    max-width: 580px;
    color: rgba(255, 255, 255, 0.72);
    line-height: 1.8;
    font-size: 1.1rem;
    margin-bottom: 38px;
}

.auth-card {
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(24px);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 32px;
    overflow: hidden;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.auth-body {
    padding: 48px;
}

.logo-badge {
    width: 56px;
    height: 56px;
    border-radius: 16px;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    margin-bottom: 24px;
    box-shadow: 0 10px 20px rgba(99, 102, 241, 0.3);
}

.heading {
    color: white;
    font-size: 2.2rem;
    font-weight: 800;
    letter-spacing: -1px;
    margin-bottom: 12px;
}

.subheading {
    color: rgba(255, 255, 255, 0.6);
    line-height: 1.6;
    margin-bottom: 32px;
    font-size: .95rem;
}

.form-group {
    margin-bottom: 24px;
}

.form-label {
    display: block;
    color: rgba(255, 255, 255, 0.9);
    font-size: .85rem;
    font-weight: 600;
    margin-bottom: 8px;
}

.input-wrapper {
    position: relative;
}

.input-wrapper i {
    position: absolute;
    top: 50%;
    left: 18px;
    transform: translateY(-50%);
    color: rgba(255, 255, 255, 0.4);
    font-size: 14px;
}

.form-control {
    width: 100%;
    height: 54px;
    border-radius: 16px;
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    padding: 0 18px 0 50px;
    color: white;
    font-size: .95rem;
    transition: all 0.2s ease;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary);
    background: rgba(255, 255, 255, 0.08);
    box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.15);
}

.form-control.is-invalid {
    border-color: #ef4444;
}

.invalid-feedback {
    margin-top: 6px;
    color: #fca5a5;
    font-size: .8rem;
}


.auth-button {
    width: 100%;
    height: 56px;
    border: none;
    border-radius: 16px;
    background: var(--primary);
    color: white;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.auth-button:hover {
    background: var(--primary-dark);
    transform: translateY(-1px);
    box-shadow: 0 10px 20px rgba(99, 102, 241, 0.25);
}

.bottom-text {
    margin-top: 24px;
    text-align: center;
    color: rgba(255, 255, 255, 0.5);
    font-size: .9rem;
}

.bottom-text a {
    color: white;
    text-decoration: none;
    font-weight: 600;
}

.bottom-text a:hover {
    text-decoration: underline;
}

.alert-modern {
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.2);
    color: #fca5a5;
    padding: 14px 18px;
    border-radius: 16px;
    margin-bottom: 24px;
    font-size: .9rem;
}

@media (max-width: 1100px) {
    .page-layout {
        grid-template-columns: 1fr;
        max-width: 600px;
    }
    .hero-section {
        display: none;
    }
}

@media (max-width: 768px) {
    .auth-body {
        padding: 32px 24px;
    }
}
</style>
</head>
<body>

<!-- Spline Background Layer -->
<div class="spline-container">
    <spline-viewer url="https://prod.spline.design/kZDDjO5HuC9GJUM2/scene.splinecode" events-target="global"></spline-viewer>
</div>

<div class="page-layout">
    <div class="hero-section">
        <div class="hero-badge">Apex Sports Club</div>
        <h1 class="hero-title">Admin <br>Control Centre.</h1>
        <p class="hero-text">Secure access to the administrator dashboard. Manage operations, monitor performance, and control all club activities from one place.</p>
        
        <div style="display: flex; gap: 40px; margin-top: 40px;">
            <div>
                <h4 style="font-size: 1.5rem; font-weight: 800;">Encrypted</h4>
                <p style="color: rgba(255,255,255,0.5); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px;">2FA Protected</p>
            </div>
            <div>
                <h4 style="font-size: 1.5rem; font-weight: 800;">Full Control</h4>
                <p style="color: rgba(255,255,255,0.5); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px;">Club Management</p>
            </div>
            <div>
                <h4 style="font-size: 1.5rem; font-weight: 800;">Real-time</h4>
                <p style="color: rgba(255,255,255,0.5); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 1px;">Live Monitoring</p>
            </div>
        </div>
    </div>

    <div class="auth-card">
        <div class="auth-body">
            <div class="logo-badge">
                <i class="fas fa-shield-halved"></i>
            </div>
            <h2 class="heading">Admin Panel</h2>
            <p class="subheading">Sign in to access the administrator dashboard.</p>

            <?php if(!empty($login_err)): ?>
                <div class="alert-modern"><?php echo $login_err; ?></div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <?php echo csrf_field('admin_login_csrf'); ?>

                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <div class="input-wrapper">
                        <i class="far fa-envelope"></i>
                        <input type="email" name="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>" placeholder="admin@example.com" autocomplete="email">
                    </div>
                    <?php if (!empty($email_err)): ?>
                        <div class="invalid-feedback"><?php echo $email_err; ?></div>
                    <?php endif; ?>
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <i class="fas fa-key"></i>
                        <input type="password" name="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" placeholder="••••••••" autocomplete="current-password">
                    </div>
                    <?php if (!empty($password_err)): ?>
                        <div class="invalid-feedback"><?php echo $password_err; ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="auth-button">
                    <i class="fas fa-key"></i> Sign In
                </button>
            </form>

            <p class="bottom-text">
                <a href="../public/index.php"><i class="fas fa-arrow-left me-1"></i> Back to main website</a>
            </p>
        </div>
    </div>
</div>

</body>
</html>