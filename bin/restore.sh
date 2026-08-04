#!/usr/bin/env bash
# ── Apex Sports Club — Database restore ────────────────────────────
# Loads a backup produced by bin/backup.sh back into the application
# database (credentials from .env). The target database is OVERWRITTEN.
#
#     bash bin/restore.sh                     # newest file in backups/
#     bash bin/restore.sh backups/apex_....sql.gz
#
# Restoring is destructive, so it requires an explicit --yes flag:
#
#     bash bin/restore.sh --yes backups/apex_....sql.gz
# ────────────────────────────────────────────────────────────────────
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

ENV_FILE="$ROOT_DIR/.env"
if [ ! -f "$ENV_FILE" ]; then
    echo "❌  .env not found at $ENV_FILE — copy .env.example first" >&2
    exit 1
fi

# ── Read DB credentials from .env ──────────────────────────────────
db_val() {
    grep -E "^[[:space:]]*$1=" "$ENV_FILE" 2>/dev/null \
        | head -n1 \
        | cut -d= -f2- \
        | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
              -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

DB_HOST="$(db_val DB_HOST)"; DB_HOST="${DB_HOST:-localhost}"
DB_USER="$(db_val DB_USER)"; DB_USER="${DB_USER:-root}"
DB_PASS="$(db_val DB_PASSWORD)"
DB_NAME="${DB_NAME:-$(db_val DB_NAME)}"; DB_NAME="${DB_NAME:-sports_club_db}"

# Override the target DB from the environment (useful for scratch-DB drills):
#   DB_NAME=my_other_db bash bin/restore.sh --yes backups/apex_....sql.gz

# ── Locate mysql client ────────────────────────────────────────────
MYSQL=""
for candidate in mysql \
                 /c/xampp/mysql/bin/mysql \
                 /usr/bin/mysql \
                 /opt/homebrew/bin/mysql \
                 /usr/local/bin/mysql; do
    if command -v "$candidate" >/dev/null 2>&1; then
        MYSQL="$(command -v "$candidate")"; break
    elif [ -x "$candidate" ]; then
        MYSQL="$candidate"; break
    fi
done
if [ -z "$MYSQL" ]; then
    echo "❌  mysql client not found — install MySQL/MariaDB client tools." >&2
    exit 1
fi

# ── Pick the backup file ───────────────────────────────────────────
FORCE=0
FILE=""
for arg in "$@"; do
    case "$arg" in
        --yes|-y|-f|--force) FORCE=1 ;;
        *) FILE="$arg" ;;
    esac
done

if [ -z "$FILE" ]; then
    FILE="$(ls -1t backups/apex_* 2>/dev/null | head -n1 || true)"
fi
if [ -z "$FILE" ] || [ ! -f "$FILE" ]; then
    echo "❌  No backup file found (looked in backups/). Pass one explicitly:" >&2
    echo "       bash bin/restore.sh --yes backups/apex_....sql.gz" >&2
    exit 1
fi
FILE="$(cd "$(dirname "$FILE")" && pwd)/$(basename "$FILE")"

echo "=========================================="
echo "  Apex Sports Club — Restore"
echo "=========================================="
echo "  Backup file : $FILE"
echo "  Target DB   : $DB_NAME"
echo "  ⚠️  This rebuilds $DB_NAME's tables from the backup (per-table DROP IF EXISTS)."

if [ "$FORCE" -ne 1 ]; then
    echo ""
    echo "Aborting: this is destructive. Re-run with --yes to proceed."
    echo "    bash bin/restore.sh --yes \"$FILE\""
    exit 1
fi

PASS_ARGS=()
[ -n "$DB_PASS" ] && PASS_ARGS=(--password="$DB_PASS")

# ── Load the dump ──────────────────────────────────────────────────
case "$FILE" in
    *.gz | *.gzip)
        echo "  Decompressing and loading..."
        gzip -dc "$FILE" | "$MYSQL" --host="$DB_HOST" --user="$DB_USER" "${PASS_ARGS[@]}" \
            --default-character-set=utf8mb4 "$DB_NAME"
        ;;
    *.sql)
        echo "  Loading..."
        "$MYSQL" --host="$DB_HOST" --user="$DB_USER" "${PASS_ARGS[@]}" \
            --default-character-set=utf8mb4 "$DB_NAME" < "$FILE"
        ;;
    *)
        echo "❌  Unknown backup format (expected .sql or .sql.gz)." >&2
        exit 1
        ;;
esac

echo "✅  Restore complete: $FILE -> $DB_NAME"
echo "=========================================="
