#!/usr/bin/env bash
# scripts/lint_changed.sh — php-cs-fixer dry-run on changed PHP files only.
#
# Mirrors the CI static-analysis job: legacy files are never reformatted
# wholesale, but code touched in this branch must conform to .php-cs-fixer.dist.php.
#
# Usage: bash scripts/lint_changed.sh
# Exit 0 when clean; exit 1 when changed files violate the style rules.

set -euo pipefail
cd "$(dirname "$0")/.."

# Resolve the diff base: PR base if provided, otherwise merge-base with origin/main,
# otherwise the previous commit.
BASE="${1:-}"
if [ -z "$BASE" ]; then
    if git rev-parse --verify origin/main >/dev/null 2>&1; then
        BASE="$(git merge-base origin/main HEAD 2>/dev/null || echo 'HEAD~1')"
    else
        BASE="HEAD~1"
    fi
fi

# --diff-filter=d excludes files deleted in the branch (fixer cannot read them).
# Union of committed range + staged + unstaged so uncommitted edits are
# checked too (the whole point of a local pre-commit style gate). Each
# $(...) strips the trailing newline, so re-add one between the sources.
FILES="$(git diff --diff-filter=d --name-only "${BASE}"...HEAD -- '*.php' 2>/dev/null || true)"
FILES+=$'\n'"$(git diff --diff-filter=d --name-only -- '*.php' 2>/dev/null || true)"
FILES+=$'\n'"$(git diff --diff-filter=d --cached --name-only -- '*.php' 2>/dev/null || true)"
FILES="$(printf '%s\n' "$FILES" | sort -u | grep -v '^vendor/' | grep -v '^\.freebuff/' || true)"

if [ -z "$FILES" ]; then
    echo "lint:style — no changed PHP files; skipping."
    exit 0
fi

echo "lint:style — checking changed PHP files:"
echo "$FILES" | sed '/^$/d'

# Windows-friendly: invoke the binary via PHP (its #!/usr/bin/env php shebang
# does not resolve under Git Bash).
PHP_BIN="${PHP_BIN:-php}"

# shellcheck disable=SC2086  # word-splitting on FILES is intentional (one path per line)
$PHP_BIN vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php \
    --path-mode=intersection --dry-run --diff --using-cache=no $FILES
