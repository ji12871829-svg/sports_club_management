#!/bin/sh
# ── Apex Sports Club — migration init script ─────────────────────────
# Mounted at /docker-entrypoint-initdb.d/10-migrations.sh so it runs
# once, on the FIRST boot of a fresh database container, after the
# server has started. Applies every numbered migration in order.
#
# The old database.sql / league_team_schema.sql / fixtures_standings_schema.sql
# files were removed; migrations/ is the canonical schema. All migrations
# are idempotent (CREATE TABLE IF NOT EXISTS, ADD COLUMN IF NOT EXISTS),
# so re-running them on an existing database is harmless.
#
# It also creates/populates the `schema_migrations` table (same layout as
# scripts/migrate.php) with the real checksums, so a later
# `php scripts/migrate.php` in the app container works without --baseline.
# ─────────────────────────────────────────────────────────────────────
set -e

DB="${MARIADB_DATABASE:-${MYSQL_DATABASE:-apex_sports_club}}"

echo "[init] Applying migrations to database '${DB}'"

# ── Migration tracking table (mirrors scripts/migrate.php) ──────────
mysql -uroot "$DB" <<'SQL'
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `version` CHAR(3) NOT NULL PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `checksum` CHAR(64) NOT NULL,
    `batch` INT NOT NULL,
    `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL

batch=1
for f in /migrations/*.sql; do
    [ -e "$f" ] || continue
    case "$f" in
        *.sql)
            echo "[init]   → $(basename "$f")"
            if command -v docker_process_sql >/dev/null 2>&1; then
                docker_process_sql --database="$DB" < "$f"
            else
                mysql -uroot "$DB" < "$f"
            fi

            name=$(basename "$f")
            version=${name%%_*}
            sum=$(sha256sum "$f" | cut -d' ' -f1)
            mysql -uroot "$DB" -e \
                "INSERT IGNORE INTO schema_migrations (version, name, checksum, batch) VALUES ('$version', '$name', '$sum', $batch);"
            batch=$((batch + 1))
            ;;
    esac
done

echo "[init] All migrations applied to '${DB}' and history recorded"
