<?php
/**
 * Stripe Terminal for POS - configuration
 * -----------------------------------------------------------------------------
 * Copy this file to config.php and fill in your own values.
 * config.php is git-ignored on purpose: it holds your secret keys.
 *
 * PHP 7.0+ compatible. No frameworks, no Composer dependencies.
 */

return array(

    // -------------------------------------------------------------------------
    // Stripe API keys  (Dashboard > Developers > API keys)
    // -------------------------------------------------------------------------
    // Use sk_test_... while testing, sk_live_... when you go live.
    'stripe_secret_key'      => 'sk_test_REPLACE_ME',

    // Webhook signing secret (whsec_...) from Dashboard > Developers > Webhooks
    // after you add the endpoint that points at /webhook.php
    'stripe_webhook_secret'  => 'whsec_REPLACE_ME',

    // Pinned Stripe API version. Keep this fixed so a Stripe-side upgrade can
    // never change the JSON shape under your running POS.
    'stripe_api_version'     => '2024-06-20',

    // Only change this for the local test harness (tests/mock_stripe.php).
    'stripe_api_base'        => 'https://api.stripe.com',

    // -------------------------------------------------------------------------
    // Reader
    // -------------------------------------------------------------------------
    // Default BBPOS WisePOS E reader id (tmr_...). Find it with:
    //   php tools_list_readers.php        (or GET api/terminal_readers.php)
    // A till can override this per request by sending reader_id.
    'default_reader_id'      => 'tmr_REPLACE_ME',

    // Map a till / workstation name to its own reader, so a 2-lane shop does
    // not need to send reader ids from the POS at all.
    //   'till_readers' => array('TILL1' => 'tmr_aaa', 'TILL2' => 'tmr_bbb'),
    'till_readers'           => array(),

    // -------------------------------------------------------------------------
    // Money
    // -------------------------------------------------------------------------
    'currency'               => 'usd',

    // 'automatic' = Stripe captures the money as soon as the card is approved
    //               (normal retail: one step, done).
    // 'manual'    = the charge is only authorised on the reader; this code then
    //               captures it explicitly. Use for bars/tabs or if you adjust
    //               the total after authorisation.
    'capture_method'         => 'automatic',

    // Where the amount to charge comes from:
    //   'db'      - read it from your sales table (safest: the browser cannot
    //               talk the server into charging a different figure)
    //   'request' - trust the amount the POS posts
    //   'both'    - read from DB and reject if the POS-sent amount disagrees
    'amount_source'          => 'both',

    // Reject anything above this (in major units, e.g. dollars). Cheap
    // protection against a mis-keyed total like 19900 instead of 199.00.
    'max_amount'             => 5000.00,

    // -------------------------------------------------------------------------
    // Tipping / cancellation on the reader
    // -------------------------------------------------------------------------
    // true  -> WisePOS E shows a tip screen before the card prompt
    'tipping_enabled'        => false,

    // Let the customer press cancel on the reader screen itself.
    'customer_cancellation'  => true,

    // -------------------------------------------------------------------------
    // Database (your existing POS database)
    // -------------------------------------------------------------------------
    'db' => array(
        'dsn'      => 'mysql:host=127.0.0.1;dbname=your_pos;charset=utf8mb4',
        'user'     => 'pos_user',
        'password' => 'CHANGE_ME',
    ),

    // -------------------------------------------------------------------------
    // Where to READ the sale total from (used when amount_source is db/both)
    // -------------------------------------------------------------------------
    'sale_lookup' => array(
        'table'        => 'sales',
        'pk_column'    => 'id',
        'total_column' => 'total',   // major units, e.g. 12.50
    ),

    // -------------------------------------------------------------------------
    // How to write the result back into YOUR sale/ticket record
    // -------------------------------------------------------------------------
    // Change the names on the right to match your own schema. Any column you
    // set to null is simply skipped, so you only fill in what you actually have.
    'sale_writeback' => array(
        'table'            => 'sales',
        'pk_column'        => 'id',            // matched against the POS sale_id
        'amount_column'    => 'amount_paid',   // written in MAJOR units (e.g. 12.50)
        'status_column'    => 'payment_status',
        'status_paid'      => 'paid',
        'status_failed'    => 'declined',
        'status_pending'   => 'awaiting_card',
        'method_column'    => 'payment_method',
        'method_value'     => 'card_present',
        'charge_id_column' => 'stripe_charge_id',
        'intent_id_column' => 'stripe_payment_intent',
        'last4_column'     => 'card_last4',
        'brand_column'     => 'card_brand',
        'paid_at_column'   => 'paid_at',       // filled with NOW()
        // Optional: only ever touch rows that are still open.
        'guard_sql'        => '',              // e.g. "voided = 0"
    ),

    // -------------------------------------------------------------------------
    // Access control for the api/*.php endpoints
    // -------------------------------------------------------------------------
    // The POS must send this as header:  X-POS-Token: <value>
    // Generate one with:  php -r "echo bin2hex(random_bytes(24));"
    'api_token'              => 'REPLACE_WITH_A_LONG_RANDOM_STRING',

    // Optionally restrict to your shop LAN (empty array = no IP restriction).
    //   'allowed_ips' => array('192.168.1.0/24', '127.0.0.1'),
    'allowed_ips'            => array(),

    // -------------------------------------------------------------------------
    // Timing
    // -------------------------------------------------------------------------
    // How long the POS may sit on one card prompt before we give up and cancel
    // the reader action (seconds). Stripe's own reader timeout is ~60s per
    // prompt; 120 covers a tip screen plus a card prompt comfortably.
    'payment_timeout'        => 120,

    'http_timeout'           => 30,   // per Stripe HTTP call
    'http_retries'           => 2,    // retries on network error / 5xx

    // -------------------------------------------------------------------------
    // Logging
    // -------------------------------------------------------------------------
    // Every Stripe call and state change is appended here. Card numbers are
    // never logged - only the last 4, which is safe to store.
    'log_file'               => __DIR__ . '/logs/stripe_terminal.log',
    'log_level'              => 'info',   // debug | info | error
);
