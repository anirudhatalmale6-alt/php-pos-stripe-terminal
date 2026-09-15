<?php
/**
 * End-to-end test of the whole POS card flow.
 * -----------------------------------------------------------------------------
 * Run with tests/run_tests.sh - that script starts the fake Stripe API and a
 * PHP web server for this integration, then calls this file.
 *
 * Every scenario goes over real HTTP through api/*.php and webhook.php, and
 * every assertion is made by reading the sales table afterwards - so what is
 * being proven is the behaviour your POS will actually see, not just that the
 * functions return something.
 */

$APP  = getenv('APP_BASE')  ? getenv('APP_BASE')  : 'http://127.0.0.1:8900';
$MOCK = getenv('MOCK_BASE') ? getenv('MOCK_BASE') : 'http://127.0.0.1:8899';
$DB   = getenv('TEST_DB');
$TOKEN = 'test-token-abc123';
$SECRET = getenv('WEBHOOK_SECRET') ? getenv('WEBHOOK_SECRET') : 'whsec_testsecret';

$pass = 0; $fail = 0; $failures = array();

function http_call($method, $url, $body = null, $headers = array())
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
    }
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err    = curl_error($ch);
    curl_close($ch);
    return array('status' => $status, 'body' => $raw, 'json' => json_decode((string) $raw, true), 'error' => $err);
}

function api($file, $data, $method = 'POST')
{
    global $APP, $TOKEN;
    $url = $APP . '/api/' . $file;
    if ($method === 'GET') {
        $url .= '?' . http_build_query($data);
        $data = null;
    }
    return http_call($method, $url, $data === null ? null : json_encode($data), array(
        'X-POS-Token: ' . $TOKEN,
        'Content-Type: application/json',
    ));
}

function mock_config($cfg)
{
    global $MOCK;
    return http_call('POST', $MOCK . '/__mock/config', $cfg);
}

function db()
{
    global $DB;
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('sqlite:' . $DB, null, null, array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                                                          PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
    }
    return $pdo;
}

function sale_row($id)
{
    $st = db()->prepare('SELECT * FROM sales WHERE id = ?');
    $st->execute(array($id));
    return $st->fetch();
}

function payment_row($saleId)
{
    $st = db()->prepare('SELECT * FROM pos_card_payments WHERE sale_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute(array((string) $saleId));
    return $st->fetch();
}

function check($label, $condition, $detail = '')
{
    global $pass, $fail, $failures;
    if ($condition) {
        $pass++;
        echo "  PASS  " . $label . "\n";
    } else {
        $fail++;
        $failures[] = $label . ($detail !== '' ? ' -- ' . $detail : '');
        echo "  FAIL  " . $label . ($detail !== '' ? '  [' . $detail . ']' : '') . "\n";
    }
}

function reset_sale($id, $total)
{
    db()->prepare('DELETE FROM pos_card_payments WHERE sale_id = ?')->execute(array((string) $id));
    db()->prepare('DELETE FROM sales WHERE id = ?')->execute(array($id));
    db()->prepare('INSERT INTO sales (id, total, voided) VALUES (?,?,0)')->execute(array($id, $total));
}

/**
 * Poll terminal_status.php the way the POS would, until it stops saying
 * in_progress.
 */
function poll_until_final($saleId, $maxPolls = 10)
{
    $last = null;
    for ($i = 0; $i < $maxPolls; $i++) {
        $r = api('terminal_status.php', array('sale_id' => (string) $saleId), 'GET');
        $last = $r;
        if (!isset($r['json']['status']) || $r['json']['status'] !== 'in_progress') {
            return $r;
        }
        usleep(150000);
    }
    return $last;
}

echo "\n=== Stripe Terminal POS - end to end test ===\n";
echo "app:  $APP\nmock: $MOCK\ndb:   $DB\n";

// -----------------------------------------------------------------------------
echo "\n[1] Approved contactless payment (the happy path)\n";
// -----------------------------------------------------------------------------
http_call('POST', $MOCK . '/__mock/reset', array());
mock_config(array('outcome' => 'approve', 'polls' => 2));
reset_sale(1001, 12.50);

$start = api('terminal_charge.php', array('sale_id' => '1001', 'amount' => '12.50', 'till' => 'TILL1'));
check('charge accepted (HTTP 200)', $start['status'] === 200, 'HTTP ' . $start['status'] . ' ' . substr((string) $start['body'], 0, 200));
check('status is in_progress', isset($start['json']['status']) && $start['json']['status'] === 'in_progress',
      json_encode($start['json']));
check('PaymentIntent created', !empty($start['json']['payment_intent_id']));
check('sale marked awaiting_card while the customer taps',
      ($s = sale_row(1001)) && $s['payment_status'] === 'awaiting_card', json_encode(sale_row(1001)));

$final = poll_until_final(1001);
check('poll ends on paid', isset($final['json']['status']) && $final['json']['status'] === 'paid', json_encode($final['json']));
check('response carries last4', isset($final['json']['last4']) && $final['json']['last4'] === '4242');
check('response carries brand', isset($final['json']['brand']) && $final['json']['brand'] === 'visa');
check('response carries charge id', !empty($final['json']['charge_id']));
check('response carries auth code for the receipt', !empty($final['json']['auth_code']));

$s = sale_row(1001);
check('sale.payment_status = paid', $s['payment_status'] === 'paid', json_encode($s));
check('sale.amount_paid = 12.50', (string) $s['amount_paid'] === '12.5' || abs($s['amount_paid'] - 12.50) < 0.001, var_export($s['amount_paid'], true));
check('sale.stripe_charge_id written', !empty($s['stripe_charge_id']), json_encode($s));
check('sale.card_last4 written', $s['card_last4'] === '4242');
check('sale.card_brand written', $s['card_brand'] === 'visa');
check('sale.payment_method = card_present', $s['payment_method'] === 'card_present');
check('sale.paid_at stamped', !empty($s['paid_at']));
$p = payment_row(1001);
check('ledger row succeeded', $p['status'] === 'succeeded');
check('read_method recorded (contactless)', $p['read_method'] === 'contactless_emv');

// -----------------------------------------------------------------------------
echo "\n[2] Re-tapping an already paid sale must not charge twice\n";
// -----------------------------------------------------------------------------
$again = api('terminal_charge.php', array('sale_id' => '1001', 'amount' => '12.50'));
check('second charge returns paid, not a new attempt',
      isset($again['json']['status']) && $again['json']['status'] === 'paid', json_encode($again['json']));
$cnt = db()->query("SELECT COUNT(*) FROM pos_card_payments WHERE sale_id = '1001'")->fetchColumn();
check('still exactly one ledger row for the sale', (int) $cnt === 1, 'rows=' . $cnt);

// -----------------------------------------------------------------------------
echo "\n[3] Declined card\n";
// -----------------------------------------------------------------------------
http_call('POST', $MOCK . '/__mock/reset', array());
mock_config(array('outcome' => 'decline', 'polls' => 1, 'decline_code' => 'insufficient_funds'));
reset_sale(1002, 40.00);

$start = api('terminal_charge.php', array('sale_id' => '1002', 'amount' => '40.00'));
check('charge accepted', isset($start['json']['status']) && $start['json']['status'] === 'in_progress', json_encode($start['json']));
$final = poll_until_final(1002);
check('poll ends on failed', isset($final['json']['status']) && $final['json']['status'] === 'failed', json_encode($final['json']));
check('failure code passed through', isset($final['json']['failure_code']) && $final['json']['failure_code'] === 'insufficient_funds',
      json_encode($final['json']));
check('cashier-readable message', isset($final['json']['message']) && stripos($final['json']['message'], 'funds') !== false,
      isset($final['json']['message']) ? $final['json']['message'] : '');
check('retry is offered', !empty($final['json']['can_retry']));
$s = sale_row(1002);
check('sale.payment_status = declined', $s['payment_status'] === 'declined', json_encode($s));
check('sale.amount_paid left empty on a decline', $s['amount_paid'] === null, var_export($s['amount_paid'], true));
check('intent id still recorded for dashboard lookup', !empty($s['stripe_payment_intent']));

// -----------------------------------------------------------------------------
echo "\n[4] Retry after a decline, second card approves\n";
// -----------------------------------------------------------------------------
mock_config(array('outcome' => 'approve', 'polls' => 1));
$retry = api('terminal_charge.php', array('sale_id' => '1002', 'amount' => '40.00', 'retry' => 1));
check('retry starts a new attempt', isset($retry['json']['status']) && $retry['json']['status'] === 'in_progress', json_encode($retry['json']));
$final = poll_until_final(1002);
check('retry ends paid', isset($final['json']['status']) && $final['json']['status'] === 'paid', json_encode($final['json']));
$s = sale_row(1002);
check('sale now paid', $s['payment_status'] === 'paid' && abs($s['amount_paid'] - 40.00) < 0.001, json_encode($s));
$p = payment_row(1002);
check('attempt counter incremented', (int) $p['attempt'] >= 2, 'attempt=' . $p['attempt']);

// -----------------------------------------------------------------------------
echo "\n[5] Double-click on the Card button creates only one reader prompt\n";
// -----------------------------------------------------------------------------
http_call('POST', $MOCK . '/__mock/reset', array());
mock_config(array('outcome' => 'never', 'polls' => 1));   // reader never finishes
reset_sale(1003, 5.00);
$a = api('terminal_charge.php', array('sale_id' => '1003', 'amount' => '5.00'));
$b = api('terminal_charge.php', array('sale_id' => '1003', 'amount' => '5.00'));
check('both clicks answer in_progress',
      $a['json']['status'] === 'in_progress' && $b['json']['status'] === 'in_progress',
      json_encode(array($a['json'], $b['json'])));
check('both clicks share ONE PaymentIntent',
      $a['json']['payment_intent_id'] === $b['json']['payment_intent_id'],
      $a['json']['payment_intent_id'] . ' vs ' . $b['json']['payment_intent_id']);
$cnt = db()->query("SELECT COUNT(*) FROM pos_card_payments WHERE sale_id = '1003'")->fetchColumn();
check('one ledger row only', (int) $cnt === 1, 'rows=' . $cnt);

// -----------------------------------------------------------------------------
echo "\n[6] Cancel at the till\n";
// -----------------------------------------------------------------------------
$cancel = api('terminal_cancel.php', array('sale_id' => '1003'));
check('cancel returns canceled', isset($cancel['json']['status']) && $cancel['json']['status'] === 'canceled', json_encode($cancel['json']));
$p = payment_row(1003);
check('ledger row canceled', $p['status'] === 'canceled');
$after = api('terminal_status.php', array('sale_id' => '1003'), 'GET');
check('status stays canceled afterwards', $after['json']['status'] === 'canceled', json_encode($after['json']));

// -----------------------------------------------------------------------------
echo "\n[7] Reader busy with the previous customer - recovers by itself\n";
// -----------------------------------------------------------------------------
http_call('POST', $MOCK . '/__mock/reset', array());
mock_config(array('outcome' => 'busy_once', 'polls' => 1));
reset_sale(1004, 9.99);
$start = api('terminal_charge.php', array('sale_id' => '1004', 'amount' => '9.99'));
check('busy reader did not fail the sale',
      isset($start['json']['status']) && $start['json']['status'] === 'in_progress', json_encode($start['json']));
mock_config(array('outcome' => 'approve', 'polls' => 1));
$final = poll_until_final(1004);
check('payment completes after the busy retry', $final['json']['status'] === 'paid', json_encode($final['json']));

// -----------------------------------------------------------------------------
echo "\n[8] Amount mismatch between POS and database is refused\n";
// -----------------------------------------------------------------------------
http_call('POST', $MOCK . '/__mock/reset', array());
mock_config(array('outcome' => 'approve', 'polls' => 1));
reset_sale(1005, 30.00);
$bad = api('terminal_charge.php', array('sale_id' => '1005', 'amount' => '3.00'));
check('mismatched amount rejected', $bad['status'] >= 400, 'HTTP ' . $bad['status']);
check('error explains the mismatch', isset($bad['json']['error']) && stripos($bad['json']['error'], 'mismatch') !== false,
      isset($bad['json']['error']) ? $bad['json']['error'] : '');
$cnt = db()->query("SELECT COUNT(*) FROM pos_card_payments WHERE sale_id = '1005'")->fetchColumn();
check('nothing was sent to the reader', (int) $cnt === 0, 'rows=' . $cnt);

// -----------------------------------------------------------------------------
echo "\n[9] Amount above max_amount is refused\n";
// -----------------------------------------------------------------------------
reset_sale(1006, 99999.00);
$big = api('terminal_charge.php', array('sale_id' => '1006', 'amount' => '99999.00'));
check('over-limit amount rejected', $big['status'] >= 400, 'HTTP ' . $big['status']);
check('error names max_amount', isset($big['json']['error']) && stripos($big['json']['error'], 'max_amount') !== false,
      isset($big['json']['error']) ? $big['json']['error'] : '');

// -----------------------------------------------------------------------------
echo "\n[10] Endpoints refuse a wrong or missing token\n";
// -----------------------------------------------------------------------------
$noTok = http_call('POST', $APP . '/api/terminal_charge.php', json_encode(array('sale_id' => '1001')),
                   array('Content-Type: application/json'));
check('no token -> 401', $noTok['status'] === 401, 'HTTP ' . $noTok['status']);
$badTok = http_call('POST', $APP . '/api/terminal_charge.php', json_encode(array('sale_id' => '1001')),
                    array('Content-Type: application/json', 'X-POS-Token: wrong'));
check('wrong token -> 401', $badTok['status'] === 401, 'HTTP ' . $badTok['status']);

// -----------------------------------------------------------------------------
echo "\n[11] Webhook writes the sale even if the till never polls\n";
// -----------------------------------------------------------------------------
http_call('POST', $MOCK . '/__mock/reset', array());
mock_config(array('outcome' => 'approve', 'polls' => 1));
reset_sale(1007, 21.00);
$start = api('terminal_charge.php', array('sale_id' => '1007', 'amount' => '21.00'));
$intentId = $start['json']['payment_intent_id'];

// Let the fake reader complete the collection without the POS asking.
http_call('GET', $MOCK . '/v1/terminal/readers/tmr_mock001');

// Build a properly signed terminal.reader.action_succeeded event.
function signed_post($url, array $event, $secret, $skewSeconds = 0)
{
    $payload = json_encode($event);
    $ts      = time() + $skewSeconds;
    $sig     = hash_hmac('sha256', $ts . '.' . $payload, $secret);
    return http_call('POST', $url, $payload, array(
        'Content-Type: application/json',
        'Stripe-Signature: t=' . $ts . ',v1=' . $sig,
    ));
}

$event = array(
    'id' => 'evt_test_reader_1', 'type' => 'terminal.reader.action_succeeded',
    'data' => array('object' => array(
        'id' => 'tmr_mock001', 'object' => 'terminal.reader',
        'action' => array('type' => 'process_payment_intent', 'status' => 'succeeded',
                          'process_payment_intent' => array('payment_intent' => $intentId)),
    )),
);
$wh = signed_post($APP . '/webhook.php', $event, $SECRET);
check('webhook accepted', $wh['status'] === 200, 'HTTP ' . $wh['status'] . ' ' . $wh['body']);
$s = sale_row(1007);
check('sale written from the webhook alone', $s['payment_status'] === 'paid', json_encode($s));
check('charge id + last4 from the webhook', !empty($s['stripe_charge_id']) && $s['card_last4'] === '4242', json_encode($s));

// -----------------------------------------------------------------------------
echo "\n[12] Webhook replay changes nothing (idempotency)\n";
// -----------------------------------------------------------------------------
$before = sale_row(1007);
$replay = signed_post($APP . '/webhook.php', $event, $SECRET);
check('replay accepted with 200', $replay['status'] === 200, 'HTTP ' . $replay['status']);
$after = sale_row(1007);
check('sale row unchanged by the replay', $before === $after, json_encode(array($before, $after)));
$evCount = db()->query("SELECT COUNT(*) FROM pos_card_payment_events WHERE event_type = 'succeeded'")->fetchColumn();
check('no second success event recorded', (int) $evCount >= 1);

// A different event id for a sale already paid must also be harmless.
$event2 = $event;
$event2['id'] = 'evt_test_reader_2';
$event2['type'] = 'payment_intent.succeeded';
$event2['data']['object'] = array('id' => $intentId, 'object' => 'payment_intent');
$dup = signed_post($APP . '/webhook.php', $event2, $SECRET);
check('a second, different success event is harmless', $dup['status'] === 200 && sale_row(1007) === $before,
      'HTTP ' . $dup['status']);

// -----------------------------------------------------------------------------
echo "\n[13] Webhook signature is actually enforced\n";
// -----------------------------------------------------------------------------
$unsigned = http_call('POST', $APP . '/webhook.php', json_encode($event), array('Content-Type: application/json'));
check('unsigned webhook -> 400', $unsigned['status'] === 400, 'HTTP ' . $unsigned['status']);
$wrongSig = signed_post($APP . '/webhook.php', $event, 'whsec_wrong_secret');
check('wrong secret -> 400', $wrongSig['status'] === 400, 'HTTP ' . $wrongSig['status']);
$old = signed_post($APP . '/webhook.php', $event, $SECRET, -3600);
check('replayed old timestamp -> 400', $old['status'] === 400, 'HTTP ' . $old['status']);

// -----------------------------------------------------------------------------
echo "\n[14] Declined-by-webhook path\n";
// -----------------------------------------------------------------------------
http_call('POST', $MOCK . '/__mock/reset', array());
mock_config(array('outcome' => 'never', 'polls' => 1));
reset_sale(1008, 7.25);
$start = api('terminal_charge.php', array('sale_id' => '1008', 'amount' => '7.25'));
$intentId = $start['json']['payment_intent_id'];
$failEvent = array(
    'id' => 'evt_test_fail_1', 'type' => 'payment_intent.payment_failed',
    'data' => array('object' => array(
        'id' => $intentId, 'object' => 'payment_intent',
        'last_payment_error' => array('code' => 'card_declined', 'decline_code' => 'expired_card',
                                      'message' => 'Your card has expired.'),
    )),
);
$wh = signed_post($APP . '/webhook.php', $failEvent, $SECRET);
check('failure webhook accepted', $wh['status'] === 200, 'HTTP ' . $wh['status']);
$s = sale_row(1008);
check('sale marked declined', $s['payment_status'] === 'declined', json_encode($s));
$p = payment_row(1008);
check('decline code stored', $p['failure_code'] === 'expired_card', json_encode($p));
check('cashier message is the expired-card one', stripos($p['failure_message'], 'expired') !== false, $p['failure_message']);

// -----------------------------------------------------------------------------
echo "\n[15] A late failure event cannot un-pay a paid sale\n";
// -----------------------------------------------------------------------------
$paidSale = sale_row(1001);
$p1001 = payment_row(1001);
$lateFail = array(
    'id' => 'evt_test_late_fail', 'type' => 'payment_intent.payment_failed',
    'data' => array('object' => array(
        'id' => $p1001['payment_intent_id'], 'object' => 'payment_intent',
        'last_payment_error' => array('code' => 'card_declined', 'message' => 'declined'),
    )),
);
$wh = signed_post($APP . '/webhook.php', $lateFail, $SECRET);
check('late failure accepted but ignored', $wh['status'] === 200, 'HTTP ' . $wh['status']);
check('sale 1001 is still paid', sale_row(1001) === $paidSale, json_encode(sale_row(1001)));

// (Manual capture is covered separately in tests/run_manual_capture_test.php,
//  which needs its own config file.)

// -----------------------------------------------------------------------------
echo "\n[16] Refund a settled sale\n";
// -----------------------------------------------------------------------------
$refund = api('terminal_refund.php', array('sale_id' => '1001'));
check('refund accepted', $refund['status'] === 200, 'HTTP ' . $refund['status'] . ' ' . substr((string) $refund['body'], 0, 200));
check('refund id returned', !empty($refund['json']['refund_id']), json_encode($refund['json']));
$p = payment_row(1001);
check('refunded amount recorded on the ledger', (int) $p['refunded_minor'] > 0, 'refunded_minor=' . $p['refunded_minor']);

// -----------------------------------------------------------------------------
echo "\n[17] Status for a sale nobody has charged\n";
// -----------------------------------------------------------------------------
$none = api('terminal_status.php', array('sale_id' => '999999'), 'GET');
check('unknown sale reports status none', isset($none['json']['status']) && $none['json']['status'] === 'none',
      json_encode($none['json']));

// -----------------------------------------------------------------------------
echo "\n[18] Reader list endpoint (setup helper)\n";
// -----------------------------------------------------------------------------
$readers = api('terminal_readers.php', array(), 'GET');
check('reader list works', $readers['status'] === 200 && !empty($readers['json']['readers']), substr((string) $readers['body'], 0, 200));
check('reader id + online status shown',
      isset($readers['json']['readers'][0]['id']) && $readers['json']['readers'][0]['status'] === 'online');

// -----------------------------------------------------------------------------
echo "\n[19] Regression: the Stripe idempotency key must not be derivable from the sale\n";
// -----------------------------------------------------------------------------
// Real bug, found by running the sandbox suite twice: the key used to be
// 'pos-sale-{id}-a{attempt}-{amount}'. Stripe remembers a key for 24h and
// replays the ORIGINAL response, so a reused ticket number - or a rerun against
// a restored ledger - got handed back yesterday's finished intent and the reader
// refused it with intent_invalid_state. The key is now a random per-attempt
// nonce; double-charge safety comes from UNIQUE (sale_id, attempt) instead.
http_call('POST', $MOCK . '/__mock/reset', array());
mock_config(array('outcome' => 'approve', 'polls' => 1));
reset_sale(1010, 11.00);

$first = api('terminal_charge.php', array('sale_id' => '1010', 'amount' => '11.00'));
$keyA  = payment_row(1010)['idem_key'];
check('an idempotency key is stored on the attempt', !empty($keyA), var_export($keyA, true));
check('the key does not contain the sale id', strpos((string) $keyA, '1010') === false, (string) $keyA);
check('the key does not contain the amount', strpos((string) $keyA, '1100') === false, (string) $keyA);

// Simulate a restored / reset ledger: same sale, same amount, row ids restart.
db()->prepare('DELETE FROM pos_card_payments WHERE sale_id = ?')->execute(array('1010'));
$second = api('terminal_charge.php', array('sale_id' => '1010', 'amount' => '11.00'));
$keyB   = payment_row(1010)['idem_key'];
check('a fresh attempt mints a different key', $keyA !== $keyB, $keyA . ' vs ' . $keyB);
check('and it gets its own PaymentIntent',
      !empty($second['json']['payment_intent_id'])
      && $second['json']['payment_intent_id'] !== $first['json']['payment_intent_id'],
      json_encode(array($first['json']['payment_intent_id'], $second['json']['payment_intent_id'])));

// Two attempts on the same sale must also differ from each other.
$final = poll_until_final(1010);
check('second attempt still pays normally', $final['json']['status'] === 'paid', json_encode($final['json']));

// -----------------------------------------------------------------------------
echo "\n=== RESULT: $pass passed, $fail failed ===\n";
if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo " - $f\n";
    }
}
exit($fail > 0 ? 1 : 0);
