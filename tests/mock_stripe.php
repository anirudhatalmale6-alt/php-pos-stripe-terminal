<?php
/**
 * A tiny fake Stripe API, for testing the whole flow with no keys and no
 * hardware.
 * -----------------------------------------------------------------------------
 * It implements only the endpoints this integration uses, and it can be told to
 * approve, decline, go busy or time out, so the POS screens can be exercised
 * for every outcome before the real WisePOS E is on the counter.
 *
 *   php -S 127.0.0.1:8899 tests/mock_stripe.php
 *   then set  'stripe_api_base' => 'http://127.0.0.1:8899'
 *
 * Control endpoints:
 *   POST /__mock/config  {outcome:approve|decline|busy_once|never, polls:1, decline_code:...}
 *   POST /__mock/reset
 *   GET  /__mock/state
 *
 * This file is for development only - never deploy it.
 */

$stateFile = getenv('MOCK_STATE') ? getenv('MOCK_STATE') : sys_get_temp_dir() . '/stripe_mock_state.json';

function mock_state($stateFile, $new = null)
{
    if ($new !== null) {
        file_put_contents($stateFile, json_encode($new));
        return $new;
    }
    if (!is_file($stateFile)) {
        return array(
            'config'  => array('outcome' => 'approve', 'polls' => 1, 'decline_code' => 'card_declined', 'capture_method_override' => null),
            'intents' => array(),
            'readers' => array(),
            'seq'     => 0,
            'busy_used' => false,
        );
    }
    $s = json_decode(file_get_contents($stateFile), true);
    return is_array($s) ? $s : array('config' => array('outcome' => 'approve', 'polls' => 1), 'intents' => array(), 'readers' => array(), 'seq' => 0);
}

function mock_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function mock_error($message, $code, $type = 'invalid_request_error', $httpCode = 400)
{
    mock_out(array('error' => array('message' => $message, 'code' => $code, 'type' => $type)), $httpCode);
}

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body   = array();
parse_str(file_get_contents('php://input'), $body);
$state  = mock_state($stateFile);

// ---------------------------------------------------------------- control ----
if ($path === '/__mock/reset') {
    @unlink($stateFile);
    mock_out(array('reset' => true));
}
if ($path === '/__mock/state') {
    mock_out($state);
}
if ($path === '/__mock/config') {
    foreach (array('outcome', 'polls', 'decline_code', 'capture_method_override') as $k) {
        if (isset($body[$k])) {
            $state['config'][$k] = $body[$k];
        }
    }
    $state['busy_used'] = false;
    mock_state($stateFile, $state);
    mock_out(array('config' => $state['config']));
}

// ------------------------------------------------------------- reader list ---
if ($path === '/v1/terminal/readers' && $method === 'GET') {
    mock_out(array('object' => 'list', 'data' => array(array(
        'id' => 'tmr_mock001', 'object' => 'terminal.reader', 'label' => 'Counter 1',
        'device_type' => 'bbpos_wisepos_e', 'status' => 'online', 'ip_address' => '192.168.1.55',
        'location' => 'tml_mock', 'serial_number' => 'WPC123456', 'device_sw_version' => '2.24.3.0',
        'last_seen_at' => (time() * 1000), 'livemode' => false,
        'action' => isset($state['readers']['tmr_mock001']['action']) ? $state['readers']['tmr_mock001']['action'] : null,
    ))));
}

// ------------------------------------------------------- payment intents -----
if ($path === '/v1/payment_intents' && $method === 'POST') {
    $state['seq']++;
    $id = 'pi_mock' . str_pad($state['seq'], 4, '0', STR_PAD_LEFT);
    $intent = array(
        'id' => $id, 'object' => 'payment_intent',
        'amount' => (int) $body['amount'], 'amount_received' => 0,
        'currency' => $body['currency'],
        'capture_method' => isset($body['capture_method']) ? $body['capture_method'] : 'automatic',
        'status' => 'requires_payment_method',
        'metadata' => isset($body['metadata']) ? $body['metadata'] : array(),
        'description' => isset($body['description']) ? $body['description'] : null,
        'latest_charge' => null, 'last_payment_error' => null,
        'livemode' => false,
    );
    $state['intents'][$id] = $intent;
    mock_state($stateFile, $state);
    mock_out($intent);
}

if (preg_match('#^/v1/payment_intents/([^/]+)$#', $path, $m) && $method === 'GET') {
    $id = $m[1];
    if (!isset($state['intents'][$id])) {
        mock_error('No such payment_intent: ' . $id, 'resource_missing', 'invalid_request_error', 404);
    }
    mock_out($state['intents'][$id]);
}

if (preg_match('#^/v1/payment_intents/([^/]+)/capture$#', $path, $m) && $method === 'POST') {
    $id = $m[1];
    if (!isset($state['intents'][$id])) {
        mock_error('No such payment_intent', 'resource_missing', 'invalid_request_error', 404);
    }
    $i = $state['intents'][$id];
    if ($i['status'] !== 'requires_capture') {
        mock_error('Cannot capture an intent in status ' . $i['status'], 'payment_intent_unexpected_state');
    }
    $i['status'] = 'succeeded';
    $i['amount_received'] = $i['amount'];
    $state['intents'][$id] = $i;
    mock_state($stateFile, $state);
    mock_out($i);
}

if (preg_match('#^/v1/payment_intents/([^/]+)/cancel$#', $path, $m) && $method === 'POST') {
    $id = $m[1];
    if (!isset($state['intents'][$id])) {
        mock_error('No such payment_intent', 'resource_missing', 'invalid_request_error', 404);
    }
    $state['intents'][$id]['status'] = 'canceled';
    mock_state($stateFile, $state);
    mock_out($state['intents'][$id]);
}

// ------------------------------------------------- process_payment_intent ----
if (preg_match('#^/v1/terminal/readers/([^/]+)/process_payment_intent$#', $path, $m) && $method === 'POST') {
    $readerId = $m[1];
    $intentId = isset($body['payment_intent']) ? $body['payment_intent'] : '';
    if (!isset($state['intents'][$intentId])) {
        mock_error('No such payment_intent: ' . $intentId, 'resource_missing', 'invalid_request_error', 404);
    }
    // Simulate "reader still busy with the last customer" once, so the retry
    // path in pos_start_card_payment gets exercised.
    if ($state['config']['outcome'] === 'busy_once' && empty($state['busy_used'])) {
        $state['busy_used'] = true;
        mock_state($stateFile, $state);
        mock_error('Reader is currently busy.', 'terminal_reader_busy', 'invalid_request_error', 409);
    }
    $state['readers'][$readerId] = array('action' => array(
        'type' => 'process_payment_intent', 'status' => 'in_progress',
        'failure_code' => null, 'failure_message' => null,
        'process_payment_intent' => array('payment_intent' => $intentId),
    ), 'polls' => 0);
    mock_state($stateFile, $state);
    mock_out(array('id' => $readerId, 'object' => 'terminal.reader', 'device_type' => 'bbpos_wisepos_e',
                   'status' => 'online', 'action' => $state['readers'][$readerId]['action'], 'livemode' => false));
}

if (preg_match('#^/v1/terminal/readers/([^/]+)/cancel_action$#', $path, $m) && $method === 'POST') {
    $readerId = $m[1];
    $state['readers'][$readerId] = array('action' => null, 'polls' => 0);
    mock_state($stateFile, $state);
    mock_out(array('id' => $readerId, 'object' => 'terminal.reader', 'action' => null));
}

if (preg_match('#^/v1/terminal/readers/([^/]+)/set_reader_display$#', $path, $m) && $method === 'POST') {
    mock_out(array('id' => $m[1], 'object' => 'terminal.reader', 'action' => array(
        'type' => 'set_reader_display', 'status' => 'succeeded')));
}

// --------------------------------------------------------- reader polling ----
if (preg_match('#^/v1/terminal/readers/([^/]+)$#', $path, $m) && $method === 'GET') {
    $readerId = $m[1];
    $r = isset($state['readers'][$readerId]) ? $state['readers'][$readerId] : array('action' => null, 'polls' => 0);

    if (!empty($r['action']) && $r['action']['status'] === 'in_progress') {
        $r['polls'] = (int) $r['polls'] + 1;
        $needed = max(1, (int) $state['config']['polls']);
        $outcome = $state['config']['outcome'];

        if ($outcome !== 'never' && $r['polls'] >= $needed) {
            $intentId = $r['action']['process_payment_intent']['payment_intent'];
            $intent   = $state['intents'][$intentId];

            if ($outcome === 'decline') {
                $r['action']['status']          = 'failed';
                $r['action']['failure_code']    = $state['config']['decline_code'];
                $r['action']['failure_message'] = 'The card was declined.';
                $intent['status'] = 'requires_payment_method';
                $intent['last_payment_error'] = array(
                    'code' => 'card_declined',
                    'decline_code' => $state['config']['decline_code'],
                    'message' => 'Your card was declined.',
                );
            } else {
                $r['action']['status'] = 'succeeded';
                $manual = ($intent['capture_method'] === 'manual');
                $intent['status'] = $manual ? 'requires_capture' : 'succeeded';
                $intent['amount_received'] = $manual ? 0 : $intent['amount'];
                $intent['latest_charge'] = array(
                    'id' => 'ch_mock' . substr($intentId, 7),
                    'object' => 'charge',
                    'amount' => $intent['amount'],
                    'paid' => true,
                    'receipt_url' => 'https://pay.stripe.com/receipts/mock',
                    'payment_method_details' => array(
                        'type' => 'card_present',
                        'card_present' => array(
                            'brand' => 'visa', 'last4' => '4242',
                            'read_method' => 'contactless_emv',
                            'exp_month' => 12, 'exp_year' => 2030,
                            'receipt' => array('authorization_code' => '123456',
                                               'application_preferred_name' => 'Visa Credit'),
                        ),
                    ),
                );
            }
            $state['intents'][$intentId] = $intent;
        }
        $state['readers'][$readerId] = $r;
        mock_state($stateFile, $state);
    }

    mock_out(array('id' => $readerId, 'object' => 'terminal.reader', 'device_type' => 'bbpos_wisepos_e',
                   'status' => 'online', 'label' => 'Counter 1',
                   'action' => $r['action'], 'livemode' => false));
}

// ------------------------------------------------------------------ refunds ---
if ($path === '/v1/refunds' && $method === 'POST') {
    mock_out(array('id' => 're_mock001', 'object' => 'refund', 'status' => 'succeeded',
                   'charge' => isset($body['charge']) ? $body['charge'] : null,
                   'amount' => isset($body['amount']) ? (int) $body['amount'] : 1250));
}

mock_error('Mock does not implement ' . $method . ' ' . $path, 'not_implemented', 'invalid_request_error', 404);
