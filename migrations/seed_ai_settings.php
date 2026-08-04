<?php
/**
 * One-off seeder: copies GEMINI_API_KEY / OPENROUTER_API_KEY / models
 * from .env into the `settings` table so gemini_client.php's DB fallback
 * (asc_ai_key) works even when .env is missing on a host.
 *
 * Run:  php migrations/seed_ai_settings.php   (from project root)
 * Safe to re-run (ON DUPLICATE KEY UPDATE).
 */

$envFile = __DIR__ . '/../.env';
$env = [];
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        $eq = strpos($line, '=');
        if ($eq === false) continue;
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        $v = trim($v, " \t\"'");
        if ($k !== '') $env[$k] = $v;
    }
} else {
    echo "No .env found at $envFile\n";
    exit(1);
}

$c = new mysqli('localhost', 'root', '', 'sports_club_db');
if ($c->connect_error) {
    echo 'DB connect failed: ' . $c->connect_error . "\n";
    exit(1);
}

$stmt = $c->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
$seeded = [];
foreach (['GEMINI_API_KEY', 'OPENROUTER_API_KEY', 'GEMINI_MODEL', 'OPENROUTER_MODEL'] as $key) {
    $v = $env[$key] ?? '';
    if ($v === '') {
        echo "$key: EMPTY in .env, skipped\n";
        continue;
    }
    $stmt->bind_param('ss', $key, $v);
    $stmt->execute();
    $seeded[] = $key;
    echo "$key: seeded (len " . strlen($v) . ")\n";
}
echo 'Seeded: ' . (implode(', ', $seeded) ?: 'nothing') . "\n";
