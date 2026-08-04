<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This migration runner must be executed from the command line." . PHP_EOL;
    exit(1);
}

require_once __DIR__ . '/../config/api_config.php';

const MIGRATIONS_DIR = __DIR__ . '/../migrations';

$args = array_slice($argv, 1);
$showHelp = in_array('--help', $args, true) || in_array('-h', $args, true);
$showStatus = in_array('--status', $args, true);
$baseline = in_array('--baseline', $args, true);

if ($showHelp) {
    printHelp();
    exit(0);
}

try {
    $migrations = discoverMigrations(MIGRATIONS_DIR);
    $conn = connectForMigrations();
    ensureMigrationsTable($conn);

    $applied = fetchAppliedMigrations($conn);
    verifyAppliedMigrationIntegrity($migrations, $applied);

    if ($showStatus) {
        printStatus($migrations, $applied);
        exit(0);
    }

    if ($baseline) {
        baselineMigrations($conn, $migrations, $applied);
        exit(0);
    }

    runPendingMigrations($conn, $migrations, $applied);
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

function printHelp(): void
{
    echo <<<HELP
Apex Sports Club migration runner

Usage:
  php scripts/migrate.php            Run pending migrations
  php scripts/migrate.php --status   Show applied and pending migrations
  php scripts/migrate.php --baseline Mark all current migrations as applied without running SQL
  php scripts/migrate.php --help     Show this help

Rules:
  - Migration files live in migrations/
  - File names must look like 001_create_core_schema.sql
  - Future schema changes must use the next number, for example:
      007_add_coach_ratings.sql
      008_add_loyalty_points.sql
  - Do not edit migrations that have already been applied. The runner checks checksums.

HELP;
}

function connectForMigrations(): mysqli
{
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $conn = new mysqli(DB_HOST, DB_USER, DB_PASSWORD);
    $conn->set_charset('utf8mb4');

    $dbName = DB_NAME;
    if ($dbName === '') {
        throw new RuntimeException('DB_NAME is empty. Set DB_NAME in .env.');
    }

    $conn->query(
        'CREATE DATABASE IF NOT EXISTS ' . quoteIdentifier($dbName)
        . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $conn->select_db($dbName);

    return $conn;
}

function quoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * @return array<string,array{version:string,name:string,path:string,checksum:string}>
 */
function discoverMigrations(string $dir): array
{
    if (!is_dir($dir)) {
        throw new RuntimeException("Migration directory does not exist: {$dir}");
    }

    $files = glob($dir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
    sort($files, SORT_NATURAL);

    $migrations = [];
    foreach ($files as $file) {
        $name = basename($file);
        if (!preg_match('/^(\d{3})_[a-z0-9_]+\.sql$/', $name, $matches)) {
            throw new RuntimeException(
                "Invalid migration filename '{$name}'. Use names like 007_add_coach_ratings.sql."
            );
        }

        $version = $matches[1];
        if (isset($migrations[$version])) {
            throw new RuntimeException("Duplicate migration number: {$version}");
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Could not read migration file: {$name}");
        }

        $migrations[$version] = [
            'version' => $version,
            'name' => $name,
            'path' => $file,
            'checksum' => hash('sha256', $sql),
        ];
    }

    if (empty($migrations)) {
        throw new RuntimeException('No migration files found.');
    }

    ksort($migrations, SORT_NATURAL);
    return $migrations;
}

function ensureMigrationsTable(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS `schema_migrations` (
            `version` CHAR(3) NOT NULL PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `checksum` CHAR(64) NOT NULL,
            `batch` INT NOT NULL,
            `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/**
 * @return array<string,array{version:string,name:string,checksum:string,batch:int,applied_at:string}>
 */
function fetchAppliedMigrations(mysqli $conn): array
{
    $applied = [];
    $result = $conn->query('SELECT version, name, checksum, batch, applied_at FROM schema_migrations ORDER BY version');
    while ($row = $result->fetch_assoc()) {
        $applied[$row['version']] = [
            'version' => $row['version'],
            'name' => $row['name'],
            'checksum' => $row['checksum'],
            'batch' => (int) $row['batch'],
            'applied_at' => $row['applied_at'],
        ];
    }
    $result->free();

    return $applied;
}

/**
 * @param array<string,array{version:string,name:string,path:string,checksum:string}> $migrations
 * @param array<string,array{version:string,name:string,checksum:string,batch:int,applied_at:string}> $applied
 */
function verifyAppliedMigrationIntegrity(array $migrations, array $applied): void
{
    foreach ($applied as $version => $record) {
        if (!isset($migrations[$version])) {
            throw new RuntimeException(
                "Applied migration {$version} is missing from migrations/. Do not delete old migration files."
            );
        }

        if ($record['name'] !== $migrations[$version]['name']) {
            throw new RuntimeException(
                "Applied migration {$version} was renamed from {$record['name']} to {$migrations[$version]['name']}."
            );
        }

        if ($record['checksum'] !== $migrations[$version]['checksum']) {
            throw new RuntimeException(
                "Applied migration {$record['name']} has changed. Create a new numbered migration instead of editing it."
            );
        }
    }
}

/**
 * @param array<string,array{version:string,name:string,path:string,checksum:string}> $migrations
 * @param array<string,array{version:string,name:string,checksum:string,batch:int,applied_at:string}> $applied
 */
function printStatus(array $migrations, array $applied): void
{
    echo 'Database: ' . DB_NAME . PHP_EOL;
    echo 'Migrations:' . PHP_EOL;

    foreach ($migrations as $version => $migration) {
        if (isset($applied[$version])) {
            echo '  [applied] ' . $migration['name'] . ' at ' . $applied[$version]['applied_at'] . PHP_EOL;
            continue;
        }

        echo '  [pending] ' . $migration['name'] . PHP_EOL;
    }
}

/**
 * @param array<string,array{version:string,name:string,path:string,checksum:string}> $migrations
 * @param array<string,array{version:string,name:string,checksum:string,batch:int,applied_at:string}> $applied
 */
function baselineMigrations(mysqli $conn, array $migrations, array $applied): void
{
    if (!tableExists($conn, 'members')) {
        throw new RuntimeException(
            'Baseline refused because the members table was not found. Run php scripts/migrate.php for a fresh database.'
        );
    }

    $pending = array_diff_key($migrations, $applied);
    if (empty($pending)) {
        echo 'No migrations to baseline.' . PHP_EOL;
        return;
    }

    $batch = nextBatch($conn);
    foreach ($pending as $migration) {
        recordMigration($conn, $migration, $batch);
        echo '[baselined] ' . $migration['name'] . PHP_EOL;
    }

    echo 'Baseline complete. Future migrations will run normally.' . PHP_EOL;
}

/**
 * @param array<string,array{version:string,name:string,path:string,checksum:string}> $migrations
 * @param array<string,array{version:string,name:string,checksum:string,batch:int,applied_at:string}> $applied
 */
function runPendingMigrations(mysqli $conn, array $migrations, array $applied): void
{
    $pending = array_diff_key($migrations, $applied);
    if (empty($pending)) {
        echo 'No pending migrations.' . PHP_EOL;
        return;
    }

    if (empty($applied) && tableExists($conn, 'members')) {
        throw new RuntimeException(
            'Existing schema detected but no migration history exists. Run php scripts/migrate.php --baseline if this database was imported manually.'
        );
    }

    $batch = nextBatch($conn);
    foreach ($pending as $migration) {
        echo '[running] ' . $migration['name'] . PHP_EOL;
        $sql = file_get_contents($migration['path']);
        if ($sql === false) {
            throw new RuntimeException('Could not read ' . $migration['name']);
        }

        runSql($conn, $sql, $migration['name']);
        recordMigration($conn, $migration, $batch);
        echo '[applied] ' . $migration['name'] . PHP_EOL;
    }

    echo 'All pending migrations applied.' . PHP_EOL;
}

function tableExists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();

    return (int) $count > 0;
}

function nextBatch(mysqli $conn): int
{
    $result = $conn->query('SELECT COALESCE(MAX(batch), 0) + 1 AS next_batch FROM schema_migrations');
    $row = $result->fetch_assoc();
    $result->free();

    return (int) ($row['next_batch'] ?? 1);
}

/**
 * @param array{version:string,name:string,path:string,checksum:string} $migration
 */
function recordMigration(mysqli $conn, array $migration, int $batch): void
{
    $version = $migration['version'];
    $name = $migration['name'];
    $checksum = $migration['checksum'];

    $stmt = $conn->prepare(
        'INSERT INTO schema_migrations (version, name, checksum, batch)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('sssi', $version, $name, $checksum, $batch);
    $stmt->execute();
    $stmt->close();
}

function runSql(mysqli $conn, string $sql, string $name): void
{
    $sql = trim($sql);
    if ($sql === '') {
        return;
    }

    if (!$conn->multi_query($sql)) {
        throw new RuntimeException("{$name}: " . $conn->error);
    }

    do {
        $result = $conn->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }

        if (!$conn->more_results()) {
            break;
        }

        if (!$conn->next_result()) {
            throw new RuntimeException("{$name}: " . $conn->error);
        }
    } while (true);
}

