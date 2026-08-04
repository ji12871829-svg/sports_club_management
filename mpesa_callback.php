<?php
/**
 * Legacy M-Pesa callback endpoint.
 *
 * This file is kept for backward compatibility. The canonical callback
 * handler lives at callbacks/mpesa_callback.php, which is the URL
 * configured in MPESA_CALLBACK_URL (.env).
 *
 * To avoid duplicate processing, forward all requests to the canonical file.
 */

require_once __DIR__ . '/callbacks/mpesa_callback.php';