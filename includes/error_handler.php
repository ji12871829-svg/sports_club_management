<?php
/**
 * Global error handler for Apex Sports Club
 *
 * Catches uncaught exceptions (especially mysqli_sql_exception) and renders
 * a friendly error page instead of dumping a raw stack trace.
 *
 * Usage: require_once __DIR__ . '/error_handler.php';
 * Place this at the top of admin_header.php or any page entry point.
 *
 * Robustness notes (fixed):
 *  - header() / http_response_code() are only called when headers have NOT
 *    been sent yet. Pages that stream output (e.g. admin_header.php) then hit
 *    a mid-render SQL exception used to trigger "Cannot modify header
 *    information" — which the error handler converted into a second exception
 *    and cascaded into a fatal. Both failure modes are now impossible.
 *  - A recursion guard prevents the handler from re-entering itself.
 */

$GLOBALS['__asc_rendering_error'] = false;

/**
 * Render a friendly error page.
 */
function asc_render_error_page(string $message, string $type = 'exception'): void {
    // Recursion guard — never re-render; just stop the request cleanly.
    if (!empty($GLOBALS['__asc_rendering_error'])) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        exit(1);
    }
    $GLOBALS['__asc_rendering_error'] = true;

    $is_sql = $type === 'sql';
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $is_admin = strpos($script, '/admin/') !== false;
    $home_url = $is_admin ? 'admin_dashboard.php' : '../public/index.php';
    $debug = defined('APP_DEBUG') && APP_DEBUG;

    // Flush any buffered output so we control the response body.
    while (ob_get_level()) { ob_end_clean(); }

    // Only send headers if nothing has been written to the client yet.
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apex Sports Club - Error</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f1f5f9; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 1rem; }
        .error-card { max-width: 520px; width: 100%; background: #fff; border-radius: 16px; padding: 2.5rem; box-shadow: 0 4px 24px rgba(0,0,0,.06); text-align: center; }
        .error-icon { width: 72px; height: 72px; background: #fee2e2; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.25rem; }
        .error-icon i { font-size: 1.8rem; color: #ef4444; }
        h4 { font-weight: 700; color: #1e293b; margin-bottom: .5rem; }
        p { color: #64748b; font-size: .9rem; margin-bottom: .25rem; }
        .error-detail { font-size: .8rem; color: #94a3b8; word-break: break-word; margin: 1rem 0 1.5rem; background: #f8fafc; border-radius: 8px; padding: .75rem 1rem; text-align: left; }
        .error-detail code { font-size: .78rem; color: #475569; }
        .btn-home { background: #2563eb; color: #fff; border: none; border-radius: 9px; padding: .6rem 1.5rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: .5rem; transition: opacity .15s; }
        .btn-home:hover { opacity: .85; color: #fff; }
        .btn-retry { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; border-radius: 9px; padding: .6rem 1.5rem; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: .5rem; margin-left: .5rem; transition: all .15s; }
        .btn-retry:hover { background: #e2e8f0; color: #334155; }
        .error-footer { margin-top: 1.5rem; font-size: .75rem; color: #94a3b8; }
        .error-footer a { color: #64748b; }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h4>Something went wrong</h4>
        <p>The system encountered an unexpected error.</p>
        <?php if ($is_sql): ?>
            <p style="font-size:.82rem;color:#94a3b8;">This may be caused by a missing database table or a connection issue.</p>
        <?php endif; ?>
        <?php if ($debug && !empty($message)): ?>
            <div class="error-detail">
                <code><?php echo htmlspecialchars($message); ?></code>
            </div>
        <?php endif; ?>
        <div style="margin-top:1.5rem;">
            <a href="<?php echo htmlspecialchars($home_url); ?>" class="btn-home">
                <i class="fas fa-home"></i> Go to Dashboard
            </a>
            <a href="javascript:location.reload()" class="btn-retry">
                <i class="fas fa-redo"></i> Retry
            </a>
        </div>
        <div class="error-footer">
            <a href="<?php echo htmlspecialchars($home_url); ?>">Apex Sports Club</a>
            &middot; Please contact admin if the problem persists.
        </div>
    </div>
</body>
</html>
    <?php
    exit(1);
}

// ── Exception handler ─────────────────────────────────────────────────────────
set_exception_handler(function (Throwable $e) {
    error_log('[Apex Club] ' . get_class($e) . ': ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    $msg = get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine();
    try {
        asc_render_error_page($msg, $e instanceof mysqli_sql_exception ? 'sql' : 'exception');
    } catch (\Throwable $inner) {
        if (!headers_sent()) { http_response_code(500); }
        echo 'Apex Sports Club encountered an error. Please contact the administrator.';
        exit(1);
    }
});

// ── Fatal error handler (shutdown) ───────────────────────────────────────────
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        error_log('[Apex Club Fatal] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        try {
            asc_render_error_page($err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'], 'exception');
        } catch (\Throwable $inner) {
            if (!headers_sent()) { http_response_code(500); }
            exit(1);
        }
    }
});

// ── Convert mysqli errors to exceptions ──────────────────────────────────────
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// ── Convert PHP warnings/notices to ErrorException ───────────────────────────
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    // Respect error_reporting level
    if (!(error_reporting() & $severity)) {
        return false;
    }
    // Don't convert suppressed errors (@ operator)
    if (error_reporting() === 0) {
        return false;
    }
    // Never throw while we are already rendering the error page — otherwise a
    // warning inside the handler (e.g. from a header() call) becomes a second
    // exception and cascades into a fatal error.
    if (!empty($GLOBALS['__asc_rendering_error'])) {
        return true; // suppress — we are already failing gracefully
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
