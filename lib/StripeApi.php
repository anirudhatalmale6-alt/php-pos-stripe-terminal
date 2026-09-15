<?php
/**
 * Minimal Stripe REST client built on cURL.
 * -----------------------------------------------------------------------------
 * Why not stripe-php? Your stack is plain PHP 7.x with no Composer, and every
 * endpoint this integration needs is a single form-encoded POST or GET. This
 * file is ~200 lines, has no dependencies, and pins the API version, so a
 * Stripe library upgrade can never break your till.
 *
 * Everything here is synchronous and returns decoded arrays.
 */

require_once __DIR__ . '/Support.php';

class StripeApiException extends RuntimeException
{
    /** @var string Stripe error code, e.g. card_declined, terminal_reader_busy */
    public $stripeCode = '';
    /** @var string Stripe error type, e.g. invalid_request_error */
    public $stripeType = '';
    /** @var int HTTP status */
    public $httpStatus = 0;
    /** @var array Full decoded error body */
    public $body = array();

    public function __construct($message, $httpStatus = 0, $code = '', $type = '', array $body = array())
    {
        parent::__construct($message);
        $this->httpStatus = (int) $httpStatus;
        $this->stripeCode = (string) $code;
        $this->stripeType = (string) $type;
        $this->body       = $body;
    }
}

class StripeApi
{
    private $secretKey;
    private $apiBase;
    private $apiVersion;
    private $timeout;
    private $retries;

    public function __construct(array $options = array())
    {
        $this->secretKey  = isset($options['secret_key'])  ? $options['secret_key']  : pos_config('stripe_secret_key');
        $this->apiBase    = isset($options['api_base'])    ? $options['api_base']    : pos_config('stripe_api_base', 'https://api.stripe.com');
        $this->apiVersion = isset($options['api_version']) ? $options['api_version'] : pos_config('stripe_api_version', '2024-06-20');
        $this->timeout    = isset($options['timeout'])     ? (int) $options['timeout'] : (int) pos_config('http_timeout', 30);
        $this->retries    = isset($options['retries'])     ? (int) $options['retries'] : (int) pos_config('http_retries', 2);

        if (!$this->secretKey || strpos($this->secretKey, 'REPLACE') !== false) {
            throw new RuntimeException('stripe_secret_key is not set in config.php');
        }
    }

    /** True when the configured key is a live key - used to keep test/live apart. */
    public function isLiveMode()
    {
        return strpos($this->secretKey, 'sk_live_') === 0 || strpos($this->secretKey, 'rk_live_') === 0;
    }

    // -------------------------------------------------------------------------
    // PaymentIntents
    // -------------------------------------------------------------------------

    /**
     * Create the PaymentIntent for an in-person (card_present) sale.
     *
     * @param int    $amountMinor   e.g. 1250 for $12.50
     * @param string $currency      'usd'
     * @param array  $metadata      keys written onto the intent (we always put sale_id here)
     * @param array  $opts          capture_method, description, customer, idempotency_key,
     *                              statement_descriptor_suffix, receipt_email
     */
    public function createPaymentIntent($amountMinor, $currency, array $metadata = array(), array $opts = array())
    {
        $params = array(
            'amount'                 => (int) $amountMinor,
            'currency'               => strtolower($currency),
            // card_present is what makes this intent collectable on a Terminal reader.
            'payment_method_types'   => array('card_present'),
            'capture_method'         => isset($opts['capture_method']) ? $opts['capture_method'] : 'automatic',
        );
        if (!empty($opts['description'])) {
            $params['description'] = $opts['description'];
        }
        if (!empty($opts['customer'])) {
            $params['customer'] = $opts['customer'];
        }
        if (!empty($opts['receipt_email'])) {
            $params['receipt_email'] = $opts['receipt_email'];
        }
        if (!empty($opts['statement_descriptor_suffix'])) {
            $params['statement_descriptor_suffix'] = $opts['statement_descriptor_suffix'];
        }
        if (!empty($metadata)) {
            $params['metadata'] = $metadata;
        }

        $headers = array();
        if (!empty($opts['idempotency_key'])) {
            // Same key = same intent. A double-click on "Tap Card" cannot create
            // two intents (and cannot charge twice).
            $headers['Idempotency-Key'] = $opts['idempotency_key'];
        }

        return $this->request('POST', '/v1/payment_intents', $params, $headers);
    }

    /**
     * Fetch an intent. latest_charge is expanded so we get brand/last4/auth code
     * in the same round trip.
     */
    public function retrievePaymentIntent($intentId, $expandCharge = true)
    {
        $query = $expandCharge ? array('expand' => array('latest_charge')) : array();
        return $this->request('GET', '/v1/payment_intents/' . rawurlencode($intentId), $query);
    }

    /** Only needed when capture_method = manual. */
    public function capturePaymentIntent($intentId, $amountToCaptureMinor = null, $idempotencyKey = null)
    {
        $params = array('expand' => array('latest_charge'));
        if ($amountToCaptureMinor !== null) {
            $params['amount_to_capture'] = (int) $amountToCaptureMinor;
        }
        $headers = $idempotencyKey ? array('Idempotency-Key' => $idempotencyKey) : array();
        return $this->request('POST', '/v1/payment_intents/' . rawurlencode($intentId) . '/capture', $params, $headers);
    }

    public function cancelPaymentIntent($intentId, $reason = null)
    {
        $params = array();
        if ($reason !== null) {
            $params['cancellation_reason'] = $reason; // duplicate|fraudulent|requested_by_customer|abandoned
        }
        return $this->request('POST', '/v1/payment_intents/' . rawurlencode($intentId) . '/cancel', $params);
    }

    // -------------------------------------------------------------------------
    // Terminal readers
    // -------------------------------------------------------------------------

    /**
     * Hand the intent to the reader. This is the call that lights up the
     * WisePOS E screen with "Present card".
     *
     * Returns the reader object; reader['action']['status'] starts as in_progress.
     */
    public function processPaymentIntentOnReader($readerId, $intentId, array $processConfig = array())
    {
        $params = array('payment_intent' => $intentId);
        if (!empty($processConfig)) {
            $params['process_config'] = $processConfig;
        }
        return $this->request('POST', '/v1/terminal/readers/' . rawurlencode($readerId) . '/process_payment_intent', $params);
    }

    /** Current reader state, including action.status / action.failure_code. */
    public function retrieveReader($readerId)
    {
        return $this->request('GET', '/v1/terminal/readers/' . rawurlencode($readerId), array());
    }

    /** Stop whatever the reader is doing (cashier pressed Cancel, or we timed out). */
    public function cancelReaderAction($readerId)
    {
        return $this->request('POST', '/v1/terminal/readers/' . rawurlencode($readerId) . '/cancel_action', array());
    }

    /** Show a line-item cart on the reader screen while the customer waits. */
    public function setReaderDisplay($readerId, $currency, array $lineItems, $totalMinor, $taxMinor = null)
    {
        $params = array(
            'type'            => 'cart',
            'cart'            => array(
                'currency'   => strtolower($currency),
                'total'      => (int) $totalMinor,
                'line_items' => $lineItems, // each: description, amount (minor), quantity
            ),
        );
        if ($taxMinor !== null) {
            $params['cart']['tax'] = (int) $taxMinor;
        }
        return $this->request('POST', '/v1/terminal/readers/' . rawurlencode($readerId) . '/set_reader_display', $params);
    }

    /** Clear the reader screen back to idle/splash. */
    public function clearReaderDisplay($readerId)
    {
        // cancel_action also returns the screen to idle after a cart display.
        return $this->cancelReaderAction($readerId);
    }

    public function listReaders(array $filters = array())
    {
        return $this->request('GET', '/v1/terminal/readers', $filters);
    }

    public function listLocations($limit = 20)
    {
        return $this->request('GET', '/v1/terminal/locations', array('limit' => (int) $limit));
    }

    /**
     * Refund an in-person charge straight back onto the card-present charge.
     */
    public function refundCharge($chargeId, $amountMinor = null, $idempotencyKey = null)
    {
        $params = array('charge' => $chargeId);
        if ($amountMinor !== null) {
            $params['amount'] = (int) $amountMinor;
        }
        $headers = $idempotencyKey ? array('Idempotency-Key' => $idempotencyKey) : array();
        return $this->request('POST', '/v1/refunds', $params, $headers);
    }

    // -------------------------------------------------------------------------
    // Test helpers (test mode only) - lets you drive a SIMULATED reader from
    // your own code so the whole flow can be proven before hardware arrives.
    // -------------------------------------------------------------------------

    /**
     * $cardNumber: 4242424242424242 approves, 4000000000000002 declines.
     */
    public function testPresentPaymentMethod($readerId, $cardNumber = null)
    {
        $params = array();
        if ($cardNumber !== null) {
            $params['card_present'] = array('number' => $cardNumber);
            $params['type']         = 'card_present';
        }
        return $this->request(
            'POST',
            '/v1/test_helpers/terminal/readers/' . rawurlencode($readerId) . '/present_payment_method',
            $params
        );
    }

    // -------------------------------------------------------------------------
    // Transport
    // -------------------------------------------------------------------------

    /**
     * One Stripe HTTP call, with retries on network failures and 5xx only.
     * 4xx is never retried - a decline is an answer, not a glitch.
     *
     * @throws StripeApiException
     */
    public function request($method, $path, array $params = array(), array $extraHeaders = array())
    {
        $url  = rtrim($this->apiBase, '/') . $path;
        $body = null;

        if (strtoupper($method) === 'GET') {
            if (!empty($params)) {
                $url .= '?' . $this->encode($params);
            }
        } else {
            $body = $this->encode($params);
        }

        $headers = array(
            'Authorization: Bearer ' . $this->secretKey,
            'Stripe-Version: ' . $this->apiVersion,
            'Content-Type: application/x-www-form-urlencoded',
            'Expect:', // stop cURL adding a 100-continue that some proxies choke on
        );
        foreach ($extraHeaders as $k => $v) {
            $headers[] = $k . ': ' . $v;
        }

        $attempt  = 0;
        $lastErr  = '';
        $maxTries = max(1, $this->retries + 1);

        while ($attempt < $maxTries) {
            $attempt++;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $cerr   = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                $lastErr = 'Network error talking to Stripe: ' . $cerr;
                pos_log('error', $lastErr, array('attempt' => $attempt, 'path' => $path));
                if ($attempt < $maxTries) {
                    usleep(300000 * $attempt);
                    continue;
                }
                throw new StripeApiException($lastErr, 0, 'api_connection_error');
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $lastErr = 'Unreadable response from Stripe (HTTP ' . $status . ')';
                if ($status >= 500 && $attempt < $maxTries) {
                    usleep(300000 * $attempt);
                    continue;
                }
                throw new StripeApiException($lastErr, $status, 'api_error');
            }

            if ($status >= 200 && $status < 300) {
                pos_log('debug', 'Stripe ' . strtoupper($method) . ' ' . $path . ' -> ' . $status);
                return $decoded;
            }

            $err  = isset($decoded['error']) ? $decoded['error'] : array();
            $msg  = isset($err['message']) ? $err['message'] : ('Stripe returned HTTP ' . $status);
            $code = isset($err['code']) ? $err['code'] : (isset($err['decline_code']) ? $err['decline_code'] : '');
            $type = isset($err['type']) ? $err['type'] : '';

            if ($status >= 500 && $attempt < $maxTries) {
                pos_log('error', 'Stripe 5xx, retrying', array('path' => $path, 'attempt' => $attempt));
                usleep(300000 * $attempt);
                continue;
            }

            pos_log('error', 'Stripe error: ' . $msg, array('path' => $path, 'code' => $code, 'status' => $status));
            throw new StripeApiException($msg, $status, $code, $type, $decoded);
        }

        throw new StripeApiException($lastErr !== '' ? $lastErr : 'Stripe request failed', 0, 'api_error');
    }

    /**
     * Stripe wants PHP-style bracket notation for nested params:
     *   metadata[sale_id]=123&payment_method_types[0]=card_present
     * http_build_query does exactly that, so we only need to normalise bools.
     */
    private function encode(array $params)
    {
        $normalise = null;
        $normalise = function ($value) use (&$normalise) {
            if (is_bool($value)) {
                return $value ? 'true' : 'false';
            }
            if (is_array($value)) {
                $out = array();
                foreach ($value as $k => $v) {
                    if ($v === null) {
                        continue;
                    }
                    $out[$k] = $normalise($v);
                }
                return $out;
            }
            return $value;
        };
        $clean = array();
        foreach ($params as $k => $v) {
            if ($v === null) {
                continue;
            }
            $clean[$k] = $normalise($v);
        }
        return http_build_query($clean, '', '&', PHP_QUERY_RFC3986);
    }

    // -------------------------------------------------------------------------
    // Webhook signature verification (no library needed)
    // -------------------------------------------------------------------------

    /**
     * Verify a Stripe-Signature header against the raw request body.
     *
     * @param string $payload   raw body, exactly as received (do not re-encode!)
     * @param string $sigHeader value of the Stripe-Signature header
     * @param string $secret    whsec_...
     * @param int    $tolerance max age of the timestamp in seconds
     * @return array decoded event
     * @throws RuntimeException when the signature does not check out
     */
    public static function verifyWebhook($payload, $sigHeader, $secret, $tolerance = 300)
    {
        $timestamp = null;
        $signatures = array();
        foreach (explode(',', (string) $sigHeader) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = (int) $kv[1];
            } elseif ($kv[0] === 'v1') {
                $signatures[] = $kv[1];
            }
        }
        if (!$timestamp || empty($signatures)) {
            throw new RuntimeException('Missing or malformed Stripe-Signature header');
        }
        if ($tolerance > 0 && abs(time() - $timestamp) > $tolerance) {
            // Blocks replay of an old, legitimately-signed event.
            throw new RuntimeException('Webhook timestamp outside tolerance');
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $match = false;
        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) {
                $match = true;
                break;
            }
        }
        if (!$match) {
            throw new RuntimeException('Webhook signature mismatch');
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            throw new RuntimeException('Webhook body is not valid JSON');
        }
        return $event;
    }
}
