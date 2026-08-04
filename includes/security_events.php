<?php
/**
 * includes/security_events.php
 * Structured security-event logging for alerting / probe detection.
 *
 * Call sites (defense-in-depth choke points):
 *   - rate-limit hits (rate_limit_check → 429)
 *   - CSRF token rejections (admin_header.php central enforcement)
 *   - payment webhook/callback failures (Paystack sig mismatch, M-Pesa IP deny)
 *   - auth lockouts (login/2FA/register/reset rate limits)
 *
 * The table may not exist on an old DB that hasn't run migration 057 yet —
 * log_security_event() degrades to error_log() in that case so the page is
 * never broken by telemetry. Writes are best-effort and never throw.
 */

if (!function_exists('log_security_event')) {

    /** @var bool|null Cached "does the security_events table exist?" */
    $GLOBALS['__asc_secevents_ready'] = null;

    /**
     * Record a security event. Never throws; degrades to error_log() when the
     * table is missing or the DB is unavailable.
     *
     * @param string $eventType e.g. 'rate_limit', 'csrf_reject', 'callback_reject', 'auth_lockout'
     * @param string $severity  'info' | 'warning' | 'critical'
     * @param string $details   Short human-readable context (kept < 500 chars).
     * @param string|null $actor  Optional identifier (email, member/admin id, bucket key).
     */
    function log_security_event(string $eventType, string $severity = 'warning', string $details = '', ?string $actor = null): void
    {
        try {
            if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
                error_log('[security_event] ' . $eventType . ': ' . $details);
                return;
            }
            $conn = $GLOBALS['conn'];

            // One-time schema existence check (cached per request).
            if ($GLOBALS['__asc_secevents_ready'] === null) {
                $res = $conn->query("SHOW TABLES LIKE 'security_events'");
                $GLOBALS['__asc_secevents_ready'] = $res && $res->num_rows > 0;
                if ($res) { $res->free(); }
            }
            if (!$GLOBALS['__asc_secevents_ready']) {
                error_log('[security_event] ' . $eventType . ': ' . $details);
                return;
            }

            $severity = in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'warning';
            $details  = mb_substr((string) $details, 0, 500, 'UTF-8');
            $ip       = $_SERVER['REMOTE_ADDR'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
            if (is_string($ip)) { $ip = mb_substr($ip, 0, 45); } else { $ip = ''; }

            $stmt = $conn->prepare(
                "INSERT INTO security_events (event_type, severity, ip_address, actor, details)
                 VALUES (?, ?, ?, ?, ?)"
            );
            if ($stmt) {
                $stmt->bind_param('sssss', $eventType, $severity, $ip, $actor, $details);
                $stmt->execute();
                $stmt->close();

                // Real-time alert: critical events (and login lockouts) get an
                // immediate email, throttled per alert type so a flood cannot
                // produce an email storm.
                if ($severity === 'critical' || $eventType === 'auth_lockout') {
                    maybe_send_security_alert($eventType, $details);
                }
            }
        } catch (\Throwable $e) {
            // Telemetry must never break the request.
            error_log('[security_event] ' . $eventType . ': ' . $details);
        }
    }

    /**
     * Send an immediate alert email for a critical security event, throttled
     * to at most one email per alert type per 15 minutes (security_alert_log
     * table, migration 058). Disabled unless ASC_SECURITY_EMAIL_TO is set.
     * Never throws.
     */
    function maybe_send_security_alert(string $eventType, string $details): void
    {
        try {
            $to = getenv('ASC_SECURITY_EMAIL_TO');
            if ($to === false || trim($to) === '') {
                return; // alerting not configured
            }
            if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
                return;
            }
            $conn = $GLOBALS['conn'];

            // Never burn a throttle slot (or a network call) when email is not
            // configured — an empty key makes sendEmail fail every time.
            if (!defined('BREVO_API_KEY') || trim(BREVO_API_KEY) === '') {
                return;
            }

            // Atomic throttle: INSERT ... WHERE NOT EXISTS both checks and
            // claims the slot in one statement, so concurrent critical events
            // cannot both pass a separate SELECT and double-email. The row is
            // written BEFORE the network call so a send failure still counts
            // against the window (no alert storm on a flaky Brevo).
            $log = $conn->prepare(
                "INSERT INTO security_alert_log (alert_type)
                 SELECT ? FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM security_alert_log
                     WHERE alert_type = ? AND sent_at > NOW() - INTERVAL 15 MINUTE
                 )"
            );
            if (!$log) {
                return;
            }
            $log->bind_param('ss', $eventType, $eventType);
            $log->execute();
            $claimed = $log->affected_rows > 0;
            $log->close();
            if (!$claimed) {
                return; // another alert of this type went out recently
            }

            if (!function_exists('sendEmail')) {
                require_once __DIR__ . '/send_email.php';
            }

            $body = '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;">'
                . '<div style="background:#7f1d1d;padding:18px 22px;"><h1 style="color:#fff;margin:0;font-size:17px;">🚨 Security Alert — Apex Sports Club</h1></div>'
                . '<div style="padding:22px;">'
                . '<p style="font-size:14px;color:#334155;">A <strong>critical security event</strong> was recorded:</p>'
                . '<p style="font-family:monospace;font-size:13px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;padding:10px;">'
                . htmlspecialchars($eventType) . ': ' . htmlspecialchars($details) . '</p>'
                . '<p style="font-size:13px;color:#64748b;">Source IP: ' . htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '</p>'
                . '<p style="font-size:12px;color:#94a3b8;">Investigate immediately. A full digest of the last 24 hours is available via <code>cron_security_alert.php</code>.</p>'
                . '</div></div>';

            sendEmail($to, 'Club Admin', '🚨 Critical security event: ' . $eventType . ' — Apex Sports Club', $body);
        } catch (\Throwable $e) {
            error_log('[security_alert] failed for ' . $eventType . ': ' . $details);
        }
    }

    /**
     * Throttled variant for high-frequency choke points (rate limits, CSRF
     * rejects, callback rejects). An attacker flooding an endpoint would
     * otherwise insert one row PER blocked request — turning the telemetry
     * into a DB-write amplifier. This inserts at most one row per
     * (eventType + actor) per minute; subsequent hits in the window are
     * still surfaced to the error log so nothing is silently lost.
     */
    function log_security_event_throttled(string $eventType, string $severity = 'warning', string $details = '', ?string $actor = null): void
    {
        try {
            if (!isset($GLOBALS['conn']) || !($GLOBALS['conn'] instanceof mysqli)) {
                error_log('[security_event] ' . $eventType . ': ' . $details);
                return;
            }
            $conn = $GLOBALS['conn'];

            // One-time schema existence check (cached per request).
            if ($GLOBALS['__asc_secevents_ready'] === null) {
                $res = $conn->query("SHOW TABLES LIKE 'security_events'");
                $GLOBALS['__asc_secevents_ready'] = $res && $res->num_rows > 0;
                if ($res) { $res->free(); }
            }
            if (!$GLOBALS['__asc_secevents_ready']) {
                error_log('[security_event] ' . $eventType . ': ' . $details);
                return;
            }

            $chk = $conn->prepare(
                "SELECT COUNT(*) FROM security_events
                 WHERE event_type = ? AND (actor = ? OR (actor IS NULL AND ? IS NULL))
                   AND created_at > NOW() - INTERVAL 60 SECOND"
            );
            if ($chk) {
                $chk->bind_param('sss', $eventType, $actor, $actor);
                $chk->execute();
                $chk->bind_result($existing);
                $chk->fetch();
                $chk->close();
                if ((int) $existing > 0) {
                    // Already recorded this bucket in the last minute — keep
                    // the error-log trail but do not write another row.
                    error_log('[security_event] ' . $eventType . ': ' . $details);
                    return;
                }
            }

            log_security_event($eventType, $severity, $details, $actor);
        } catch (\Throwable $e) {
            error_log('[security_event] ' . $eventType . ': ' . $details);
        }
    }

    /**
     * Optional quick accessor used by the digest cron: count events of a type
     * since a given cutoff. Returns 0 on any failure.
     */
    function count_security_events(mysqli $conn, string $eventType, string $since): int
    {
        try {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM security_events WHERE event_type = ? AND created_at >= ?"
            );
            if (!$stmt) { return 0; }
            $stmt->bind_param('ss', $eventType, $since);
            $stmt->execute();
            $stmt->bind_result($count);
            $stmt->fetch();
            $stmt->close();
            return (int) $count;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
