# Threat Model — Payments & Membership Flows

**Owner:** Club admin / developer on call
**Scope:** Paystack checkout + webhook, M-Pesa STK push + callback, membership plans, auto-renewal, pause/resume, refunds, promo codes.
**Method:** STRIDE per trust boundary; updated whenever these flows change.
**Review cadence:** Re-run after every payment/membership code change; quarterly alongside pen-test/red-team.

Trust boundaries:
1. **Public web** (unauthenticated visitors) → app
2. **Member session** (logged-in members) → app
3. **Admin session** (staff, RBAC) → app
4. **Payment providers** (Paystack/M-Pesa servers) → callbacks
5. **App → providers** (STK push, verification API calls)
6. **Cron/CLI** (auto-renewal, reminders) → app internals

Legend: ✔ mitigated / ⚠ partial / ✖ open.

---

## Flow A — Paystack checkout + webhook

| STRIDE | Threat | Mitigation | Status |
|---|---|---|---|
| **S** | Attacker forges a "payment success" webhook to activate membership without paying | Webhook signature: `x-paystack-signature` verified as HMAC-SHA256 of raw body with `PAYSTACK_SECRET_KEY` before any data is trusted; mismatches rejected + logged | ✔ |
| **S** | Replay of a captured webhook | Provider reference recorded; idempotent upsert on `provider_reference` (unique index, migration 054) | ✔ |
| **T** | Attacker tampers with `amount`/`reference` params passed to checkout | Server re-verifies with provider verify API using server-side reference; amount compared against DB | ✔ (verify API) |
| **R** | Admin/member retrieves another user's payment record | Admin pages behind RBAC (`payments.view`/`payments.refund`); member views only own records (`member_id` scoping) | ✔ |
| **I** | Payment data (card/PAN) leaked in logs/DB | No PAN stored; provider-hosted checkout (tokenized); only reference/amount/status persisted | ✔ |
| **D** | Webhook floods / replay storms | Signature gate rejects forged traffic; rate-limit and alerting on callback errors is recommended (see open items) | ⚠ |
| **E** | Provider downtime blocks legitimate payments | Callback + checkout handled by provider; no local single point of failure | ✔ |

Open items:
- Callback endpoints lack per-IP rate limiting — a forged-flood only wastes CPU (sig check rejects), but adding a limiter + 5xx alert is cheap defense-in-depth.
- Consider `requiresConfirmation`/pending-status handling if Paystack returns `pending` — confirm what the code does with non-`success` statuses.

## Flow B — M-Pesa STK push + callback

| STRIDE | Threat | Mitigation | Status |
|---|---|---|---|
| **S** | Forged callback marks a payment as received without money moving | M-Pesa callback authenticates the merchant via `password` (base64 of shortcode+passkey+timestamp) — verify the implementation matches the provider's current spec and that the shortcode/passkey come from `.env` | ⚠ verify spec |
| **T** | Callback body tampered (amount, phone, reference) | Provider signs the payload; app must validate `TransactionType`, `Amount`, `Msisdn`, and reference against the pending payment record | ⚠ verify |
| **R** | Member reads another member's payment status | Member endpoints scope by `member_id` | ✔ |
| **I** | Customer phone/amount leaked in logs | `mpesa_log.txt` is gitignored; confirm it doesn't contain the full STK password or security credentials | ⚠ review log contents |
| **D** | STK push abuse — attacker triggers pushes to arbitrary phone numbers at scale | Each STK initiation must be auth-gated (member session) + rate-limited per member/IP | ⚠ |
| **E** | Safaricom timeout → payment confirmed later | Callback must be able to complete the payment regardless of which request initiated it; verify handling of the async result flow | ⚠ |

Open items:
- ~~Add per-member/IP rate limit on STK-push initiation~~ ✅ Done — `renew_membership.php` caps at 3 pushes / 5 min per member (`rate_limit_check('mpesa_push_m'.$member_id, 3, 300)`); callbacks also rate-limited per IP (120/min) as defense-in-depth behind the allow-list.
- Confirm M-Pesa `password` derivation + callback `SecurityCredential` handling against current Daraja docs (password = base64(shortcode+passkey+timestamp) matches the current spec; re-check on provider changes).
- Ensure callback rejects unknown/invalid `CheckoutRequestID` references (no blind accept).

## Flow C — Membership lifecycle (subscribe, renew, pause, auto-renew)

| STRIDE | Threat | Mitigation | Status |
|---|---|---|---|
| **S** | Forged "pause membership" request via cross-site request | `csrf_verify('member_csrf')` on `pause_membership.php` + `auto_renewal_settings.php`; SameSite=Lax cookies | ✔ |
| **S** | Unauthenticated user activates a membership for free | All membership actions require member session; payment callbacks verify provider signatures | ✔ |
| **T** | Member tampers with plan/price posted from the client | Server derives price from `membership_plans` (DB), not the posted amount; payment amount compared server-side | ✔ |
| **R** | Member pauses beyond allowed days / other member's membership | Owner check on `member_id`; `MAX_PAUSE_DAYS` cap enforced server-side | ✔ |
| **I** | Membership expiry computed wrong → free access | Status derived from `end_date` vs now in queries; expiry cron + membership gate (`membership_gate.php`) | ✔ |
| **D** | Auto-renewal cron loops/pays duplicate charges | Cron gated by payment idempotency (provider reference unique index); verify cron is single-instance (lockfile/`GET_LOCK`) | ⚠ |
| **E** | Payment provider fails at renewal time | Member keeps grace access; renewal retries on next cron pass; verify no double-charge on retry (idempotency) | ✔ |

Open items:
- ~~Ensure the auto-renewal cron uses a MySQL `GET_LOCK` or lockfile so overlapping runs can't double-charge~~ ✅ Done — `cron/cron_membership_renewal.php` acquires `GET_LOCK('apex_membership_renewal', 0)` (fails fast if another run holds it) and releases via `register_shutdown_function`; verified across two live connections.
- Confirm pause/renewal audit trail exists (activity log) for dispute resolution.

## Flow D — Refunds & promo codes

| STRIDE | Threat | Mitigation | Status |
|---|---|---|---|
| **S** | Non-admin triggers a refund | `manage_refunds.php` requires `payments.refund` permission + CSRF | ✔ |
| **T** | Refund amount/currency tampered | Server-side amount validation against the original payment; provider API for actual refund | ✔ |
| **R** | Refund another club's/staff member's payment | RBAC + payment ownership checks | ✔ |
| **I** | Promo code enumeration | `manage_promo_codes.php` admin-only; per-code usage caps; redemption attempts rate-limited per member (5 / 5 min) | ✅ |
| **D** | Promo brute-force (guess valid codes) | Codes are random; redemption endpoint rate-limited per member (5 / 5 min) | ✅ |

---

## Cross-cutting mitigations (already verified elsewhere)

- **AuthN**: bcrypt passwords, login rate limiting (5/15 min), 2FA for admins, constant-time CSRF tokens (CSPRNG, `hash_equals`).
- **AuthZ**: per-page RBAC enforced in `admin_header.php`; member data scoped by `member_id`.
- **Injection**: prepared statements throughout; interpolated IDs int-cast; `e()`/`esc()` output encoding.
- **Secrets**: provider keys only in `.env` (gitignored); CI gitleaks + Semgrep gates.
- **Do you need to handle** the `MPESA_CALLBACK_URL`/`PAYSTACK_CALLBACK_URL` being public endpoints behind TLS: always TLS in production (HSTS recommended).

---

## Open action list (tracked)

1. ~~Rate-limit M-Pesa STK-push initiation per member/IP~~ ✅ Done (3/5 min per member).
2. ~~Verify M-Pesa password/SecurityCredential derivation + callback validation against current Daraja docs~~ ✅ Done (round 3) — OAuth client_credentials, STK push endpoint, `base64(shortcode+passkey+timestamp)` password, callback ack without SecurityCredential, CheckoutRequestID lookup + amount match verified against current spec. Re-check on provider change.
3. ~~Add GET_LOCK/lockfile to auto-renewal cron to prevent double-charge on overlap~~ ✅ Done (MySQL GET_LOCK, verified).
4. ~~Rate-limit promo-code redemption attempts~~ ✅ Done (5/5 min per member).
5. ~~Add rate-limit + error-alert on Paystack/M-Pesa callback endpoints~~ ✅ Done (120/min per IP on both; HMAC/IP-allow-list remain the primary gate). Alerting on 429 spikes now handled by the security digest (round 3).
6. ~~Review `mpesa_log.txt` contents for credential leakage~~ ✅ Done — only transaction metadata (Amount, PhoneNumber, CheckoutRequestID, receipt); no keys/secrets; file is gitignored. Note: contains phone numbers (PII) — treat as sensitive.
7. ~~Add alerting on 401/403/429 spikes on payment routes (probe detection)~~ ✅ Done (rounds 3–4) — `security_events` table (migration 057) + `log_security_event()` at all choke points (rate_limit, csrf_reject, callback_reject, auth_lockout) + daily digest (`cron_security_alert.php`) AND real-time email on critical events / lockouts (`maybe_send_security_alert()`, 15-min atomic throttle, migration 058). Browse via `admin/security_events.php` (filters + top-IPs).
