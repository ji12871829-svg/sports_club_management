# Apex Sports Club — System Design & Quality Audit (v2.0 Re-Run)

**Date:** August 6, 2026 (blocker-closure update on top of the Aug 5 v2.0 re-run)
**Scope:** Full codebase re-audit against the v2.0 Master Evaluation prompt, following the payment/security/perf rounds merged through `0cbbb8c`.
**Baseline:** Last full audit (v1.0) produced `PRR.md` (PASS-WITH-RISKS), `THREAT_MODEL.md`, `SECURITY_AUDIT.md`.
**Method:** Static review of the live repo (223 PHP files, 61 migrations), CI workflows, existing tests, and the audit documents; dynamic verification of key checks via `public/health.php` and the admin panel.

---

## 1. Executive Summary

| Dimension | Verdict |
|---|---|
| Architecture | ✅ Cohesive vanilla PHP monolith; no new anti-patterns introduced |
| Security | ✅ Strong (see §3) — all STRIDE open actions closed |
| Payments | ✅ Webhook-signed, idempotent, monitored (see §4) |
| Data integrity | ✅ ACID payments, unique provider references, migration chain verified |
| Performance | ✅ p95 132–288 ms @ 50 concurrent (`output/load_test/report_20260806_230148.md`) |
| Testing | ✅ 32 PHPUnit tests + smoke harness + 8-job CI (incl. feature-flagged deploy) |
| Compliance | ⚠️ DPA addressed; PCI not formally attested |
| **PRR sign-off** | ⚠️ **Close** — all 4 operational blockers closed; CI-green-7-runs + lead sign-off remain (see §9) |

**One-line verdict:** Production-hardening is effectively complete; what blocks sign-off is **operational evidence** (backup restore, load tests, env parity, staged deploy), not missing code.

---

## 2. System Overview (as audited)

- Vanilla PHP 8.2 + MySQL/MariaDB (XAMPP), Bootstrap 5, no framework, no composer runtime deps.
- Two checkouts kept in sync: Apache-served main + Freebuff worktree (`sync_check.sh`).
- Payments: Paystack (checkout + HMAC webhook) and M-Pesa Daraja (STK push + callback).
- AI features (booking review, predictions, churn analytics) via Gemini/OpenRouter with settings-table key management.
- 61 sequential SQL migrations; `scripts/migrate.php` runner; migration dry-run in CI.
- CI: 6 jobs (lint, security-scan [gitleaks + Semgrep + composer audit + SBOM], PHPUnit, payment-config gate, migration dry-run, smoke + conditional staging security smoke).

---

## 3. Security (STRIDE re-verification)

All previously-open THREAT_MODEL actions are **closed** and code-verified this round:

| Area | Evidence |
|---|---|
| Webhook forgery | Paystack `x-paystack-signature` HMAC-SHA256 via `paystack_verify_signature()` (`includes/paystack.php`), unit-tested (7 cases); M-Pesa callback authenticates merchant + validates CheckoutRequestID/amount |
| Replay/idempotency | Unique `provider_reference` (migration 061), upsert on callbacks; duplicate activation idempotent |
| CSRF | Central enforcement in `admin_header.php`; per-form tokens; constant-time `hash_equals` |
| AuthN | bcrypt, login/register/2FA rate limits, TOTP 2FA for admins, lockout + real-time alert |
| Rate limiting | STK push 3/5min per member; callbacks 120/min per IP; promo 5/5min; API 5/min |
| Telemetry | `security_events` (partitioned, ack workflow), daily digest + real-time critical alerts, `admin/security_events.php`, `cron_security_alert.php` |
| Supply chain | gitleaks, Semgrep, `composer audit --locked`, CycloneDX SBOM in CI |
| Input handling | Prepared statements, int-cast IDs, `e()` output encoding; smoke harness probes SQLi/traversal/forged webhook live |

*Verified live:* forged webhook → 403, valid → 200, browser GET → 200; path traversal blocked 404 on Apache.

---

## 4. Payments & Membership Integrity

- **Paystack:** signature-gated webhook; server-side verify API; amount compared server-side; payment recorded once (`provider_reference` unique).
- **M-Pesa:** STK push fail-fast on non-`https://` callback (`mpesa_callback_url_error()`), OAuth token flow, timeout-hardened curl, pending-row lifecycle (Pending → Completed), callback ack, membership activation idempotent.
- **Config guardrails:** `public/health.php` `payment_config` check, `cron_payment_health.php` (24h-throttled email), admin dashboard **Payment Health** panel (new), CI payment-config gate.
- **Data-quality guardrail:** `find_duplicate_memberships()` (shared helper) flags overlapping Active memberships on the same plan — dashboard badge + members-page banner; 6 new unit tests.
- **Operations:** `ApexPaymentHealth` + `ApexProfilerSlowDigest` Windows scheduled tasks registered (daily 06:00); `schedule_alert_cron.php` now creates tasks via `proc_open` argv array (fixed cmd quoting for paths with spaces).

---

## 5. Performance & Scalability

| Check | Status |
|---|---|
| Dashboard stats | Single consolidated query (was 7 COUNTs) |
| Caching | In-memory `AscCache`; OPcache warmup script |
| Profiling | `page_timings` + `admin/slow_pages.php` + daily digest cron |
| Indexes | Migration 055 covers WHERE/JOIN/ORDER BY; unique index 061 |
| Assets | `asc_asset()` filemtime cache-busting; HTTP/2 config in prod compose |
| Concurrency | Booking `SELECT ... FOR UPDATE`; renewal cron `GET_LOCK` |
| **Load evidence** | ✅ **Captured** — `bin/load_test.sh` (ApacheBench): homepage p95 176 ms, login 288 ms, registration 132 ms @ 50 concurrent, 0 failed; report in `output/load_test/` (git-ignored) |

---

## 6. Testing & CI

- **PHPUnit: 32 tests / 56 assertions**, suites: PaymentIdempotency, Security, MpesaCallbackUrl, MpesaCallbackCycle, PaystackSignature, DuplicateMembership (1 environment-skip: NULL dates unreachable under NOT NULL schema).
- **Smoke harness** `scripts/security_smoke.sh`: 10 probes; 1 environment-only artifact (path traversal returns 200 under `php -S` but 404 under Apache — gate passes in production).
- **CI (GitHub Actions):** lint → security scan (gitleaks/Semgrep/composer audit/SBOM) → tests (MariaDB 10.11) → payment-config gate → migration dry-run → smoke; optional staging security-smoke behind `SMOKE_BASE_URL`.
- **Migration chain:** 61/61 apply clean on a fresh DB (verified in earlier rounds; dry-run gate in CI).

---

## 7. Observability, Compliance, Operations

- Observability: ⚠️ request profiler + health endpoints + security events, but no structured logs/tracing/metrics stack.
- Compliance: Kenya DPA addressed (privacy policy, consent, deletion flow); no card data stored (PCI scope minimal); field-level encryption of PII ❌ not implemented.
- Operations: backup/restore ✅ scripted (`bin/backup.sh`/`bin/restore.sh`) and drill-verified (120 tables, exact row parity); env parity ✅ `APP_ENV` + `.env.{env}` overlays; staged deploy ✅ feature-flagged CI job; single-server no failover; no SLOs/error budgets.

---

## 8. What Changed Since the Last Audit (Delta)

| Area | Change |
|---|---|
| Security | security_events ack workflow + partitioning; real-time critical alerts; callback rate limits; composer.lock + audit + SBOM |
| Payments | Paystack signature extraction + 7 tests; M-Pesa cycle tests; payment_config health check + cron + dashboard panel; duplicate-membership detector + shared helper + 6 tests |
| Ops | payment + profiler scheduled tasks; scheduler fixed (proc_open); health endpoint expanded |
| Tests | 26 → 32 tests (was 11 at PRR) |

---

## 9. Remaining Blockers Before PRR Sign-Off

The code is in good shape; these are the **documented PRR sign-off criteria still unmet** (all operational/verification items — no critical code gaps found):

| # | Blocker | Status | Evidence |
|---|---|---|---|
| 1 | **No backup restore verification** | ✅ **Closed** | `bin/backup.sh` + `bin/restore.sh`; live drill: 120 tables backed up, restored into scratch DB, exact row-count parity (members 682, payments 8, bookings 8, plans 5) |
| 2 | **No load-test evidence** | ✅ **Closed** | `bin/load_test.sh` (ApacheBench @ 50 concurrent): homepage p95 176 ms, login 288 ms, registration 132 ms, 0 failed → `output/load_test/report_20260806_230148.md` |
| 3 | **No env parity (dev/staging/prod)** | ✅ **Closed** | `APP_ENV` (development/staging/production) + `.env.{env}` overlay loader in `config/api_config.php` (overrides win over base `.env`); committed `.env.example`; secrets stay in git-ignored `.env` |
| 4 | **No staged CI/CD to a live host** | ✅ **Closed (feature-flagged)** | `deploy` job in `.github/workflows/ci.yml`: rsync + migration run + optional health check on main, gated on `DEPLOY_HOST`/`DEPLOY_SSH_KEY` secrets (no-op until configured); `.env` never overwritten |
| 5 | *CI green 7 consecutive runs* | ⏳ Operational | — pending repo activity |
| 6 | *PRR sign-off by lead developer* | ⏳ Human step | — |

**Recommended order:** 1 → 2 → 3 → 4 (cheapest evidence first).

---

## 10. Prioritized Action List

1. ✅ **P0 — backup script + one restore drill** — `bin/backup.sh`/`bin/restore.sh`, drill recorded (Aug 6).
2. ✅ **P1 — load test** — `bin/load_test.sh`, p95 132–288 ms @ 50 concurrent; report in `output/load_test/`.
3. ✅ **P1 — APP_ENV parity** — `APP_ENV` + `.env.{env}` overlays + `.env.example`.
4. ✅ **P2 — staged deploy job** — feature-flagged `deploy` job in CI.
5. **P2 — field-level encryption** for PII columns if the club stores sensitive member data beyond what's necessary (data-minimization currently mitigates).
6. **P3 — structured logging** (JSON error handler output) for easier alerting/tracing.

---

*Re-audit complete. Next re-run after any architectural or payment-system change (per THREAT_MODEL review cadence).*
