<?php
include_once '../includes/admin_header.php';
require_once __DIR__ . '/../includes/gemini_client.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once '../includes/csrf.php';

require_once __DIR__ . '/../includes/input_sanitize.php';

// ── AI Provider Key management (settings table) ───────────────────────
$keysSavedMsg = '';
$keysError = '';
$savedKeys = [];

// Load current values from settings table (masked for display).
$keyMeta = [
    'GEMINI_API_KEY'     => ['label' => 'Gemini API Key',      'hint' => 'AI Studio key for Gemini models'],
    'OPENROUTER_API_KEY' => ['label' => 'OpenRouter API Key',  'hint' => 'Fallback provider (recommended)'],
    'GEMINI_MODEL'       => ['label' => 'Gemini Model',        'hint' => 'e.g. gemini-2.5-flash'],
    'OPENROUTER_MODEL'   => ['label' => 'OpenRouter Model',    'hint' => 'e.g. google/gemini-2.5-flash'],
];

if ($conn && $conn instanceof mysqli) {
    $r = $conn->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('GEMINI_API_KEY','OPENROUTER_API_KEY','GEMINI_MODEL','OPENROUTER_MODEL')");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $savedKeys[$row['setting_key']] = (string) $row['setting_value'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_ai_keys') {
    if (!csrf_verify($_POST['csrf_token'] ?? '', 'gemini_keys_csrf')) {
        $keysError = 'Security check failed. Please refresh and try again.';
    } else {
        $updated = 0;
        foreach ($keyMeta as $key => $meta) {
            $field = 'key_' . strtolower($key);
            $val = trim((string) ($_POST[$field] ?? ''));
            // Blank field = keep current value.
            if ($val === '') {
                continue;
            }
            $stmt = $conn->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            if ($stmt) {
                $stmt->bind_param('ss', $key, $val);
                $stmt->execute();
                $stmt->close();
                $savedKeys[$key] = $val;
                $updated++;
            }
        }
        if ($updated > 0) {
            $keysSavedMsg = 'AI provider settings saved successfully. Changes take effect immediately.';
            // Re-evaluate status after save.
            $keyStatus = asc_gemini_api_key_status();
            $apiReady = !empty($keyStatus['ready']);
            $statusLabel = $apiReady ? 'API ready' : (!empty($keyStatus['configured']) ? 'Key format issue' : 'API key missing');
        }
    }
}

// ── Status (evaluated after a possible save above) ────────────────────
if (!isset($keyStatus)) {
    $keyStatus = asc_gemini_api_key_status();
    $apiReady = !empty($keyStatus['ready']);
    $statusLabel = $apiReady ? 'API ready' : (!empty($keyStatus['configured']) ? 'Key format issue' : 'API key missing');
}
$model = function_exists('config_value') ? config_value('GEMINI_MODEL', 'gemini-2.5-flash') : 'gemini-2.5-flash';
$prompt = trim((string) ($_POST['prompt'] ?? ''));
$answer = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['action']) && csrf_verify($_POST['csrf_token'] ?? '', 'gemini_hub_csrf')) {
    if ($prompt === '') {
        $error = 'Enter a prompt first.';
    } else {
        $hubPrompt = "You are the Apex Sports Club admin assistant. Answer clearly and concisely for a sports club admin. "
            . "If the request asks for code or database changes, explain the next practical steps instead of inventing private data.\n\n"
            . "Admin request: " . $prompt;

        $result = asc_gemini_generate_text($hubPrompt, [
            'temperature' => 0.35,
            'maxOutputTokens' => 700,
            'timeout' => 20,
        ]);

        if (!empty($result['success'])) {
            $answer = trim((string) ($result['text'] ?? ''));
        } else {
            $error = (string) ($result['error'] ?? 'Gemini did not return a response.');
        }
    }
}

$shortcuts = [
    [
        'title' => 'Match Predictions',
        'text' => 'Win, draw, and loss analysis for upcoming fixtures.',
        'href' => 'ai_predictions.php',
        'icon' => 'fa-chart-line',
        'color' => '#1d5c8f',
    ],
    [
        'title' => 'Match Reports',
        'text' => 'Generate and publish full-time match reports.',
        'href' => 'ai_match_reports.php',
        'icon' => 'fa-newspaper',
        'color' => '#059669',
    ],
    [
        'title' => 'Diagnostics',
        'text' => 'Check API key, cURL, SSL, and Gemini connectivity.',
        'href' => 'gemini_test.php',
        'icon' => 'fa-plug',
        'color' => '#1a5a8c',
    ],
];

/**
 * Mask a secret for display: show last 4 chars only.
 */
function mask_secret(string $value): string {
    $len = strlen($value);
    if ($len <= 8) return $value === '' ? 'Not set' : str_repeat('•', $len);
    return '••••••••' . substr($value, -4) . ' (' . $len . ' chars)';
}
?>

<style>
.gemini-shell {
    max-width: 1120px;
}
.gemini-status {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #ffffff;
}
.gemini-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    display: inline-block;
}
.gemini-shortcut {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    background: #ffffff;
    text-decoration: none;
    color: #0f172a;
    transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
}
.gemini-shortcut:hover {
    transform: translateY(-2px);
    border-color: #cbd5e1;
    box-shadow: 0 8px 18px rgba(15, 23, 42, .08);
    color: #0f172a;
}
.gemini-icon {
    width: 38px;
    height: 38px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #ffffff;
}
.gemini-answer {
    white-space: pre-wrap;
    line-height: 1.6;
}
.key-cell code {
    background: #f1f5f9;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: .78rem;
    color: #475569;
}
</style>

<div class="gemini-shell mx-auto">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
      <h2 class="mb-1">Gemini Hub</h2>
      <p class="text-muted mb-0">Fast access to Apex Sports Club AI tools.</p>
    </div>
    <div class="gemini-status px-3 py-2 d-flex align-items-center gap-2">
      <span class="gemini-dot" style="background: <?php echo $apiReady ? '#16a34a' : '#f59e0b'; ?>"></span>
      <span class="fw-semibold"><?php echo e($statusLabel); ?></span>
      <span class="text-muted small"><?php echo e($model); ?></span>
    </div>
  </div>

  <?php if (!$apiReady): ?>
    <div class="alert alert-warning border-0 shadow-sm">
      <?php echo e($keyStatus['message'] ?? 'Add GEMINI_API_KEY to .env.'); ?>
      Add your provider keys below (stored in the database) or in <code>.env</code>, then use Diagnostics to confirm the connection.
    </div>
  <?php endif; ?>

  <div class="row g-3 mb-4">
    <?php foreach ($shortcuts as $shortcut): ?>
      <div class="col-md-4">
        <a class="gemini-shortcut d-block h-100 p-3" href="<?php echo e($shortcut['href']); ?>">
          <div class="d-flex align-items-start gap-3">
            <span class="gemini-icon" style="background: <?php echo e($shortcut['color']); ?>;">
              <i class="fas <?php echo e($shortcut['icon']); ?>"></i>
            </span>
            <span>
              <span class="d-block fw-bold mb-1"><?php echo e($shortcut['title']); ?></span>
              <span class="d-block text-muted small"><?php echo e($shortcut['text']); ?></span>
            </span>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($keysError)): ?>
    <div class="alert alert-danger border-0 shadow-sm"><i class="fas fa-exclamation-circle me-2"></i><?php echo e($keysError); ?></div>
  <?php endif; ?>
  <?php if (!empty($keysSavedMsg)): ?>
    <div class="alert alert-success border-0 shadow-sm"><i class="fas fa-check-circle me-2"></i><?php echo e($keysSavedMsg); ?></div>
  <?php endif; ?>

  <!-- AI Provider Keys (database-managed) -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
      <strong><i class="fas fa-key me-1 text-secondary"></i> AI Provider Keys</strong>
      <span class="badge <?php echo $apiReady ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo $apiReady ? 'Connected' : 'Needs keys'; ?></span>
    </div>
    <div class="card-body">
      <p class="text-muted small mb-3">
        Keys are stored in the <code>settings</code> database table and override <code>.env</code>. Leave a field blank to keep its current value.
      </p>
      <form method="post" class="mb-0">
        <?php echo csrf_field('gemini_keys_csrf'); ?>
        <input type="hidden" name="action" value="save_ai_keys">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-3" style="font-size:.85rem;">
            <thead class="table-light">
              <tr>
                <th style="width:22%;">Setting</th>
                <th style="width:28%;">Current value</th>
                <th>New value (blank = keep)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($keyMeta as $key => $meta): ?>
                <tr class="key-cell">
                  <td>
                    <div class="fw-semibold"><?php echo e($meta['label']); ?></div>
                    <div class="text-muted" style="font-size:.72rem;"><?php echo e($meta['hint']); ?></div>
                  </td>
                  <td><code><?php echo e(mask_secret($savedKeys[$key] ?? '')); ?></code></td>
                  <td>
                    <input type="text" class="form-control form-control-sm" name="key_<?php echo strtolower($key); ?>"
                           placeholder="Enter new value…" autocomplete="off">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-primary btn-sm"><i class="fas fa-save me-1"></i> Save Keys</button>
          <a href="gemini_test.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-plug me-1"></i> Run Diagnostics</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
      <strong>Quick Gemini Prompt</strong>
      <span class="badge <?php echo $apiReady ? 'bg-success' : 'bg-secondary'; ?>"><?php echo $apiReady ? 'Live' : 'Needs key'; ?></span>
    </div>
    <div class="card-body">
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
      <?php endif; ?>

      <form method="post" class="mb-0">
        <?php echo csrf_field('gemini_hub_csrf'); ?>
        <label class="form-label fw-semibold" for="prompt">Ask Gemini</label>
        <textarea id="prompt" name="prompt" class="form-control mb-3" rows="5" placeholder="Example: Write a WhatsApp reminder for Saturday's fixture"><?php echo e($prompt); ?></textarea>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-primary" <?php echo $apiReady ? '' : 'disabled'; ?>>
            <i class="fas fa-paper-plane me-1"></i> Send
          </button>
          <a href="gemini_test.php" class="btn btn-outline-secondary">
            <i class="fas fa-plug me-1"></i> Diagnostics
          </a>
        </div>
      </form>

      <?php if ($answer !== ''): ?>
        <div class="border-top mt-4 pt-4">
          <div class="fw-semibold mb-2">Gemini Response</div>
          <div class="gemini-answer bg-light border rounded p-3"><?php echo e($answer); ?></div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include_once '../includes/footer.php'; ?>
