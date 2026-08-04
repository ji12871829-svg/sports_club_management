<?php
// ============================================================
//  public/register.php
//  APIs Added:
//    ✅ Brevo Email API      — welcome email on registration
//    ✅ CSRF Protection      — form token
//    ✅ Rate Limiting        — max 3 registrations per IP per hour
// ============================================================
session_start();
require_once '../config/db_connect.php';
require_once '../config/api_config.php';
require_once '../includes/send_email.php';
require_once '../includes/rate_limiter.php';
require_once '../includes/csrf.php';

$first_name = $last_name = $email = $password = $confirm_password = $phone_number = $address = '';
$first_name_err = $last_name_err = $email_err = $password_err = $confirm_password_err = '';
$register_err = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // ── CSRF Verification ───────────────────────────────────────
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'register_csrf')) {
        $register_err = 'Security check failed. Please refresh and try again.';
    }

    // ── Rate Limiting Check ──────────────────────────────────────
    if (empty($register_err)) {
        $rate = check_registration_rate($conn);
        if (!$rate['allowed']) {
            $register_err = 'Too many registration attempts from this IP. Please try again later.';
        }
    }

    // ── Validate First Name ───────────────────────────────────
    if (empty(trim($_POST['first_name']))) {
        $first_name_err = 'Please enter your first name.';
    } else {
        $first_name = trim($_POST['first_name']);
    }

    // ── Validate Last Name ────────────────────────────────────
    if (empty(trim($_POST['last_name']))) {
        $last_name_err = 'Please enter your last name.';
    } else {
        $last_name = trim($_POST['last_name']);
    }

    // ── Validate Email ────────────────────────────────────────
    if (empty(trim($_POST['email']))) {
        $email_err = 'Please enter your email.';
    } else {
        $sql = 'SELECT member_id FROM members WHERE email = ?';
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('s', $param_email);
            $param_email = trim($_POST['email']);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $email_err = 'This email is already taken.';
                } else {
                    $email = trim($_POST['email']);
                }
            }
            $stmt->close();
        }
    }

    // ── Validate Password ─────────────────────────────────────
    if (empty(trim($_POST['password']))) {
        $password_err = 'Please enter a password.';
    } elseif (strlen(trim($_POST['password'])) < 8) {
        $password_err = 'Password must have at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $_POST['password'])) {
        $password_err = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[a-z]/', $_POST['password'])) {
        $password_err = 'Password must contain at least one lowercase letter.';
    } elseif (!preg_match('/[0-9]/', $_POST['password'])) {
        $password_err = 'Password must contain at least one number.';
    } else {
        $password = trim($_POST['password']);
    }

    // ── Validate Confirm Password ─────────────────────────────
    if (empty(trim($_POST['confirm_password']))) {
        $confirm_password_err = 'Please confirm password.';
    } else {
        $confirm_password = trim($_POST['confirm_password']);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = 'Passwords did not match.';
        }
    }

    // ── Validate Privacy Consent ───────────────────────────────
    $consent = isset($_POST['privacy_consent']) && $_POST['privacy_consent'] === '1';
    if (!$consent) {
        $register_err = 'You must agree to the Privacy Policy to create an account.';
    }

    $phone_number = trim($_POST['phone_number']);
    $address      = trim($_POST['address']);

    // ── Insert Member if All Validations Pass ─────────────────
    if (empty($register_err) && empty($first_name_err) && empty($last_name_err) && empty($email_err)
        && empty($password_err) && empty($confirm_password_err)) {

        register_registration_attempt($conn);

        $sql = 'INSERT INTO members (first_name, last_name, email, password, phone_number, address, privacy_consent, consent_given_at)
                VALUES (?, ?, ?, ?, ?, ?, 1, NOW())';

        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param('ssssss',
                $param_first_name, $param_last_name, $param_email,
                $param_password, $param_phone_number, $param_address
            );
            $param_first_name   = $first_name;
            $param_last_name    = $last_name;
            $param_email        = $email;
            $param_password     = password_hash($password, PASSWORD_DEFAULT);
            $param_phone_number = $phone_number;
            $param_address      = $address;

            if ($stmt->execute()) {
                // ── Send Welcome Email via Brevo ──────────────
                sendEmail(
                    $email,
                    $first_name . ' ' . $last_name,
                    'Welcome to Apex Sports Club! 🏆',
                    emailWelcome($first_name)
                );

                $conn->close();
                header('location: login.php?registered=1');
                exit;
            } else {
                echo 'Something went wrong. Please try again later.';
            }
            $stmt->close();
        }
    }

    $conn->close();
}
?>

<?php include '../includes/header.php'; ?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header">
                <h2><i class="fas fa-user-plus me-2"></i>Register New Member</h2>
            </div>
            <div class="card-body">
                <p class="text-muted">Please fill in this form to create your account.</p>

                <?php if (!empty($register_err)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($register_err); ?>
                    </div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="post">
                    <?php echo csrf_field('register_csrf'); ?>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="first_name" class="form-label">First Name</label>
                            <input type="text" name="first_name" id="first_name"
                                   class="form-control <?php echo (!empty($first_name_err)) ? 'is-invalid' : ''; ?>"
                                   value="<?php echo htmlspecialchars($first_name); ?>">
                            <span class="invalid-feedback"><?php echo $first_name_err; ?></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="last_name" class="form-label">Last Name</label>
                            <input type="text" name="last_name" id="last_name"
                                   class="form-control <?php echo (!empty($last_name_err)) ? 'is-invalid' : ''; ?>"
                                   value="<?php echo htmlspecialchars($last_name); ?>">
                            <span class="invalid-feedback"><?php echo $last_name_err; ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email Address</label>
                        <input type="email" name="email" id="email"
                               class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>"
                               value="<?php echo htmlspecialchars($email); ?>">
                        <span class="invalid-feedback"><?php echo $email_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">Password</label>
                        <input type="password" name="password" id="password"
                               class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>"
                               oninput="updatePasswordStrength(this.value)">
                        <span class="invalid-feedback"><?php echo $password_err; ?></span>
                        <!-- Password strength meter -->
                        <div id="pw-strength-meter" class="progress mt-2" style="height:6px;display:none;">
                            <div id="pw-strength-bar" class="progress-bar" role="progressbar"
                                 style="width:0%;height:6px;transition:width 0.3s ease,background-color 0.3s ease;"
                                 aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
                            </div>
                        </div>
                        <small id="pw-strength-text" class="form-text mt-1 d-none"></small>
                        <small id="pw-help-text" class="form-text text-muted d-block mt-1">
                            Minimum 8 characters. Must include uppercase, lowercase, and a number.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <input type="password" name="confirm_password" id="confirm_password"
                               class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>">
                        <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                    </div>

                    <div class="mb-3">
                        <label for="phone_number" class="form-label">
                            Phone Number <span class="text-muted">(Optional)</span>
                        </label>
                        <input type="text" name="phone_number" id="phone_number"
                               class="form-control" placeholder="e.g. 0712345678"
                               value="<?php echo htmlspecialchars($phone_number); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">
                            Address <span class="text-muted">(Optional)</span>
                        </label>
                        <input type="text" name="address" id="address"
                               class="form-control"
                               value="<?php echo htmlspecialchars($address); ?>">
                    </div>

                    <!-- Privacy Consent Checkbox -->
                    <div class="mb-3 form-check">
                        <input type="checkbox" name="privacy_consent" id="privacy_consent" value="1" class="form-check-input" required>
                        <label class="form-check-label" for="privacy_consent">
                            I have read and agree to the <a href="privacy.php" target="_blank">Privacy Policy</a>. I consent to the collection and processing of my personal data in accordance with the Kenya Data Protection Act, 2019. <span class="text-danger">*</span>
                        </label>
                    </div>

                    <div class="mb-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-user-plus me-1"></i>Register
                        </button>
                        <button type="reset" class="btn btn-secondary">Reset</button>
                    </div>

                    <p>Already have an account? <a href="login.php">Login here</a>.</p>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Password strength meter JavaScript (zxcvbn + custom) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/zxcvbn/4.4.2/zxcvbn.js" integrity="sha512-TZlMGFY9xKj38t/5m2FzJ+RM/aD5alMHDe26p0mYUMoCF5G7ibfHUQILq0qQPV3wlsnCwL+TPRNK4vIwgLOpUQ==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
function updatePasswordStrength(password) {
    const meter = document.getElementById('pw-strength-meter');
    const bar = document.getElementById('pw-strength-bar');
    const text = document.getElementById('pw-strength-text');

    if (!password) {
        meter.style.display = 'none';
        text.classList.add('d-none');
        bar.style.width = '0%';
        return;
    }

    meter.style.display = 'block';

    let score = 0;
    let message = '';
    let color = '';

    // Use zxcvbn if available, fallback to simple heuristics
    if (typeof zxcvbn !== 'undefined') {
        const result = zxcvbn(password);
        score = result.score; // 0-4

        const labels = ['Very Weak', 'Weak', 'Fair', 'Strong', 'Very Strong'];
        const colors = ['#dc3545', '#fd7e14', '#ffc107', '#198754', '#0d6efd'];
        const scores = [10, 30, 55, 80, 100];

        message = labels[score];
        color = colors[score];
        bar.style.width = scores[score] + '%';

        if (result.feedback && result.feedback.warning) {
            message += ' — ' + result.feedback.warning;
        }
    } else {
        // Fallback heuristic
        let meetsMin = password.length >= 8;
        let hasUpper = /[A-Z]/.test(password);
        let hasLower = /[a-z]/.test(password);
        let hasNumber = /[0-9]/.test(password);
        let hasSpecial = /[^A-Za-z0-9]/.test(password);

        let strong = 0;
        if (hasUpper) strong++;
        if (hasLower) strong++;
        if (hasNumber) strong++;
        if (hasSpecial) strong++;
        if (password.length >= 12) strong++;

        if (strong <= 1 || password.length < 8) {
            score = 0; message = 'Weak'; color = '#dc3545'; bar.style.width = '20%';
        } else if (strong === 2) {
            score = 1; message = 'Fair'; color = '#fd7e14'; bar.style.width = '45%';
        } else if (strong === 3) {
            score = 2; message = 'Good'; color = '#ffc107'; bar.style.width = '70%';
        } else {
            score = 3; message = 'Strong'; color = '#198754'; bar.style.width = '95%';
        }
    }

    bar.style.backgroundColor = color;
    bar.setAttribute('aria-valuenow', score * 25);

    text.textContent = message;
    text.classList.remove('d-none');
    text.classList.remove('text-danger', 'text-warning', 'text-success', 'text-primary');
    if (score <= 1) text.classList.add('text-danger');
    else if (score === 2) text.classList.add('text-warning');
    else text.classList.add('text-success');
}
</script>

<?php include '../includes/footer.php'; ?>
