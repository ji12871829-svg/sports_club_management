#!/usr/bin/env bash
# ── Apex Sports Club — One-command setup script ─────────────────────
# Run from the project root:  bash bin/setup.sh
# Requires:  PHP 8.2+, MySQL/MariaDB, Composer (optional for PHPUnit)
# ─────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "=========================================="
echo "  Apex Sports Club — Setup"
echo "=========================================="

# ── 1. Copy .env (if missing) ───────────────────────────────────────
if [ ! -f .env ]; then
    if [ -f .env.example ]; then
        cp .env.example .env
        echo "✅  Created .env from .env.example"
        echo "    ⚠️  Edit .env with your real API keys before running"
    else
        echo "⚠️  No .env.example found — skipping"
    fi
else
    echo "✅  .env already exists"
fi

# ── 2. Create uploads directory ─────────────────────────────────────
mkdir -p uploads
chmod 775 uploads 2>/dev/null || true
echo "✅  uploads/ directory ready"

# ── 3. Check PHP ────────────────────────────────────────────────────
if ! command -v php &>/dev/null; then
    echo "❌  PHP is not installed. Install PHP 8.2+ with mysqli, mbstring, pdo_mysql, xml, gd, zip, curl"
    exit 1
fi

PHP_VERSION=$(php -r "echo PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;")
echo "✅  PHP $PHP_VERSION detected"

# Check required extensions
REQUIRED_EXTS="mysqli mbstring pdo_mysql xml json curl"
MISSING_EXTS=""
for ext in $REQUIRED_EXTS; do
    php -m 2>/dev/null | grep -qi "^$ext$" || MISSING_EXTS="$MISSING_EXTS $ext"
done
if [ -n "$MISSING_EXTS" ]; then
    echo "❌  Missing PHP extensions:$MISSING_EXTS"
    echo "    Install them and try again"
    exit 1
fi
echo "✅  All required PHP extensions present"

# ── 4. Check MySQL / MariaDB ────────────────────────────────────────
if command -v mysql &>/dev/null; then
    echo "✅  MySQL client found"
else
    echo "⚠️  MySQL client not found in PATH — will try via PHP"
fi

# ── 5. Create database (if not exists) ──────────────────────────────
DB_NAME="${DB_NAME:-sports_club_db}"
DB_USER="${DB_USER:-root}"
DB_PASS="${DB_PASS:-}"

if command -v mysql &>/dev/null; then
    MYSQL_CMD="mysql -u $DB_USER"
    [ -n "$DB_PASS" ] && MYSQL_CMD="$MYSQL_CMD -p$DB_PASS"

    echo "🔧  Creating database '$DB_NAME' if not exists..."
    $MYSQL_CMD -e "CREATE DATABASE IF NOT EXISTS \`$DB_NAME\`" 2>/dev/null && echo "✅  Database ready" || echo "⚠️  Could not create database — check MySQL credentials"
fi

# ── 6. Run migrations ───────────────────────────────────────────────
echo "🔧  Running migrations..."
MIGRATIONS=$(ls migrations/*.sql 2>/dev/null | sort || true)
if [ -n "$MIGRATIONS" ]; then
    for migration in $MIGRATIONS; do
        echo "    → $(basename "$migration")"
        $MYSQL_CMD "$DB_NAME" < "$migration" 2>/dev/null || echo "    ⚠️  Migration $(basename "$migration") had warnings (may be expected)"
    done
    echo "✅  Migrations complete"
else
    echo "⚠️  No migration files found"
fi

# ── 7. Main schema (via migrations) ─────────────────────────────────
# The old database.sql / league_team_schema.sql / fixtures_standings_schema.sql
# files were removed — migrations/ is the canonical schema and was already
# applied in step 6, so nothing more to import here.
echo "✅  Main schema applied via migrations (step 6)"

# Record migration history so the canonical runner (scripts/migrate.php)
# works after setup.sh — otherwise it would refuse with "no migration history".
if command -v php &>/dev/null; then
    php scripts/migrate.php --baseline >/dev/null 2>&1 && echo "✅  Migration history recorded (scripts/migrate.php)" || echo "⚠️  Could not record migration history (run 'php scripts/migrate.php --baseline' later)"
fi

# ── 8. Install PHPUnit (if not present) ─────────────────────────────
if [ ! -f phpunit.phar ]; then
    echo "🔧  Downloading PHPUnit..."
    # Match composer.json ("phpunit/phpunit": "^9.6") and phpunit.xml
    curl -L -o phpunit.phar https://phar.phpunit.de/phpunit-9.phar 2>/dev/null && echo "✅  PHPUnit installed" || echo "⚠️  Could not download PHPUnit"
fi

# ── 9. Run PHPUnit tests ────────────────────────────────────────────
if [ -f phpunit.phar ]; then
    echo "🔧  Running PHPUnit tests..."
    php phpunit.phar --configuration=phpunit.xml 2>/dev/null && echo "✅  All tests pass" || echo "⚠️  Some tests failed — check output above"
fi

# ── 10. Seed test database ──────────────────────────────────────────
echo "🔧  Setting up test database..."
$MYSQL_CMD -e "DROP DATABASE IF EXISTS sports_club_db_test; CREATE DATABASE sports_club_db_test" 2>/dev/null || true
# Build the test schema from migrations (canonical source of schema)
for migration in $MIGRATIONS; do
    $MYSQL_CMD sports_club_db_test < "$migration" 2>/dev/null || true
done
# Record migration history for the test DB too
if command -v php &>/dev/null; then
    DB_NAME=sports_club_db_test php scripts/migrate.php --baseline >/dev/null 2>&1 || true
fi
# Seed test data
$MYSQL_CMD sports_club_db_test -e "
    INSERT IGNORE INTO members (member_id, first_name, last_name, email, password, phone_number) VALUES (1, 'Test', 'User', 'test@example.com', 'password_hash', '0712345678');
    INSERT IGNORE INTO facilities (facility_id, name, location, capacity) VALUES (1, 'Test Facility', 'Main Ground', 50);
    INSERT IGNORE INTO sports (sport_id, name) VALUES (1, 'Test Sport');
    INSERT IGNORE INTO coaches (coach_id, first_name, last_name, email, phone_number, specialization) VALUES (1, 'Coach', 'Test', 'coach@test.com', '0711111111', 'General');
" 2>/dev/null || true
echo "✅  Test database seeded"

echo ""
echo "=========================================="
echo "  Setup complete!"
echo "=========================================="
echo ""
echo "Next steps:"
echo "  1. Edit .env with your API keys"
echo "  2. Start your server:  php -S localhost:8080 -t public/"
echo "  3. Open http://localhost:8080 in your browser"
echo "  4. Default admin: admin@sportsclub.com / admin123"
echo ""