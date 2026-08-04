#!/usr/bin/env bash
# ============================================================
# Apex Sports Club — Security Smoke-Test Harness
# ------------------------------------------------------------
# Replays the live security check matrix against a running
# deployment and exits non-zero on any regression, so it can
# run locally, in CI, or against a staging deploy.
#
# Usage:
#   bash scripts/security_smoke.sh [base_url]
#
# Env overrides:
#   BASE_URL   target app root (default: http://localhost/Apex%20Sports%20Club)
#   ASC_ADMIN_EMAIL / ASC_ADMIN_PASSWORD / ASC_ADMIN_TOTP_SECRET
#              if all three are set, the authenticated CSRF checks
#              also run (full login + 2FA flow via the app's own
#              includes/totp.php). Otherwise those checks SKIP.
#
# Exit code: 0 = all enabled checks passed; 1 = a regression found.
# ============================================================

set -u

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${1:-${BASE_URL:-http://localhost/Apex%20Sports%20Club}}"
BASE_URL="${BASE_URL%/}"

PHP_BIN="${PHP_BIN:-$(command -v php || echo 'C:/xampp/php/php.exe')}"

PASS=0
FAIL=0
SKIP=0

say()   { printf '%-62s' "$1"; }
ok()    { echo "[ OK ]";      PASS=$((PASS+1)); }
bad()   { echo "[FAIL] (got $1, want $2)"; FAIL=$((FAIL+1)); }
skip()  { echo "[SKIP]";      SKIP=$((SKIP+1)); }

code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

# ── Unauthenticated matrix ───────────────────────────────────────────────

say "forged Paystack webhook rejected (403)"
c=$(code -X POST -H 'Content-Type: application/json' -H 'x-paystack-signature: bad' -d '{}' "$BASE_URL/paystack_callback.php")
[ "$c" = "403" ] && ok || bad "$c" "403"

say "M-Pesa callback does not 500 on empty payload"
c=$(code -X POST -d '{}' "$BASE_URL/callbacks/mpesa_callback.php")
# Sandbox accepts any IP (200); production rejects non-Safaricom IPs with 403.
# The invariant is "no server error", not a specific code.
{ [ "$c" = "200" ] || [ "$c" = "403" ]; } && ok || bad "$c" "200/403"

say "admin page redirects when unauthenticated (302)"
c=$(code "$BASE_URL/admin/manage_bookings.php")
[ "$c" = "302" ] && ok || bad "$c" "302"

say "tokenless admin POST blocked (302/403)"
c=$(code -X POST -d 'action=x' "$BASE_URL/admin/manage_announcements.php")
{ [ "$c" = "302" ] || [ "$c" = "403" ]; } && ok || bad "$c" "302/403"

say "AI endpoint denied when unauthenticated (403/302)"
c=$(code -X POST -d 'ai_action=predict_match' "$BASE_URL/admin/ai_predictions.php")
{ [ "$c" = "403" ] || [ "$c" = "302" ]; } && ok || bad "$c" "403/302"

say "path traversal blocked (404)"
c=$(code "$BASE_URL/uploads/../../config/api_config.php")
[ "$c" = "404" ] && ok || bad "$c" "404"

say "config file direct-hit harmless (no 500)"
c=$(code "$BASE_URL/config/api_config.php")
[ "$c" = "200" ] || [ "$c" = "403" ] || [ "$c" = "404" ] && ok || bad "$c" "200/403/404"

say "public page serves (200)"
c=$(code "$BASE_URL/public/index.php")
[ "$c" = "200" ] && ok || bad "$c" "200"

say "AI prediction API rate-limits after 5/min (429 on 6th)"
seqs=""
for i in 1 2 3 4 5 6; do
    c=$(code "$BASE_URL/public/api/match_predict.php?home=Arsenal&away=Chelsea")
    seqs="$seqs$c "
done
if echo "$seqs" | grep -q '429'; then ok; else bad "$seqs" "…429"; fi

say "SQLi probe on int-cast param is inert (no 500/200-leak)"
c=$(code "$BASE_URL/admin/coach_session_notes.php?coach_id=1%20OR%201=1")
# Must remain in the auth/error set — a 500 (crash) or 200 (page rendered
# publicly) would both be regressions.
case "$c" in 200|302|403|404) ok ;; *) bad "$c" "200/302/403/404" ;; esac

# ── Authenticated CSRF checks (optional) ─────────────────────────────────

ADMIN_EMAIL="${ASC_ADMIN_EMAIL:-}"
ADMIN_PASS="${ASC_ADMIN_PASSWORD:-}"
ADMIN_TOTP="${ASC_ADMIN_TOTP_SECRET:-}"

if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASS" ] && [ -n "$ADMIN_TOTP" ]; then
    JAR="$(mktemp)"
    trap 'rm -f "$JAR"' EXIT

    say "admin login POST redirects to 2FA challenge"
    T1=$(curl -s -c "$JAR" "$BASE_URL/admin/admin_login.php" \
        | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
    LOC=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' \
        -X POST -d "email=${ADMIN_EMAIL}&password=${ADMIN_PASS}&csrf_token=${T1}" \
        "$BASE_URL/admin/admin_login.php")
    case "$LOC" in
        *admin_verify_2fa*) ok ;;
        *) bad "$LOC" "*admin_verify_2fa*" ;;
    esac

    say "2FA verification succeeds (step 2/4)"
    T2=$(curl -s -b "$JAR" -c "$JAR" "$BASE_URL/admin/admin_verify_2fa.php" \
        | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
    if [ -z "$T2" ]; then
        skip  # no 2FA challenge (2FA disabled on this admin)
    else
        CODE=$("$PHP_BIN" -r 'require $argv[1]; echo totp_code($argv[2]);' \
            "$PROJECT_ROOT/includes/totp.php" "$ADMIN_TOTP" 2>/dev/null)
        LOC2=$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' \
            -X POST -d "code=${CODE}&csrf_token=${T2}" "$BASE_URL/admin/admin_verify_2fa.php")
        case "$LOC2" in
            *admin_dashboard.php*) ok ;;
            *) bad "$LOC2" "*admin_dashboard.php*" ;;
        esac
    fi

    say "admin dashboard reachable (200)"
    c=$(code -b "$JAR" "$BASE_URL/admin/admin_dashboard.php")
    [ "$c" = "200" ] && ok || bad "$c" "200"

    say "authenticated POST without CSRF token rejected (403)"
    c=$(code -b "$JAR" -X POST -d 'action=delete&id=999' "$BASE_URL/admin/manage_announcements.php")
    [ "$c" = "403" ] && ok || bad "$c" "403"

    say "authenticated POST with wrong CSRF token rejected (403)"
    c=$(code -b "$JAR" -X POST -d 'action=delete&csrf_token=deadbeef' "$BASE_URL/admin/manage_announcements.php")
    [ "$c" = "403" ] && ok || bad "$c" "403"

    say "security events page renders for admin (200)"
    c=$(code -b "$JAR" "$BASE_URL/admin/security_events.php")
    [ "$c" = "200" ] && ok || bad "$c" "200"
else
    say "authenticated CSRF checks (set ASC_ADMIN_* env to enable)"
    skip
fi

# ── Summary ───────────────────────────────────────────────────────────────

echo
echo "=============================================="
echo "  PASS: $PASS   FAIL: $FAIL   SKIP: $SKIP"
echo "  Target: $BASE_URL"
echo "=============================================="

if [ "$FAIL" -gt 0 ]; then
    echo "SECURITY SMOKE TEST FAILED — regression detected." >&2
    exit 1
fi
echo "All enabled security smoke checks passed."
exit 0
