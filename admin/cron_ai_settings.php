<?php
// ============================================================
//  admin/cron_ai_settings.php
//  Settings page for the automated AI booking review cron
// ============================================================
include_once("../includes/admin_header.php");
require_once "../config/db_connect.php";
require_once "../includes/csrf.php";

$message = '';

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_cron_settings') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'admin_csrf')) {
        $message = '<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i>Security check failed.</div>';
    } else {
        $settings = [
            'enabled'         => isset($_POST['enabled']) ? '1' : '0',
            'strictness'      => in_array($_POST['strictness'] ?? '', ['Conservative', 'Balanced', 'Liberal', 'Custom'], true) ? $_POST['strictness'] : 'Balanced',
            'interval_hours'  => max(1, min(168, (int)($_POST['interval_hours'] ?? 6))),
            'batch_limit'     => max(1, min(500, (int)($_POST['batch_limit'] ?? 50))),
            'custom_prompt_text' => $_POST['custom_prompt_text'] ?? '',
            'custom_prompt_temperature' => number_format(max(0.0, min(1.0, (float)($_POST['custom_prompt_temperature'] ?? 0.20))), 2),
        ];

        $stmt = $conn->prepare("INSERT INTO cron_ai_settings (setting_key, setting_value) VALUES (?, ?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        if ($stmt) {
            foreach ($settings as $key => $value) {
                $stmt->bind_param('ss', $key, $value);
                $stmt->execute();
            }
            $stmt->close();
            $message = '<div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i>Cron AI review settings saved successfully.</div>';
        } else {
            $message = '<div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-triangle me-2"></i>Database error: ' . htmlspecialchars($conn->error) . '</div>';
        }
    }

    // Reload settings after save
    $current_settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM cron_ai_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $current_settings[$row['setting_key']] = $row['setting_value'];
        }
        $result->free();
    }
} else {
    // Fetch current settings
    $current_settings = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM cron_ai_settings");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $current_settings[$row['setting_key']] = $row['setting_value'];
        }
        $result->free();
    }
}

// Defaults if table is empty or not yet migrated
$cron_enabled      = $current_settings['enabled'] ?? '1';
$cron_strictness   = $current_settings['strictness'] ?? 'Balanced';
$cron_interval     = (int)($current_settings['interval_hours'] ?? 6);
$cron_batch_limit  = (int)($current_settings['batch_limit'] ?? 50);
$cron_custom_prompt_text = $current_settings['custom_prompt_text'] ?? '';
$cron_custom_prompt_temperature = (float)($current_settings['custom_prompt_temperature'] ?? 0.20);

$last_run = '';
$last_result = $conn->query("SELECT MAX(reviewed_at) AS last_time FROM ai_review_log WHERE admin_id = 0");
if ($last_result && $row = $last_result->fetch_assoc()) {
    $last_run = $row['last_time'] ?? '';
    $last_result->free();
}

$conn->close();
?>

<style>
    body {
        background-color: #f8fafc !important;
        color: #334155;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }
    .settings-card {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05);
        overflow: hidden;
        margin-bottom: 1.5rem;
    }
    .settings-header {
        background-color: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        padding: 1.25rem 1.5rem;
        font-size: 0.95rem;
        font-weight: 700;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 0.65rem;
    }
    .settings-body {
        padding: 1.5rem;
    }
    .form-label-custom {
        font-weight: 600;
        font-size: 0.85rem;
        color: #334155;
        margin-bottom: 0.35rem;
    }
    .form-control-custom {
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.9rem;
        padding: 0.5rem 0.75rem;
        transition: border-color 0.15s ease;
    }
    .form-control-custom:focus {
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
    }
    .form-check-custom {
        width: 48px;
        height: 26px;
        cursor: pointer;
    }
    .hint-text {
        font-size: 0.78rem;
        color: #64748b;
        margin-top: 0.25rem;
    }
    .accent-purple {
        color: #7c3aed;
    }
    .cron-schedule-example {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        padding: 0.75rem 1rem;
        font-family: monospace;
        font-size: 0.85rem;
    }
</style>

<div class="container-fluid py-5 px-4" style="max-width: 800px;">

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4 pb-3 border-bottom border-light">
        <div>
            <h2 class="fw-bold mb-1" style="color:#0f172a;letter-spacing:-0.5px;">
                <i class="fas fa-clock me-2 accent-purple"></i>AI Review Cron Settings
            </h2>
            <p class="text-muted small mb-0">Configure automated Gemini AI booking review scheduling.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="manage_bookings.php" class="btn btn-sm" style="border:1px solid #cbd5e1;background:#fff;color:#475569;font-weight:600;padding:0.45rem 1rem;border-radius:6px;">
                <i class="fas fa-calendar-check me-1"></i> Bookings
            </a>
            <a href="ai_review_log.php" class="btn btn-sm" style="border:1px solid #cbd5e1;background:#fff;color:#475569;font-weight:600;padding:0.45rem 1rem;border-radius:6px;">
                <i class="fas fa-history me-1"></i> Review Log
            </a>
        </div>
    </div>

    <?php echo $message; ?>

    <!-- Status Card -->
    <div class="settings-card">
        <div class="settings-header">
            <i class="fas fa-circle-info accent-purple"></i> Cron Status
        </div>
        <div class="settings-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge <?php echo $cron_enabled === '1' ? 'bg-success' : 'bg-secondary'; ?>" style="font-size:0.7rem;">
                            <?php echo $cron_enabled === '1' ? '● ENABLED' : '○ DISABLED'; ?>
                        </span>
                    </div>
                    <div class="hint-text mt-2">Cron status</div>
                </div>
                <div class="col-md-4">
                    <div class="fw-semibold"><?php echo htmlspecialchars($cron_strictness); ?></div>
                    <div class="hint-text">Strictness mode</div>
                </div>
                <div class="col-md-4">
                    <div class="fw-semibold">Every <?php echo $cron_interval; ?> hour(s)</div>
                    <div class="hint-text">Check interval</div>
                </div>
                <div class="col-12 mt-3">
                    <div class="d-flex align-items-center gap-2">
                        <i class="fas fa-history text-muted small"></i>
                        <span class="text-muted small">
                            Last cron run: <?php echo $last_run ? htmlspecialchars(date('d M Y H:i', strtotime($last_run))) : 'Never'; ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Settings Form -->
    <form method="post" class="settings-card">
        <?php echo csrf_field('admin_csrf'); ?>
        <input type="hidden" name="action" value="save_cron_settings">

        <div class="settings-header">
            <i class="fas fa-sliders accent-purple"></i> Configuration
        </div>

        <div class="settings-body">
            <div class="row g-4">
                <!-- Enable/Disable -->
                <div class="col-md-12">
                    <div class="d-flex align-items-center gap-3">
                        <div class="form-check form-switch m-0">
                            <input class="form-check-input form-check-custom" type="checkbox" role="switch"
                                   id="enabled" name="enabled" value="1"
                                   <?php echo $cron_enabled === '1' ? 'checked' : ''; ?>
                                   style="cursor:pointer;border-color:<?php echo $cron_enabled === '1' ? '#7c3aed' : '#cbd5e1'; ?>;">
                        </div>
                        <div>
                            <label for="enabled" class="form-label-custom mb-0" style="cursor:pointer;">
                                Enable Automated AI Reviews
                            </label>
                            <div class="hint-text">
                                When enabled, the cron job will automatically review and process pending bookings.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Strictness -->
                <div class="col-md-6">
                    <label for="strictness" class="form-label-custom">🎯 Strictness Level</label>
                    <select name="strictness" id="strictness" class="form-select form-control-custom">
                        <option value="Conservative" <?php echo $cron_strictness === 'Conservative' ? 'selected' : ''; ?>>🔒 Conservative — Strict criteria, reject unless perfect</option>
                        <option value="Balanced" <?php echo $cron_strictness === 'Balanced' ? 'selected' : ''; ?>>⚖️ Balanced — Fair review, default behavior</option>
                        <option value="Liberal" <?php echo $cron_strictness === 'Liberal' ? 'selected' : ''; ?>>🔄 Liberal — Lenient, give benefit of the doubt</option>
                        <option value="Custom" <?php echo $cron_strictness === 'Custom' ? 'selected' : ''; ?>>✏️ Custom — Write your own prompt</option>
                    </select>
                    <div class="hint-text">Controls how strict the AI is when deciding to approve or reject bookings.</div>
                </div>

                <!-- Interval hours -->
                <div class="col-md-3">
                    <label for="interval_hours" class="form-label-custom">⏱️ Interval (hours)</label>
                    <input type="number" name="interval_hours" id="interval_hours"
                           value="<?php echo $cron_interval; ?>" min="1" max="168"
                           class="form-control form-control-custom">
                    <div class="hint-text">How often the cron runs (1-168 hours).</div>
                </div>

                <!-- Batch limit -->
                <div class="col-md-3">
                    <label for="batch_limit" class="form-label-custom">📦 Batch Limit</label>
                    <input type="number" name="batch_limit" id="batch_limit"
                           value="<?php echo $cron_batch_limit; ?>" min="1" max="500"
                           class="form-control form-control-custom">
                    <div class="hint-text">Max pending bookings to review per run.</div>
                </div>

                <!-- Custom Prompt (shown when Custom is selected) -->
                <div class="col-12 <?php echo $cron_strictness !== 'Custom' ? 'd-none' : ''; ?>" id="cronCustomPromptBlock">
                    <hr class="my-2">
                    <label for="custom_prompt_text" class="form-label-custom">✏️ Custom AI Prompt / Guidelines</label>
                    <textarea name="custom_prompt_text" id="custom_prompt_text" class="form-control form-control-custom"
                              rows="5" style="font-family:monospace;font-size:0.85rem;"><?php echo htmlspecialchars($cron_custom_prompt_text); ?></textarea>
                    <div class="hint-text">This prompt will be sent to Gemini instead of the predefined levels when Custom is selected.</div>

                    <div class="row g-2 mt-2">
                        <div class="col-auto">
                            <label for="custom_prompt_temperature" class="form-label-custom small">🌡️ Temperature</label>
                            <input type="number" name="custom_prompt_temperature" id="custom_prompt_temperature"
                                   value="<?php echo number_format($cron_custom_prompt_temperature, 2); ?>"
                                   step="0.05" min="0.0" max="1.0"
                                   class="form-control form-control-custom" style="width:100px;">
                            <div class="hint-text">0.0 (strict) – 1.0 (creative). Default: 0.20</div>
                        </div>
                        <div class="col">
                            <div class="hint-text mt-3">
                                <i class="fas fa-lightbulb me-1"></i>
                                <strong>Tip:</strong> Lower temp (0.0–0.2) = predictable, Higher (0.3–0.5) = flexible.
                                Above 0.5 may give inconsistent results.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Schedule hint -->
                <div class="col-12">
                    <div class="cron-schedule-example">
                        <span class="fw-semibold">Expected cron entry:</span>
                        <code class="ms-2">0 */<?php echo $cron_interval; ?> * * * php /path/to/cron/cron_ai_booking_review.php</code>
                        <div class="hint-text mt-1">
                            Add this to your crontab (Linux) or Task Scheduler (Windows) to automate reviews every <?php echo $cron_interval; ?> hour(s).
                            The cron script reads these settings from the database automatically.
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 pt-3 border-top border-light d-flex justify-content-end gap-2">
                <a href="manage_bookings.php" class="btn btn-sm" style="border:1px solid #e2e8f0;background:#fff;color:#475569;font-weight:600;padding:0.5rem 1.25rem;border-radius:6px;">
                    Cancel
                </a>
                <button type="submit" class="btn btn-sm" style="background:#7c3aed;color:#fff;border:none;font-weight:600;padding:0.5rem 1.25rem;border-radius:6px;">
                    <i class="fas fa-save me-1"></i> Save Settings
                </button>
            </div>
        </div>
    </form>

    <!-- Manual trigger hint -->
    <div class="settings-card">
        <div class="settings-header">
            <i class="fas fa-play accent-purple"></i> Manual Trigger
        </div>
        <div class="settings-body">
            <p class="text-muted small mb-3">
                Run the AI cron review immediately, regardless of schedule. This processes all pending bookings
                using the configured strictness and sends notifications.
            </p>
            <form method="post" action="manage_bookings.php" class="d-inline">
                <?php echo csrf_field('admin_csrf'); ?>
                <input type="hidden" name="action" value="ai_cron_run_manual">
                <button type="submit" class="btn btn-sm" style="background:#7c3aed;color:#fff;border:none;font-weight:600;padding:0.5rem 1.25rem;border-radius:6px;">
                    <i class="fas fa-robot me-1"></i> Run AI Review Now
                </button>
            </form>
            <span class="text-muted small ms-2">(redirects to Manage Bookings)</span>
        </div>
    </div>

</div>

<script>
document.getElementById('strictness')?.addEventListener('change', function() {
    const block = document.getElementById('cronCustomPromptBlock');
    if (block) {
        block.classList.toggle('d-none', this.value !== 'Custom');
    }
});
</script>

<?php include_once("../includes/footer.php"); ?>
