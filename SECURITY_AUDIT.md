# Apex Sports Club — Security Risk Register

**Audit date:** August 4, 2026
**Method:** Automated pattern scans (SQLi, XSS, secrets, weak RNG, dangerous functions, CSRF coverage) + targeted manual review of auth, CRUD, AJAX, and public form handlers.
**Scope:** `admin/`, `public/`, `includes/`, `config/`, `callbacks/`.

Severity: **Critical / High / Medium / Low**. Status: **Fixed** / **Deferred** / **Accepted**.

---

## Summary

| Area | Result |
|---|---|
| Hardcoded secrets in source/JS/config | ✅ None found (all keys via `.env`, `config_value()`) |
| `eval`/`system`/`shell_exec`/`unserialize`/`extract` | ✅ None (only `curl_exec` for outbound APIs) |
| SQL injection (string-concatenated queries) | ✅ No exploitable cases — all interpolated IDs are `(int)`-cast or bound |
| Weak crypto for credentials | ✅ bcrypt + `password_verify`, constant-time `hash_equals` tokens |
| `mt_rand`/`rand` for security material | 🔧 2 fixed (access codes, referral codes); remaining uses are non-security (cache keys, ETags, game simulation) |
| CSRF coverage — admin | 🔧 Central enforcement added (was missing on **26 admin pages**) |
| CSRF coverage — public | ✅ All POST handlers covered (login/booking/update_profile/pause_membership/auto_renewal/referral + polls, volunteers, referrals, verify-membership, chatbot, tactics, AI summary) |
| AuthN/AuthZ gaps | 🔧 2 unauthenticated AI endpoints fixed |
| XSS | 🔧 4 reflected-echo fixes; `e()`/`esc()` (ENT_QUOTES) used everywhere else |

---

## Findings & status

### Critical
| # | Finding | Location | Status |
|---|---------|-----------|--------|
| C1 | **Unauthenticated AJAX endpoints** — `predict_match`/`quick_predict`/`test_connection` ran before `admin_header`, so no login check: anyone could trigger paid Gemini API calls and probe API-key presence. | `admin/ai_match_reports.php`, `admin/ai_predictions.php` | ✅ Fixed (auth + CSRF guard, 403 on failure) |

### High
| # | Finding | Location | Status |
|---|---------|-----------|--------|
| H1 | **No CSRF on admin POST endpoints** (26 pages): announcements, coach notes, event checklists, live scores, fixtures/leagues/standings, sports/coaches/equipment/facilities CRUD, polls, forum, gallery, sponsors, volunteers, tickets, injuries, expenses, loans, maintenance, season wizard, backup, etc. | `admin/*` (26 files) | ✅ Fixed centrally: `admin_header.php` rejects admin POSTs without a valid token; a client interceptor stamps all admin forms + `fetch()` calls; 4 legacy CRUD pages also got per-form tokens and POST-only deletes |
| H2 | **GET-based destructive actions** (`?action=delete&id=`) — trivially CSRF-able via `<img>`/link from any page | `admin/manage_coaches.php`, `manage_equipment.php`, `manage_facilities.php`, `manage_sports.php` | ✅ Fixed — converted to POST forms with CSRF tokens |
| H3 | **No CSRF on member state changes** — pause/resume membership, auto-renewal settings (incl. removing a saved Paystack card), referral invites | `public/pause_membership.php`, `public/auto_renewal_settings.php`, `public/referral.php` | ✅ Fixed |

### Medium
| # | Finding | Location | Status |
|---|---------|-----------|--------|
| M1 | Facility access codes generated with `mt_rand()` (predictable 6-digit codes) | `includes/smart_access_facility.php` | ✅ Fixed (`random_int`) |
| M2 | Referral codes built from `md5(uniqid(mt_rand()))` | `public/referral.php` | ✅ Fixed (`bin2hex(random_bytes(4))`, prepared uniqueness check) |
| M3 | Reflected XSS — raw `$_GET['id']`/`$_POST['id']` echoed into hidden inputs | `admin/manage_coaches.php`, `manage_equipment.php`, `manage_facilities.php`, `manage_sports.php` | ✅ Fixed (`(int)` casts + `e()`) |
| M4 | Reflected echo of member-entered booking date/time into `value` attributes (no escaping) | `public/booking.php` | ✅ Fixed (`htmlspecialchars`) |
| M5 | **No CSRF on public POST pages** — account/reward/engagement actions | `public/polls.php`, `volunteer_opportunities.php`, `verify_membership.php`, `referral_loyalty.php`, `tactics_card.php`, `ai_player_summary.php`, `chatbot.php` | ✅ Fixed — `csrf_field`/`csrf_verify('member_csrf')` on every handler (chatbot/tactics/AI-summary append the token to their fetch FormData; `verify_membership` uses `admin_csrf` for the admin gate check-in). `public/membership_reminders.php` is an admin page (includes `admin_header.php`) and is covered by central enforcement |
| M7 | **Regression from H1 fix** — `admin/ai_match_reports.php` + `ai_predictions.php` `testAPI()` posted a raw string body (`'ai_action=test_connection'`), which the client interceptor can't stamp (only FormData/URLSearchParams), so the new page-level CSRF guard 403'd it | `admin/ai_match_reports.php`, `admin/ai_predictions.php` | ✅ Fixed — `testAPI()` now sends `new URLSearchParams({ ai_action: 'test_connection' })`, which the interceptor stamps with `admin_csrf` |
| M6 | Login CSRF (can't hijack a session, but attacker can pre-load a victim's session with the attacker's account) | `public/login.php` | ✅ Fixed |
| M8 | **"Looks-locked" rate limit** — `rate_limit_check()` was *called* by `public/api/match_predict.php` inside `function_exists()` but **never defined anywhere**, so the AI prediction endpoint silently had no rate limit (free Gemini quota burn) | `includes/rate_limiter.php`, `public/api/match_predict.php` | ✅ Fixed — implemented `rate_limit_check()` (per-key sliding window on `login_attempts`, fails open on DB error) + `client_rate_key()` (trusts `X-Forwarded-For` only when `ASC_TRUST_PROXY=1`, else `REMOTE_ADDR` — no spoofable bypass); rate check moved after param validation so junk requests don't burn budget; verified live: 5 valid then 429 |
| M9 | **No rate limit on AI-quota endpoints** — chatbot, tactics card, AI player summary, AI booking suggestions, and the external API-Sports proxy all spend paid quota with no per-client cap | `public/chatbot.php`, `tactics_card.php`, `ai_player_summary.php`, `ai_booking_suggestions.php`, `get_sport_data.php` | ✅ Fixed — wired `rate_limit_check(client_rate_key(...))` into each (10–30/min per client) |
| M10 | **Client-supplied file extension on profile photo upload** — MIME was sniffed via `finfo` and the 2 MB cap enforced, but the stored extension came from `pathinfo($_FILES[...]['name'])`, so an image named `x.php` would be stored with a `.php` extension (not executable: `uploads/.htaccess` denies PHP — defense-in-depth only) | `public/edit_profile.php` | ✅ Fixed — extension now derived from the verified MIME (`jpg/png/gif/webp`); random-ish `member_ID_timestamp.ext` names retained |
| L5 | Reviewer residuals — admin forms using programmatic `.submit()` bypass the CSRF interceptor's `submit` event | `admin/manage_bookings.php`, `manage_members.php`, `manage_refunds.php`, `payments_overview.php` | ✅ Verified safe — every such form embeds its own `csrf_field('admin_csrf')`; `payments_overview` selects are GET filters (no CSRF needed). No `sendBeacon` or JSON-string `fetch` bodies exist in admin |
| L6 | **Rate-limiter cross-contamination** — `check_login_attempts()` counted *all* `login_attempts` rows for an IP, so the new `action_type='api'` rows (or registration/reset rows) could lock out a legitimate login from the same IP | `includes/rate_limiter.php` | ✅ Fixed — login check now filters `action_type = 'login'` |
| L7 | **Broken callback paths (pre-existing, exposed by smoke test)** — `callbacks/mpesa_callback.php` + `callbacks/paystack_callback.php` used relative/CWD-based requires (`config/db_connect.php`), 500ing under Apache from the `callbacks/` subdir; the sibling root webhook (`paystack_callback.php`) already used `__DIR__` | `callbacks/*.php` | ✅ Fixed — all requires anchored to `__DIR__ . '/../…'`; both endpoints now 200 with correct behavior (root webhook still 403s forged signatures) |

### Low / Accepted
| # | Finding | Status |
|---|---------|--------|
| L1 | `md5()` used for cache keys, ETags, rate-limit keys, filename suffixes (not security material) | ✅ Accepted |
| L2 | `rand()` in match-score simulation / prediction fallback and rate-limit sampling | ✅ Accepted (non-security) |
| L3 | Health check reports `degraded` locally because XAMPP PHP has `gd` disabled (env config, not code) | ⏳ Env fix outside repo |
| L4 | `bin/setup.sh` still requires `mysql`/`php` on PATH (Windows XAMPP users must add them) | ⏳ Pre-existing DX issue |

---

## Hardening controls confirmed present

- Sessions: `httponly`, `SameSite=Lax`, `secure` on HTTPS (`includes/session_config.php`).
- CSRF tokens: 64-hex CSPRNG, constant-time comparison, per-form keys (`includes/csrf.php`).
- Passwords: bcrypt via `password_hash`/`password_verify` (member + admin).
- SQL: prepared statements with bound params throughout; all `query()` interpolations int-cast.
- Output: `esc()`/`e()` = `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` in views.
- Input: centralized `sanitize_*` + `post()`/`get()` helpers (`includes/input_sanitize.php`).
- Rate limiting: login/register/password-reset/2FA + API endpoints (`includes/rate_limiter.php`, `login_attempts`).
- RBAC: per-page permission enforcement in `admin_header.php` (`roles.php`).
- Secrets: `.env` only, gitignored; CI greps for live keys.
- Payment callbacks: webhook signature verification + idempotent upserts (PRR #1, resolved).

---

## Completed follow-ups (stage 5 hardening)

- **File uploads audited** — the shared `image_processor.php`/`object_storage.php` path is fully hardened (MIME allow-list, server-side re-encode, random names, `uploads/.htaccess` blocks script execution); the one raw consumer (`edit_profile.php`) had its extension derivation fixed (M10).
- **DAST-style review** — `live_scores.php`/`standings.php` are read-only cached public data (OK); the AI/API endpoints now have per-client rate limits (M8, M9); `get_sport_data.php` is SSRF-safe (endpoints are server-side allow-listed, never user-supplied URLs).
- **CI security gates added** — new `security-scan` job in `.github/workflows/ci.yml`: gitleaks secret scan, Semgrep SAST, and a dangerous-patterns regression gate (`eval`/`system`/`shell_exec`/`exec`/`assert`/`unserialize`, live keys, weak RNG for tokens).
- **STRIDE threat model written** — `THREAT_MODEL.md` covers Paystack checkout + webhook, M-Pesa STK + callback, membership lifecycle (subscribe/renew/pause/auto-renew), refunds + promo codes, with mitigation status and an open-action list.

## Stage-5 round 2 (STRIDE actions + pen-test) — completed

- **STK push rate limit** — `public/renew_membership.php`: 3 pushes / 5 min per member.
- **Callback rate limits** — `callbacks/mpesa_callback.php` + `paystack_callback.php`: 120/min per IP (defense-in-depth behind the existing HMAC signature check and Safaricom IP allow-list).
- **Auto-renewal single-instance guard** — `cron/cron_membership_renewal.php` now takes MySQL `GET_LOCK('apex_membership_renewal', 0)` (fails fast on overlap, released via shutdown handler); verified across two live connections (A=1, B=0 while held, C=1 after).
- **Promo-code brute-force limit** — `public/payments.php`: 5 redemption attempts / 5 min per member.
- **`mpesa_log.txt` reviewed** — only transaction metadata (amounts, checkout IDs, receipts, phone numbers); no credentials; gitignored. Treat as PII-bearing.
- **Pen-test pass (authenticated)** — full admin login + 2FA flow exercised: tokenless POST → 403, wrong token → 403, valid token → passes; GET-based deletes inert (coach count unchanged); SQLi probes return normal pages (int-cast + prepared statements hold).
- **Found + fixed during testing (L6, L7)** — login lockout cross-contamination with the new API rate-limit rows; broken relative requires in `callbacks/*.php` (500 under Apache).
- **Review refinements applied** — Paystack callback rate limit moved *after* the HMAC signature check (forged floods now rejected with zero DB writes); STK push limit relaxed to 5/10 min per member for typo retries.

## Stage-5 round 3 (security alerting + dependency scan + Daraja review) — completed

- **Security-events pipeline built** — migration `057_security_events.sql` adds the `security_events` table (event_type, severity, ip, actor, details); `includes/security_events.php` exposes `log_security_event()` (never throws; degrades to `error_log()` if the table/DB is unavailable); `cron_security_alert.php` emails a daily digest (disabled until `ASC_SECURITY_EMAIL_TO` is set in `.env`, `ASC_SECURITY_MIN_CRITICAL` threshold, ~30-day retention).
- **Choke points wired** — `includes/rate_limiter.php` logs `auth_lockout` (login lockout) and `rate_limit` (endpoint 429s); `includes/csrf.php` logs `csrf_reject` (throttled to 1/min per session+key so stale back-button posts don't flood the digest); `callbacks/mpesa_callback.php` logs `callback_reject` critical on IP-allow-list failure; `paystack_callback.php` logs `callback_reject` critical on HMAC signature mismatch (before the rate limiter, so forged floods are rejected with zero DB writes).
- **Live-verified** — forged Paystack webhook → 403 + critical `callback_reject` row; tokenless login POST → `csrf_reject` row; 6th `match_predict` request → 429 + `rate_limit` row; digest cron ran and mailed the events. PHPUnit 11/11.
- **Flood-bounded telemetry (review fix)** — `log_security_event_throttled()` in `security_events.php` inserts at most one row per (event type + actor) per minute, so an attacker flooding an endpoint cannot turn the telemetry into a DB-write amplifier (the 429/HMAC path itself stays DB-cheap). Verified live: 15 forged webhooks → exactly 1 new `callback_reject` row. The CSRF reject throttle was moved from session-based (defeatable by sessionless bots) to this same DB-backed dedup. Digest cron now exits non-zero and logs when the email send fails.
- **Dependency scan** — generated `composer.lock` (26 packages, all dev), `composer audit` clean (0 advisories), `vendor/` gitignored, lockfile ready to commit. Added `.github/dependabot.yml` (weekly composer + github-actions) and a `composer audit` + CycloneDX SBOM step to the CI `security-scan` job.
- **M-Pesa Daraja spec review** — implementation verified against current docs: OAuth `grant_type=client_credentials` with Basic auth; STK push `POST /mpesa/stkpush/v1/processrequest` with Bearer token; password = `base64(shortcode + passkey + timestamp YmdHis)`; callback ack is plain `ResultCode`/`ResultDesc` (no `SecurityCredential` in the response body); CheckoutRequestID validated by DB lookup + amount match + Pending status. No changes needed.

## Stage-5 round 4 (real-time alerts + security admin page) — completed

- **Real-time critical alerting** — `maybe_send_security_alert()` in `includes/security_events.php` fires an immediate email when a `critical` event (or `auth_lockout`) is logged, once per alert type per 15 min. Atomic throttle via `INSERT … SELECT … WHERE NOT EXISTS` (migration `058_security_alerts.sql`), throttle row claimed BEFORE the network call so a send failure still counts against the window; guarded on `BREVO_API_KEY` so an unconfigured env never burns throttle slots. `sendEmail()` now sets `CURLOPT_TIMEOUT=15`/`CONNECTTIMEOUT=8` so a slow Brevo cannot stall the webhook-rejection path. Verified: 3 concurrent critical events → exactly 1 alert row + 1 email attempt.
- **Admin security page** — `admin/security_events.php`: filters (type/severity/IP/date), paginated event table (50/page), 24h summary cards per event type, top-offending-IPs table (7 days). Prepared statements throughout; all output `htmlspecialchars`-escaped; permission `logs.view` + nav dropdown item + page-title entry added to `includes/admin_header.php`. Live-verified with a real admin session (200, renders live events).

## Stage-5 round 5 (smoke harness + deterministic retention + git identity) — completed

- **Security smoke harness** — `scripts/security_smoke.sh` replays the full live check matrix (forged webhook 403, M-Pesa no-500, unauthenticated 302/403 gates, traversal 404, config direct-hit harmless, rate-limit 429 within 6 calls, SQLi probe inert) plus an optional authenticated pass (full login + 2FA via the app's own `includes/totp.php`, CSRF no-token/wrong-token 403, admin pages 200). Prints PASS/FAIL/SKIP and **exits 1 on any regression**. CI/staging-ready: `BASE_URL`, `ASC_ADMIN_EMAIL`/`ASC_ADMIN_PASSWORD`/`ASC_ADMIN_TOTP_SECRET` env-configurable; M-Pesa check accepts 200/403 so production allow-list behavior doesn't false-positive. Verified: 16/16 pass authenticated, fails correctly (exit 1) against a bad target.
- **Deterministic retention** — `cron_security_alert.php` now runs a weekly (Sunday) purge of `security_events` + `security_alert_log` honoring `ASC_SECURITY_RETENTION_DAYS` (default 30, min 7), moved **before** the digest-disabled early exit so the table can never grow unbounded when alerting is off. Documented in `.env.example`.
- **Git identity** — repo-local `user.name`/`user.email` set so future commits don't need inline identity.

## Stage-5 round 6 (CI staging smoke + acknowledge workflow + repo clean) — completed

- **CI staging security-smoke job** — new `security-smoke-staging` job in `.github/workflows/ci.yml` that runs `scripts/security_smoke.sh` against `SMOKE_BASE_URL` (settable via GitHub `vars` or `secrets`), gated to skip when unconfigured, with `ASC_ADMIN_*` secrets passed for the authenticated checks. CI YAML validated.
- **Acknowledge/notes workflow** — migration `059_security_events_ack.sql` adds `acknowledged`, `acknowledged_by`, `acknowledged_at`, `notes` columns; `includes/security_events.php` exposes `acknowledge_security_event()` (bound param UPDATE, returns ok/error, never throws); `admin/security_events.php` has a POST handler with Bootstrap modal + notes textarea, Status filter dropdown, and green badge display. `cron_security_alert.php` adds `AND acknowledged = 0` so acknowledged events drop from the daily digest (verified: 16→15 after acknowledging one event).
- **Review fixes applied** — modal form now outputs `csrf_field('admin_csrf')` so the acknowledge POST works without JS (browsers with JS skip the duplication via the interceptor); the error message reads "already acknowledged" instead of "not found or already acknowledged"; `acknowledged_by` uses `$_SESSION['admin_email']` for immediate readability.
- **Repo cleaned** — all remaining pre-existing work committed (317 files), local tooling/scratch dirs gitignored (`.freebuff/`, `.agent/`, `.cache/`, `.opencode/`, `dev/`, `node_modules/`, `backups/`, `screenshots/`, `output/`, `tmp/`, `legacy_sql/`), working tree clean.

## Remaining recommended next steps

1. **Pen-test / red-team pass** on the live app (quarterly), tracking findings to closure in this register.
