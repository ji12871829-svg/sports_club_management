#!/usr/bin/env bash
# ============================================================
#  bin/load_test.sh
#  PRR sign-off criterion: "Load test completed: p95 < 2 s at
#  50 concurrent users" (AUDIT_REPORT.md blocker #2).
#
#  Runs ApacheBench (ab) against the key public endpoints of a
#  deployment and writes a markdown report + raw ab logs into
#  output/load_test/. Exits non-zero when any endpoint's p95
#  exceeds the 2-second target.
#
#  Usage:
#    bin/load_test.sh [BASE_URL] [CONCURRENCY] [REQUESTS]
#    bin/load_test.sh http://localhost/Apex%20Sports%20Club/public/ 50 400
#
#  Notes:
#    * Locates `ab` on PATH or common XAMPP/LAMPP locations.
#    * output/ is git-ignored, so reports stay local by design;
#      reference output/load_test/ in docs as the evidence path.
# ============================================================
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${ROOT}/output/load_test"
mkdir -p "$OUT_DIR"

BASE_URL="${1:-http://localhost/Apex%20Sports%20Club/public/}"
BASE_URL="${BASE_URL%/}"
CONCURRENCY="${2:-50}"
REQUESTS="${3:-400}"
P95_TARGET_MS=2000

# Endpoints exercised: homepage, an auth form, a heavier listing page.
ENDPOINTS=(index.php login.php register.php)
ENDPOINT_LABELS=(Homepage "Login page" "Registration page")

find_ab() {
  if command -v ab >/dev/null 2>&1; then
    command -v ab
    return
  fi
  for p in /c/xampp/apache/bin/ab.exe /opt/lampp/bin/ab /usr/bin/ab /usr/local/bin/ab /usr/bin/ab2; do
    if [ -x "$p" ]; then
      echo "$p"
      return
    fi
  done
  echo ""
}

AB="$(find_ab)"
if [ -z "$AB" ]; then
  echo "ERROR: ApacheBench (ab) not found. Install httpd-tools or use the XAMPP build." >&2
  exit 1
fi

TS="$(date +%Y%m%d_%H%M%S)"
REPORT="${OUT_DIR}/report_${TS}.md"

echo "Apex Sports Club — load test (${TS})"
echo "  Target base URL : ${BASE_URL}"
echo "  Concurrency     : ${CONCURRENCY}"
echo "  Requests        : ${REQUESTS} per endpoint"
echo "  Pass criterion  : p95 < ${P95_TARGET_MS} ms"
echo "  Report          : ${REPORT}"

# ── header ────────────────────────────────────────────────────
{
  echo "# Apex Sports Club — Load Test Report"
  echo ""
  echo "**Date:** $(date '+%B %d, %Y %H:%M')  "
  echo "**Tool:** ApacheBench (\`$(basename "$AB")\`)  "
  echo "**Base URL:** \`${BASE_URL}\`  "
  echo "**Concurrency:** ${CONCURRENCY}  "
  echo "**Requests per endpoint:** ${REQUESTS}  "
  echo "**Pass criterion:** p95 < ${P95_TARGET_MS} ms  "
  echo ""
  echo "| Endpoint | Requests/s | p50 (ms) | p95 (ms) | p99 (ms) | Failed | Verdict |"
  echo "|---|---|---|---|---|---|---|"
} > "$REPORT"

overall_pass=1

i=0
for ep in "${ENDPOINTS[@]}"; do
  label="${ENDPOINT_LABELS[$i]}"
  url="${BASE_URL}/${ep}"
  raw="${OUT_DIR}/ab_${ep%.php}_${TS}.log"

  echo ""
  echo "── ${label} (${url}) ──"
  # -k keep-alive, -s hard timeout per request (avoid hangs)
  "$AB" -n "$REQUESTS" -c "$CONCURRENCY" -k -s 30 "$url" > "$raw" 2>&1 || true

  # Parse metrics from the raw ab output
  rps=$(grep -E '^Requests per second' "$raw" | awk '{print $4}')
  failed=$(grep -E '^Failed requests' "$raw" | awk '{print $3}' | tr -d '(')
  p50=$(awk '/^ *50%/{print $2}' "$raw")
  p95=$(awk '/^ *95%/{print $2}' "$raw")
  p99=$(awk '/^ *99%/{print $2}' "$raw")

  if [ -z "$p95" ]; then
    echo "  ✗ ab produced no p95 (see $raw) — treating as FAIL"
    verdict="FAIL"
    overall_pass=0
  elif [ "$p95" -lt "$P95_TARGET_MS" ]; then
    echo "  ✓ p95 = ${p95} ms (< ${P95_TARGET_MS} ms)"
    verdict="PASS"
  else
    echo "  ✗ p95 = ${p95} ms (≥ ${P95_TARGET_MS} ms)"
    verdict="FAIL"
    overall_pass=0
  fi

  printf '| %s | %s | %s | %s | %s | %s | **%s** |\n' \
    "$label" "${rps:-n/a}" "${p50:-n/a}" "${p95:-n/a}" "${p99:-n/a}" "${failed:-n/a}" "$verdict" >> "$REPORT"
  i=$((i + 1))
done

echo "" >> "$REPORT"
if [ "$overall_pass" -eq 1 ]; then
  echo "**Overall verdict:** ✅ PASS — all endpoints served p95 < ${P95_TARGET_MS} ms at ${CONCURRENCY} concurrent users." >> "$REPORT"
else
  echo "**Overall verdict:** ❌ FAIL — at least one endpoint exceeded p95 ≥ ${P95_TARGET_MS} ms." >> "$REPORT"
fi
echo "" >> "$REPORT"
echo "Raw logs: \`ab_*.log\` in this directory." >> "$REPORT"

echo ""
echo "Report written to ${REPORT}"
echo "Raw logs in ${OUT_DIR}/"
[ "$overall_pass" -eq 1 ] || exit 1
