<?php
/**
 * Shared helpers: config loading, logging, money conversion, request auth.
 * PHP 7.0+ compatible.
 */

/**
 * Load config.php once and return it.
 */
function pos_config($key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) {
        // POS_CONFIG_FILE lets the test harness (and a staging vhost) point at
        // a different config without touching the live one.
        $env  = getenv('POS_CONFIG_FILE');
        $file = ($env && is_file($env)) ? $env : dirname(__DIR__) . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config.php is missing - copy config.sample.php to config.php and fill it in.');
        }
        $cfg = require $file;
        if (!is_array($cfg)) {
            throw new RuntimeException('config.php must return an array.');
        }
    }
    if ($key === null) {
        return $cfg;
    }
    return array_key_exists($key, $cfg) ? $cfg[$key] : $default;
}

/**
 * Append a line to the log file. Never logs full card data - Stripe never
 * gives us a PAN in the first place, only brand + last4.
 */
function pos_log($level, $message, array $context = array())
{
    $levels = array('debug' => 10, 'info' => 20, 'error' => 30);
    $min = isset($levels[pos_config('log_level', 'info')]) ? $levels[pos_config('log_level', 'info')] : 20;
    $cur = isset($levels[$level]) ? $levels[$level] : 20;
    if ($cur < $min) {
        return;
    }

    $file = pos_config('log_file', dirname(__DIR__) . '/logs/stripe_terminal.log');
    $dir  = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = sprintf(
        "[%s] %-5s %s%s\n",
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message,
        $context ? ' ' . json_encode($context) : ''
    );
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Currencies Stripe expects WITHOUT decimals (¥100 is 100, not 10000).
 */
function pos_zero_decimal_currencies()
{
    return array('bif','clp','djf','gnf','jpy','kmf','krw','mga','pyg','rwf','ugx','vnd','vuv','xaf','xof','xpf');
}

/**
 * 12.50 USD -> 1250 ; 1200 JPY -> 1200
 * Rounds half-up at the smallest unit so 0.005 never disappears silently.
 */
function pos_to_minor_units($amount, $currency)
{
    $currency = strtolower($currency);
    if (in_array($currency, pos_zero_decimal_currencies(), true)) {
        return (int) round((float) $amount);
    }
    return (int) round(((float) $amount) * 100);
}

/**
 * 1250 -> 12.50 (string, so it goes into DECIMAL columns without float drift)
 */
function pos_from_minor_units($minor, $currency)
{
    $currency = strtolower($currency);
    if (in_array($currency, pos_zero_decimal_currencies(), true)) {
        return (string) (int) $minor;
    }
    return number_format(((int) $minor) / 100, 2, '.', '');
}

/**
 * Send a JSON response and stop. Every api/ endpoint answers in this shape,
 * so the POS only ever has to look at .ok and .status.
 */
function pos_json_response($payload, $httpCode = 200)
{
    if (!headers_sent()) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode($payload);
    exit;
}

function pos_json_error($message, $httpCode = 400, $extra = array())
{
    pos_log('error', 'API error: ' . $message, $extra);
    pos_json_response(array_merge(array('ok' => false, 'error' => $message), $extra), $httpCode);
}

/**
 * Read the request body as an array, accepting either JSON or a normal
 * form post - whichever your POS finds easier to send.
 */
function pos_request_input()
{
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    if (!empty($_POST)) {
        return $_POST;
    }
    return $_GET;
}

/**
 * Shared-secret + optional IP check for the api/ endpoints.
 * Call this first in every endpoint.
 */
function pos_require_auth()
{
    $expected = (string) pos_config('api_token', '');
    if ($expected === '' || $expected === 'REPLACE_WITH_A_LONG_RANDOM_STRING') {
        pos_json_error('Server not configured: set api_token in config.php', 500);
    }

    $given = '';
    if (isset($_SERVER['HTTP_X_POS_TOKEN'])) {
        $given = (string) $_SERVER['HTTP_X_POS_TOKEN'];
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $given = trim(preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION']));
    } elseif (isset($_REQUEST['pos_token'])) {
        // Fallback for tills that cannot set headers.
        $given = (string) $_REQUEST['pos_token'];
    }

    // hash_equals = constant time, so the token cannot be guessed byte by byte.
    if (!hash_equals($expected, $given)) {
        pos_json_error('Unauthorized', 401);
    }

    $allowed = pos_config('allowed_ips', array());
    if (!empty($allowed)) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        if (!pos_ip_allowed($ip, $allowed)) {
            pos_json_error('Forbidden for this IP (' . $ip . ')', 403);
        }
    }
}

/**
 * IPv4 match against a list of plain addresses and CIDR ranges.
 */
function pos_ip_allowed($ip, array $allowed)
{
    $ipLong = ip2long($ip);
    foreach ($allowed as $rule) {
        $rule = trim($rule);
        if ($rule === '') {
            continue;
        }
        if (strpos($rule, '/') === false) {
            if ($rule === $ip) {
                return true;
            }
            continue;
        }
        if ($ipLong === false) {
            continue;
        }
        $parts = explode('/', $rule, 2);
        $net   = ip2long($parts[0]);
        $bits  = (int) $parts[1];
        if ($net === false || $bits < 0 || $bits > 32) {
            continue;
        }
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;
        if ((($ipLong & $mask) === ($net & $mask))) {
            return true;
        }
    }
    return false;
}

/**
 * Translate Stripe's machine codes into something a cashier can act on.
 */
function pos_decline_message($code, $fallback = '')
{
    // Keys are Stripe decline_code / error code values. Deliberately worded as
    // an instruction to the cashier, because that is who reads them.
    $map = array(
        // --- the issuer said no -------------------------------------------
        'card_declined'                 => 'Card declined - ask for another card',
        'generic_decline'               => 'Card declined - ask for another card',
        'do_not_honor'                  => 'Card declined by the bank - ask for another card',
        'transaction_not_allowed'       => 'This card will not allow the payment - ask for another card',
        'service_not_allowed'           => 'This card will not allow the payment - ask for another card',
        'not_permitted'                 => 'This card will not allow the payment - ask for another card',
        'card_not_supported'            => 'This card type is not supported - ask for another card',
        'currency_not_supported'        => 'Card cannot pay in this currency - ask for another card',
        'restricted_card'               => 'Card is restricted - ask for another card',
        'security_violation'            => 'Card declined - ask for another card',
        'stop_payment_order'            => 'Card declined - ask for another card',
        'merchant_blacklist'            => 'Card declined - ask for another card',
        'invalid_account'               => 'Card declined - ask for another card',
        'fraudulent'                    => 'Card declined - ask for another card',
        'insufficient_funds'            => 'Insufficient funds - ask for another card',
        'withdrawal_count_limit_exceeded' => 'Card limit reached - ask for another card',
        'expired_card'                  => 'Card expired - ask for another card',
        'lost_card'                     => 'Card declined - ask for another card',
        'stolen_card'                   => 'Card declined - ask for another card',
        'pickup_card'                   => 'Card declined - ask for another card',
        'testmode_decline'              => 'Test card declined (test mode)',

        // --- PIN -----------------------------------------------------------
        'incorrect_pin'                 => 'Wrong PIN - ask the customer to retry',
        'invalid_pin'                   => 'Wrong PIN - ask the customer to retry',
        'pin_try_exceeded'              => 'Too many PIN attempts - card blocked, use another card',
        'pin_required'                  => 'PIN required - ask the customer to insert the card',
        'offline_pin_required'          => 'PIN required - ask the customer to insert the card',
        'online_or_offline_pin_required'=> 'PIN required - ask the customer to insert the card',

        // --- retryable ------------------------------------------------------
        'processing_error'              => 'Processing error - please retry the tap',
        'try_again_later'               => 'Issuer busy - please retry the tap',
        'issuer_not_available'          => 'Bank not reachable - please retry the tap',
        'reenter_transaction'           => 'Please retry the tap',
        'approve_with_id'               => 'Bank needs the payment retried - please tap again',
        'authentication_required'       => 'Card needs authentication - ask the customer to insert and enter PIN',

        // --- our side / the reader -----------------------------------------
        'canceled'                      => 'Payment cancelled',
        'reader_timeout'                => 'Reader timed out - please retry the tap',
        'failed_to_process'             => 'Reader could not read the card - retry or try chip/swipe',
        'terminal_reader_timeout'       => 'Reader did not respond - check it is on WiFi and awake',
        'terminal_reader_offline'       => 'Reader is offline - check its WiFi connection',
        'terminal_reader_busy'          => 'Reader is busy with another payment',
        'terminal_reader_hardware_fault'=> 'Reader hardware fault - restart the reader',
        'intent_invalid_state'          => 'This sale is no longer in a payable state',
        'setup_failed'                  => 'The payment could not be started - please press Card again',
        'capture_failed'                => 'Card was authorised but the capture failed - check Stripe before retrying',
    );
    if ($code !== null && $code !== '' && isset($map[$code])) {
        return $map[$code];
    }
    if ($fallback !== '') {
        return $fallback;
    }
    return 'Payment failed' . ($code ? ' (' . $code . ')' : '');
}
