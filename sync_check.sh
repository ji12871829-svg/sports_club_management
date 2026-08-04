#!/usr/bin/env bash
# ============================================================
# Apex Sports Club — Worktree / Main Checkout Sync Checker
# ------------------------------------------------------------
# Compares the Freebuff worktree against the live main checkout
# and reports which project files differ. Run from anywhere:
#
#   bash sync_check.sh [worktree_path] [main_path]
#
# Defaults:
#   worktree = ./.freebuff/worktrees/<current worktree>
#   main     = ../../ (parent of .freebuff)
# ============================================================

set -u

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Locate the current worktree automatically (best effort)
DEFAULT_WT=""
if [ -d "$PROJECT_ROOT/.freebuff/worktrees" ]; then
    # If we're already inside a worktree, use it; otherwise pick the newest
    if [[ "$PROJECT_ROOT" == *"/.freebuff/worktrees/"* ]]; then
        DEFAULT_WT="$PROJECT_ROOT"
    else
        DEFAULT_WT=$(ls -dt "$PROJECT_ROOT"/.freebuff/worktrees/*/ 2>/dev/null | head -1 | sed 's:/*$::')
    fi
fi

MAIN_DEFAULT="$PROJECT_ROOT"
if [[ "$PROJECT_ROOT" == *"/.freebuff/worktrees/"* ]]; then
    MAIN_DEFAULT=$(dirname "$(dirname "$(dirname "$PROJECT_ROOT")")")
fi

WT="${1:-${DEFAULT_WT:-$PROJECT_ROOT}}"
MAIN="${2:-$MAIN_DEFAULT}"

echo "=== Apex Sports Club sync check ==="
echo "Worktree: $WT"
echo "Main:     $MAIN"
echo

if [ ! -d "$WT" ] || [ ! -d "$MAIN" ]; then
    echo "ERROR: one of the paths does not exist."
    exit 1
fi

# Directories to compare (project source, not .git/.freebuff/vendor)
DIRS="admin config includes public"

DIFF_COUNT=0
MISSING_COUNT=0

for d in $DIRS; do
    if [ ! -d "$WT/$d" ] || [ ! -d "$MAIN/$d" ]; then
        continue
    fi
    while IFS= read -r -d '' f; do
        rel="${f#"$WT"/}"
        main_f="$MAIN/$rel"
        if [ ! -f "$main_f" ]; then
            echo "[MISSING in main] $rel"
            MISSING_COUNT=$((MISSING_COUNT+1))
            continue
        fi
        if ! diff -q <(tr -d '\r' < "$f") <(tr -d '\r' < "$main_f") >/dev/null 2>&1; then
            echo "[DIFFERS] $rel"
            DIFF_COUNT=$((DIFF_COUNT+1))
        fi
    done < <(find "$WT/$d" -type f \( -name '*.php' -o -name '*.css' -o -name '*.js' -o -name '*.html' \) -print0 2>/dev/null)
done

# Root-level config files
for f in .env.example; do
    if [ -f "$WT/$f" ] && [ -f "$MAIN/$f" ]; then
        if ! diff -q <(tr -d '\r' < "$WT/$f") <(tr -d '\r' < "$MAIN/$f") >/dev/null 2>&1; then
            echo "[DIFFERS] $f"
            DIFF_COUNT=$((DIFF_COUNT+1))
        fi
    fi
done

echo
echo "=== Summary ==="
echo "Differing files: $DIFF_COUNT"
echo "Missing in main: $MISSING_COUNT"
if [ "$DIFF_COUNT" -eq 0 ] && [ "$MISSING_COUNT" -eq 0 ]; then
    echo "RESULT: In sync ✓"
    exit 0
else
    echo "RESULT: OUT OF SYNC — review the list above and sync before testing on localhost."
    exit 1
fi
