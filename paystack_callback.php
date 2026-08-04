<?php
/**
 * Legacy Paystack callback endpoint.
 *
 * The canonical handler lives at callbacks/paystack_callback.php, which is
 * the URL configured in PAYSTACK_CALLBACK_URL (.env). That file includes
 * webhook HMAC signature verification, rate limiting, idempotent payment
 * recording, membership activation, ticket finalization, and promo
 * redemption.
 *
 * Keep this file as a thin forwarder so any legacy references (docs,
 * older .env values, Paystack dashboard webhook config) still work and
 * get the full, hardened flow.
 */

require_once __DIR__ . '/callbacks/paystack_callback.php';
