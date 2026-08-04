#!/usr/bin/env bash
# ── Apex Sports Club — Database backup ─────────────────────────────
# One-command MySQL dump of the application database, credentials
# read from .env. Run from anywhere:
#
#     bash bin/backup.sh
#
# Writes gzip-compressed dumps into backups/ (auto-created, git-ignored)
# and keeps the 14 most recent files. Works on Windows (Git Bash +
# XAMPP) and Linux/macOS.
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
# Handles `KEY=value`, `KEY = value` and quoted values.
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

if [ -z "$DB_NAME" ] || [ "$DB_NAME" = "sports_club_db_test" ]; then
    echo "⚠️  Refusing to back up an empty or test database ('$DB_NAME')." >&2
    exit 1
fi
# Override the target DB from the environment (useful for scratch-DB drills):
#   DB_NAME=my_other_db bash bin/backup.sh

# ── Locate mysqldump ───────────────────────────────────────────────
MYSQLDUMP=""
for candidate in mysqldump \
                 /c/xampp/mysql/bin/mysqldump \
                 /usr/bin/mysqldump \
                 /opt/homebrew/bin/mysqldump \
                 /usr/local/bin/mysqldump; do
    if command -v "$candidate" >/dev/null 2>&1; then
        MYSQLDUMP="$(command -v "$candidate")"; break
    elif [ -x "$candidate" ]; then
        MYSQLDUMP="$candidate"; break
    fi
done
if [ -z "$MYSQLDUMP" ]; then
    echo "❌  mysqldump not found — install MySQL/MariaDB client tools." >&2
    exit 1
fi

# ── Backup directory ───────────────────────────────────────────────
mkdir -p backups
if [ ! -f backups/.gitignore ]; then
    printf '*\n!.gitignore\n' > backups/.gitignore
fi

STAMP="$(date +%Y%m%d_%H%M%S)"
OUT_GZ="backups/apex_${DB_NAME}_${STAMP}.sql.gz"

echo "=========================================="
echo "  Apex Sports Club — Backup"
echo "=========================================="
echo "  Database : $DB_NAME"
echo "  Host     : $DB_HOST"

PASS_ARGS=()
[ -n "$DB_PASS" ] && PASS_ARGS=(--password="$DB_PASS")

echo "  Target   : $OUT_GZ"

# Dump to a temp file first: if mysqldump fails, no partial backup survives.
TMP_SQL="backups/.apex_${DB_NAME}_${STAMP}.tmp.sql"
if ! "$MYSQLDUMP" --host="$DB_HOST" --user="$DB_USER" "${PASS_ARGS[@]}" \
        --single-transaction --routines --triggers \
        --default-character-set=utf8mb4 \
        "$DB_NAME" 2>"$ROOT_DIR/logs/backup_err.tmp" > "$TMP_SQL"; then
    echo "❌  mysqldump failed — no backup written." >&2
    cat "$ROOT_DIR/logs/backup_err.tmp" >&2 2>/dev/null || true
    rm -f "$TMP_SQL" "$ROOT_DIR/logs/backup_err.tmp"
    exit 1
fi

# Sanity check: the dump must contain table definitions for the app schema.
if ! grep -qi "CREATE TABLE" "$TMP_SQL"; then
    echo "❌  Backup appears empty/invalid — not keeping it." >&2
    rm -f "$TMP_SQL" "$ROOT_DIR/logs/backup_err.tmp"
    exit 1
fi

if command -v gzip >/dev/null 2>&1; then
    gzip -9 -c "$TMP_SQL" > "$OUT_GZ"
    rm -f "$TMP_SQL"
else
    mv "$TMP_SQL" "$OUT_GZ"   # gzip not found — keep plain SQL
fi

if [ -s "$ROOT_DIR/logs/backup_err.tmp" ]; then
    echo "⚠️  mysqldump warnings:" >&2
    cat "$ROOT_DIR/logs/backup_err.tmp" >&2
fi
rm -f "$ROOT_DIR/logs/backup_err.tmp"

SIZE="$(du -h "$OUT_GZ" 2>/dev/null | cut -f1 || stat -c %s "$OUT_GZ")"
echo "✅  Backup complete: $OUT_GZ ($SIZE)"

# ── Prune old backups (keep the 14 most recent) ────────────────────
mapfile -t OLD < <(ls -1t backups/apex_* 2>/dev/null | tail -n +15)
for old in "${OLD[@]}"; do
    rm -f "$old"
    echo "    pruned $old"
done

echo "=========================================="
