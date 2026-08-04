<?php

require_once __DIR__ . '/../config/api_config.php';

// ── AI provider key resolution (env -> settings table fallback) ───────
// Returns the value of an AI-related setting. Prefers the .env constant;
// if it is empty, falls back to the `settings` table so keys survive
// deployments where .env is missing or was overwritten.

function asc_ai_key(string $name): string
{
    static $dbKeys = null;

    // 1) settings table first (lazy, cached, defensive). Admin-managed keys
    //    in the `settings` table take priority so they can be updated from the
    //    admin panel without touching .env. Empty stored values fall through.
    if ($dbKeys === null) {
        $dbKeys = [];
        if (class_exists('mysqli')) {
            try {
                $host = defined('DB_HOST') ? DB_HOST : 'localhost';
                $user = defined('DB_USER') ? DB_USER : 'root';
                $pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
                $base = defined('DB_NAME') ? DB_NAME : 'sports_club_db';
                $c = @new mysqli($host, $user, $pass, $base);
                if (!$c->connect_error) {
                    $r = $c->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('GEMINI_API_KEY','OPENROUTER_API_KEY','OPENROUTER_MODEL','GEMINI_MODEL')");
                    if ($r) {
                        while ($row = $r->fetch_assoc()) {
                            $dbKeys[$row['setting_key']] = (string) $row['setting_value'];
                        }
                    }
                    $c->close();
                }
            } catch (\Throwable $e) {
                // Table missing or DB unreachable — silently skip
            }
        }
    }
    if (isset($dbKeys[$name]) && trim($dbKeys[$name]) !== '') {
        return trim($dbKeys[$name]);
    }

    // 2) .env constant as fallback
    if (defined($name)) {
        $val = trim((string) constant($name));
        if ($val !== '') return $val;
    }

    return '';
}

// ── Gemini API key status ─────────────────────────────────────────────

function asc_gemini_api_key_status(): array
{
    $apiKey = asc_ai_key('GEMINI_API_KEY');
    $orKey  = asc_ai_key('OPENROUTER_API_KEY');

    // Check OpenRouter first — it's the working fallback
    if ($orKey !== '') {
        $valid = preg_match('/^[a-zA-Z0-9_-]{20,}$/', $orKey) === 1;
        if ($valid) {
            return [
                'configured' => true,
                'valid_format' => true,
                'ready' => true,
                'provider' => 'openrouter',
                'message' => 'OpenRouter API key is configured.',
            ];
        }
        return [
            'configured' => true,
            'valid_format' => false,
            'ready' => false,
            'provider' => 'openrouter',
            'message' => 'OPENROUTER_API_KEY does not look valid.',
        ];
    }

    // Fall back to Gemini key check
    if ($apiKey === '') {
        return [
            'configured' => false,
            'valid_format' => false,
            'ready' => false,
            'provider' => 'none',
            'message' => 'No AI API key configured. Set OPENROUTER_API_KEY or GEMINI_API_KEY in .env.',
        ];
    }

    $validFormat = preg_match('/^(AIza|AQ\.)[0-9A-Za-z_.-]{20,}$/', $apiKey) === 1;

    if (!$validFormat) {
        return [
            'configured' => true,
            'valid_format' => false,
            'ready' => false,
            'provider' => 'gemini',
            'message' => 'GEMINI_API_KEY does not look valid. Try using OPENROUTER_API_KEY instead.',
        ];
    }

    return [
        'configured' => true,
        'valid_format' => true,
        'ready' => true,
        'provider' => 'gemini',
        'message' => 'GEMINI_API_KEY is configured (may need OpenRouter fallback for AQ keys).',
    ];
}

// ── OpenRouter API call ────────────────────────────────────────────────

function asc_openrouter_generate_text(string $prompt, array $options = []): array
{
    $apiKey = asc_ai_key('OPENROUTER_API_KEY');

    if ($apiKey === '') {
        return ['success' => false, 'error' => 'OPENROUTER_API_KEY is not configured.'];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'The PHP cURL extension is not enabled.'];
    }

    $orModel = asc_ai_key('OPENROUTER_MODEL');
    $model = $orModel !== '' ? $orModel : 'openai/gpt-4o-mini';

    $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.55;
    $maxTokens   = isset($options['maxOutputTokens']) ? (int) $options['maxOutputTokens'] : 700;
    $timeout     = isset($options['timeout']) ? (int) $options['timeout'] : 25;

    $payload = json_encode([
        'model'    => $model,
        'messages' => [
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
    ]);

    if ($payload === false) {
        return ['success' => false, 'error' => 'Could not encode OpenRouter request.'];
    }

    $url = 'https://openrouter.ai/api/v1/chat/completions';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
            'HTTP-Referer: https://apexsportsclub.local',
            'X-Title: Apex Sports Club',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlError !== '') {
        return ['success' => false, 'error' => 'cURL error: ' . $curlError];
    }

    $data = json_decode((string) $response, true);

    if ($httpCode === 200 && is_array($data)) {
        $text = $data['choices'][0]['message']['content'] ?? '';
        $text = trim((string) $text);
        if ($text === '') {
            return ['success' => false, 'error' => 'OpenRouter returned an empty response.'];
        }
        return [
            'success' => true,
            'text' => $text,
            'model' => $data['model'] ?? $model,
            'provider' => 'openrouter',
        ];
    }

    $error = 'OpenRouter HTTP ' . $httpCode;
    if (is_array($data) && isset($data['error']['message'])) {
        $error .= ': ' . $data['error']['message'];
    }
    return ['success' => false, 'error' => $error];
}

// ── Gemini API call (with automatic OpenRouter fallback) ──────────────

function asc_gemini_generate_text(string $prompt, array $options = []): array
{
    // If OpenRouter is configured, use it directly (it's simpler and works)
    $orKey = asc_ai_key('OPENROUTER_API_KEY');
    if ($orKey !== '') {
        return asc_openrouter_generate_text($prompt, $options);
    }

    // Try Gemini
    $apiKey = asc_ai_key('GEMINI_API_KEY');

    $keyStatus = asc_gemini_api_key_status();
    if (empty($keyStatus['ready'])) {
        return ['success' => false, 'error' => $keyStatus['message']];
    }

    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'The PHP cURL extension is not enabled.'];
    }

    $gemModel = asc_ai_key('GEMINI_MODEL');
    $configuredModel = $gemModel !== '' ? $gemModel : 'gemini-2.5-flash';

    $models = [$configuredModel, 'gemini-2.5-flash', 'gemini-2.0-flash'];

    $temperature = isset($options['temperature']) ? (float) $options['temperature'] : 0.55;
    $maxTokens   = isset($options['maxOutputTokens']) ? (int) $options['maxOutputTokens'] : 700;
    $timeout     = isset($options['timeout']) ? (int) $options['timeout'] : 25;

    $payload = json_encode([
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => [
            'temperature' => $temperature,
            'maxOutputTokens' => $maxTokens,
            'topP' => 0.9,
        ],
    ]);

    if ($payload === false) {
        return ['success' => false, 'error' => 'Could not encode Gemini request.'];
    }

    $lastError = 'No response from Gemini.';

    foreach ($models as $model) {
        $endpoints = [
            'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent',
            'https://generativelanguage.googleapis.com/v1/models/' . rawurlencode($model) . ':generateContent',
        ];

        foreach ($endpoints as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'x-goog-api-key: ' . $apiKey,
                ],
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => true,
            ]);

            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($curlError !== '') {
                $lastError = 'cURL error: ' . $curlError;
                continue;
            }

            $data = json_decode((string) $response, true);

            if ($httpCode === 200) {
                if (!is_array($data)) {
                    $lastError = 'Gemini returned an unreadable response.';
                    continue;
                }
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
                $text = trim((string) $text);
                if ($text === '') {
                    $lastError = 'Gemini returned an empty response.';
                    continue;
                }
                return [
                    'success' => true,
                    'text' => $text,
                    'model' => $model,
                    'provider' => 'gemini',
                ];
            }

            // If Gemini returns auth error with AQ key, try OpenRouter fallback
            $isAuthError = false;
            if (is_array($data) && isset($data['error']['message'])) {
                $msg = $data['error']['message'];
                if (strpos($msg, 'ACCESS_TOKEN_TYPE_UNSUPPORTED') !== false || strpos($msg, 'invalid authentication') !== false) {
                    $isAuthError = true;
                }
            }

            if ($isAuthError && $orKey !== '') {
                // Fall back silently to OpenRouter
                $fallback = asc_openrouter_generate_text($prompt, $options);
                if ($fallback['success']) {
                    return $fallback;
                }
                // If OpenRouter also fails, return its error
                return $fallback;
            }

            if ($httpCode === 404) {
                $lastError = "Model '{$model}' not found";
                continue;
            }

            $lastError = 'Gemini HTTP ' . $httpCode;
            if (is_array($data) && isset($data['error']['message'])) {
                $lastError .= ': ' . $data['error']['message'];
            }
        }
    }

    // All Gemini models failed — try OpenRouter as last resort
    if ($orKey !== '') {
        $fallback = asc_openrouter_generate_text($prompt, $options);
        if ($fallback['success']) {
            return $fallback;
        }
    }

    return ['success' => false, 'error' => $lastError];
}

// ── AI key diagnostic details ─────────────────────────────────────────
// Returns an array describing which provider keys are set, their format
// validity, and the active provider — for rendering a diagnostic panel.

function asc_ai_diagnostics(): array
{
    $gemKey   = asc_ai_key('GEMINI_API_KEY');
    $orKey    = asc_ai_key('OPENROUTER_API_KEY');
    $gemModel = asc_ai_key('GEMINI_MODEL');
    $orModel  = asc_ai_key('OPENROUTER_MODEL');

    $gemValid = $gemKey !== '' && preg_match('/^(AIza|AQ\.)[0-9A-Za-z_.-]{20,}$/', $gemKey) === 1;
    $orValid  = $orKey !== '' && preg_match('/^[a-zA-Z0-9_-]{20,}$/', $orKey) === 1;

    $status = asc_gemini_api_key_status();

    return [
        'gemini_set'      => $gemKey !== '',
        'gemini_valid'    => $gemValid,
        'gemini_masked'   => $gemKey !== '' ? substr($gemKey, 0, 6) . '…' . substr($gemKey, -4) : '',
        'openrouter_set'  => $orKey !== '',
        'openrouter_valid'=> $orValid,
        'openrouter_masked' => $orKey !== '' ? substr($orKey, 0, 6) . '…' . substr($orKey, -4) : '',
        'gemini_model'    => $gemModel !== '' ? $gemModel : 'gemini-2.5-flash (default)',
        'openrouter_model'=> $orModel !== '' ? $orModel : 'openai/gpt-4o-mini (default)',
        'provider'        => $status['provider'] ?? 'none',
        'ready'           => !empty($status['ready']),
        'status_message'  => $status['message'] ?? '',
    ];
}

// Renders a self-contained diagnostic panel (Bootstrap 5 + Font Awesome).
// Returns an HTML string — safe to echo anywhere inside the admin shell.

function asc_ai_diagnostics_panel(): string
{
    $d = asc_ai_diagnostics();
    $badge = $d['ready']
        ? '<span class="asc-badge asc-badge-success"><i class="fas fa-check-circle"></i> Ready</span>'
        : '<span class="asc-badge asc-badge-danger"><i class="fas fa-exclamation-circle"></i> Not ready</span>';

    $row = function (string $label, bool $ok, string $detail): string {
        $icon = $ok ? '<i class="fas fa-check-circle" style="color:#059669;"></i>'
                    : '<i class="fas fa-circle-xmark" style="color:#dc2626;"></i>';
        return '<div style="display:flex;align-items:center;gap:.6rem;padding:.45rem .9rem;border-bottom:1px solid var(--asc-line-soft);font-size:.85rem;">'
            . '<span style="width:150px;flex-shrink:0;font-weight:600;color:var(--asc-muted);">' . htmlspecialchars($label) . '</span>'
            . $icon
            . '<span style="color:var(--asc-body);">' . htmlspecialchars($detail) . '</span>'
            . '</div>';
    };

    $html = '<div class="asc-card" style="max-width:560px;margin:1rem 0;">';
    $html .= '<div class="asc-card-head"><h4 class="asc-card-title"><i class="fas fa-key me-1"></i> AI API Key Diagnostics</h4> ' . $badge . '</div>';
    $html .= '<div style="padding:.5rem 0;">';
    $html .= $row('Active provider', true, ucfirst($d['provider']) . ' (' . ($d['ready'] ? 'ready' : 'blocked') . ')');
    $html .= $row('Gemini key', $d['gemini_set'], $d['gemini_set'] ? ($d['gemini_valid'] ? $d['gemini_masked'] . ' · format valid' : $d['gemini_masked'] . ' · FORMAT INVALID') : 'Not set in .env or settings');
    $html .= $row('OpenRouter key', $d['openrouter_set'], $d['openrouter_set'] ? ($d['openrouter_valid'] ? $d['openrouter_masked'] . ' · format valid' : $d['openrouter_masked'] . ' · FORMAT INVALID') : 'Not set in .env or settings');
    $html .= $row('Gemini model', $d['gemini_model'] !== '', $d['gemini_model']);
    $html .= $row('OpenRouter model', $d['openrouter_model'] !== '', $d['openrouter_model']);
    $html .= '</div>';
    $html .= '<div style="padding:.9rem;">';
    $html .= '<p style="font-size:.82rem;color:var(--asc-muted);margin:0 0 .7rem;">' . htmlspecialchars($d['status_message']) . '</p>';
    $html .= '<a class="asc-btn asc-btn-primary btn-sm" href="gemini_hub.php"><i class="fas fa-plug"></i> Open Gemini Hub &amp; Test Connection</a>';
    $html .= '</div></div>';

    return $html;
}

// ── Connection test ────────────────────────────────────────────────────

function asc_gemini_test_connection(): array
{
    $testPrompt = 'Reply with exactly one word: CONNECTED';
    $result = asc_gemini_generate_text($testPrompt, [
        'maxOutputTokens' => 10,
        'temperature' => 0,
    ]);

    if ($result['success']) {
        $text = strtoupper(trim($result['text']));
        if (strpos($text, 'CONNECTED') !== false) {
            return [
                'success' => true,
                'message' => ($result['provider'] ?? 'AI') . ' API is working correctly!',
                'model' => $result['model'] ?? 'unknown',
            ];
        }
        return [
            'success' => false,
            'message' => 'API responded but with unexpected text: ' . substr($result['text'], 0, 50),
        ];
    }

    return ['success' => false, 'message' => $result['error']];
}

// ── List available OpenRouter models ──────────────────────────────────

function asc_gemini_list_models(): array
{
    $orKey = asc_ai_key('OPENROUTER_API_KEY');
    if ($orKey !== '') {
        $ch = curl_init('https://openrouter.ai/api/v1/models');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $orKey],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data ?? ['error' => 'Could not parse response'];
        }
        return ['error' => "HTTP $httpCode"];
    }

    // Fall back to Gemini model list
    $apiKey = asc_ai_key('GEMINI_API_KEY');
    $url = 'https://generativelanguage.googleapis.com/v1/models';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $apiKey],
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode !== 200) return ['error' => "HTTP $httpCode"];
    $data = json_decode($response, true);
    return $data ?? ['error' => 'Could not parse response'];
}
