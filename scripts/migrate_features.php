<?php
/**
 * Migration runner — creates all 7 new tables / columns.
 * Run once via browser: http://localhost/Apex Sports Club/scripts/migrate_features.php
 * Or CLI: php scripts/migrate_features.php
 */
require_once __DIR__ . '/../config/db_connect.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    echo "<!DOCTYPE html><html><head><title>Feature Migration</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head><body class='p-4'><h2>Feature Migration</h2><pre style='background:#f1f5f9;padding:1rem;border-radius:8px;'>";
}

$migrations = [];

// ── 1. admin_todos ───────────────────────────────────────────────────────────
$migrations['admin_todos'] = "CREATE TABLE IF NOT EXISTS admin_todos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  admin_id INT NOT NULL DEFAULT 1,
  title VARCHAR(255) NOT NULL,
  description TEXT,
  priority ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
  status ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
  due_date DATE DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// ── 2. event_checklists ──────────────────────────────────────────────────────
$migrations['event_checklists'] = "CREATE TABLE IF NOT EXISTS event_checklists (
  id INT AUTO_INCREMENT PRIMARY KEY,
  fixture_id INT DEFAULT NULL,
  item VARCHAR(255) NOT NULL,
  responsible VARCHAR(120) DEFAULT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  done_at DATETIME DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// ── 3. coach_session_notes ───────────────────────────────────────────────────
$migrations['coach_session_notes'] = "CREATE TABLE IF NOT EXISTS coach_session_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  coach_id INT NOT NULL,
  session_date DATE NOT NULL,
  sport_id INT DEFAULT NULL,
  title VARCHAR(255) NOT NULL,
  notes TEXT NOT NULL,
  ai_summary TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// ── 4. member_fines ──────────────────────────────────────────────────────────
$migrations['member_fines'] = "CREATE TABLE IF NOT EXISTS member_fines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  member_id INT NOT NULL,
  reason VARCHAR(255) NOT NULL,
  amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  due_date DATE DEFAULT NULL,
  status ENUM('pending','escalated','paid','waived') NOT NULL DEFAULT 'pending',
  escalated_at DATETIME DEFAULT NULL,
  escalation_count INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// ── 5. member_referrals ──────────────────────────────────────────────────────
$migrations['member_referrals'] = "CREATE TABLE IF NOT EXISTS member_referrals (
  id INT AUTO_INCREMENT PRIMARY KEY,
  referrer_id INT NOT NULL,
  referee_email VARCHAR(191) DEFAULT NULL,
  referee_member_id INT DEFAULT NULL,
  code VARCHAR(12) NOT NULL,
  status ENUM('pending','joined','rewarded') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rewarded_at DATETIME DEFAULT NULL,
  UNIQUE KEY uq_referral_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

// ── 6. member_memberships — pause columns ────────────────────────────────────
// Check and add each column separately (IF NOT EXISTS not always supported in ALTER)
$pauseCols = [
    'paused_at'       => "ALTER TABLE member_memberships ADD COLUMN paused_at DATETIME DEFAULT NULL",
    'pause_reason'    => "ALTER TABLE member_memberships ADD COLUMN pause_reason VARCHAR(255) DEFAULT NULL",
    'pause_until'     => "ALTER TABLE member_memberships ADD COLUMN pause_until DATE DEFAULT NULL",
    'pause_days_used' => "ALTER TABLE member_memberships ADD COLUMN pause_days_used INT NOT NULL DEFAULT 0",
];

// ── 7. members — directory / referral columns ────────────────────────────────
$memberCols = [
    'show_in_directory' => "ALTER TABLE members ADD COLUMN show_in_directory TINYINT(1) NOT NULL DEFAULT 0",
    'referral_code'     => "ALTER TABLE members ADD COLUMN referral_code VARCHAR(12) DEFAULT NULL",
    'referred_by'       => "ALTER TABLE members ADD COLUMN referred_by INT DEFAULT NULL",
];

// ─── Run CREATE TABLE migrations ─────────────────────────────────────────────
$ok = 0; $fail = 0;
foreach ($migrations as $name => $sql) {
    if ($conn->query($sql)) {
        echo "✅ Table '{$name}' ready.\n";
        $ok++;
    } else {
        echo "❌ Failed '{$name}': " . $conn->error . "\n";
        $fail++;
    }
}

// ─── Helper: add column if not exists ────────────────────────────────────────
function addColIfMissing(mysqli $conn, string $table, string $col, string $sql): void {
    $res = $conn->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table' AND COLUMN_NAME='$col'");
    $row = $res ? $res->fetch_row() : [0];
    if ((int)$row[0] === 0) {
        if ($conn->query($sql)) {
            echo "✅ Column '{$table}.{$col}' added.\n";
        } else {
            echo "❌ Failed to add '{$table}.{$col}': " . $conn->error . "\n";
        }
    } else {
        echo "⏭️  Column '{$table}.{$col}' already exists.\n";
    }
}

foreach ($pauseCols as $col => $sql) {
    addColIfMissing($conn, 'member_memberships', $col, $sql);
}
foreach ($memberCols as $col => $sql) {
    addColIfMissing($conn, 'members', $col, $sql);
}

echo "\n✨ Migration complete.\n";

if (!$isCli) {
    echo "</pre><a href='public/dashboard.php' class='btn btn-primary mt-3'>Back to Dashboard</a></body></html>";
}


