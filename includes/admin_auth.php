<?php

require_once __DIR__ . '/session_config.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/admin_2fa.php';

asc_session_start();
csrf_ensure('admin_csrf');

// Mid-login: password OK, awaiting TOTP
if (admin_2fa_pending_valid()) {
    if (!admin_2fa_is_public_page()) {
        header('Location: admin_verify_2fa.php');
        exit;
    }
    return;
}

if (!isset($_SESSION['admin_loggedin']) || $_SESSION['admin_loggedin'] !== true) {
    if (!admin_2fa_is_public_page()) {
        header('Location: admin_login.php');
        exit;
    }
}
