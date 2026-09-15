<?php
/**
 * Smoke test against the REAL Stripe API in test mode.
 * -----------------------------------------------------------------------------
 * tests/run_tests.sh proves the logic against a fake Stripe. This file proves
 * the same code against Stripe itself - real PaymentIntents, a real reader
 * object, real declines - using a SIMULATED reader so nothing has to be tapped
 * by hand and the reader on the counter is never disturbed.
 *
 *   POS_CONFIG_FILE=/path/to/config.sandbox.php php tests/run_sandbox_test.php
 *
 * Requirements in that config:
 *   stripe_secret_key  sk_test_...            (NEVER a live key)
 *   default_reader_id  tmr_... of a simulated reader
 *                      (create one: Dashboard > Terminal > Readers > Register,
 *                       or POST /v1/terminal/readers registration_code=simulated-wpe)
 *
 * Test cards Stripe honours on a simulated reader:
 *   4242424242424242  approves
 *   4000000000000002  generic decline
 *   4000000000009995  insufficient funds
 */

require_once dirname(__DIR__) . '/lib/PosTerminal.php';

$pass = 0; $fail = 0; $notes = array();

function check($label, $cond, $detail = '')
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else       { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}

$stripe = new StripeApi();
if ($stripe->isLiveMode()) {
    echo "REFUSING TO RUN: this config holds a LIVE key. Sandbox tests use sk_test_ only.\n";
    exit(2);
}

$reader = pos_config('default_reader_id');
$info   = $stripe->retrieveReader($reader);
echo "\n=== Sandbox test against real Stripe (test mode) ===\n";
echo "reader:   {$info['id']}  {$info['device_type']}  {$info['status']}\n";
echo "currency: " . strtoupper(pos_config('currency')) . "\n";

if (strpos($info['device_type'], 'simulated') === false) {
    echo "\nNOTE: {$info['id']} is a PHYSICAL reader. This script cannot present a card\n";
    echo "      on it - someone has to tap. Point default_reader_id at a simulated\n";
    echo "      reader for an unattended run.\n";
    exit(3);
}

/** Drive one sale all the way through, presenting $card on the simulated reader. */
function run_sale($saleId, $total, $card, $expect)
{
    global $stripe;
    $pdo = pos_db();
    $pdo->prepare('DELETE FROM pos_card_payments WHERE sale_id = ?')->execute(array((string) $saleId));
    $pdo->prepare('DELETE FROM sales WHERE id = ?')->execute(array($saleId));
    $pdo->prepare('INSERT INTO sales (id, total, voided) VALUES (?,?,0)')->execute(array($saleId, $total));

    $start = pos_start_card_payment((string) $saleId, array('amount' => $total));
    check("sale $saleId: reader prompted", $start['status'] === 'in_progress', json_encode($start));
    if ($start['status'] !== 'in_progress') {
        return null;
    }
    echo "        intent {$start['payment_intent_id']}\n";

    // Stand in for the customer tapping.
    try {
        $stripe->testPresentPaymentMethod(pos_config('default_reader_id'), $card);
    } catch (StripeApiException $e) {
        // A declined simulated card can surface as an API error here; the
        // reader action still ends up failed, which is what we then read.
        echo "        present_payment_method: {$e->getMessage()}\n";
    }

    $res = null;
    for ($i = 0; $i < 20; $i++) {
        $res = pos_check_card_payment((string) $saleId);
        if ($res['status'] !== 'in_progress') {
            break;
        }
        usleep(700000);
    }
    check("sale $saleId: ends '$expect'", $res['status'] === $expect, json_encode($res));
    return $res;
}

// -----------------------------------------------------------------------------
echo "\n[1] Approved - Visa 4242, contactless\n";
// -----------------------------------------------------------------------------
$res = run_sale(9001, '12.50', '4242424242424242', 'paid');
if ($res && $res['status'] === 'paid') {
    check('charge id returned', !empty($res['charge_id']), json_encode($res));
    check('last4 = 4242', isset($res['last4']) && $res['last4'] === '4242', json_encode($res));
    check('brand returned', !empty($res['brand']), json_encode($res));
    check('amount = 12.50', $res['amount'] === '12.50', json_encode($res));
    echo "        charge {$res['charge_id']}  {$res['brand']} ****{$res['last4']}"
       . "  auth " . (isset($res['auth_code']) ? $res['auth_code'] : '-')
       . "  read " . (isset($res['read_method']) ? $res['read_method'] : '-') . "\n";

    $st = pos_db()->prepare('SELECT * FROM sales WHERE id = ?');
    $st->execute(array(9001));
    $sale = $st->fetch();
    check('sale row: status paid', $sale['payment_status'] === 'paid', json_encode($sale));
    check('sale row: amount_paid 12.50', abs($sale['amount_paid'] - 12.50) < 0.001, var_export($sale['amount_paid'], true));
    check('sale row: charge id written', $sale['stripe_charge_id'] === $res['charge_id'], json_encode($sale));
    check('sale row: last4 written', $sale['card_last4'] === '4242');
    check('sale row: brand written', !empty($sale['card_brand']));
    check('sale row: paid_at stamped', !empty($sale['paid_at']));

    // Confirm at Stripe's end too, not just in our own database.
    $intent = $stripe->retrievePaymentIntent($res['payment_intent_id']);
    check('Stripe agrees the intent succeeded', $intent['status'] === 'succeeded', $intent['status']);
    check('Stripe received the full amount', (int) $intent['amount_received'] === 1250,
          'amount_received=' . $intent['amount_received']);
    check('sale_id is on the Stripe intent metadata',
          isset($intent['metadata']['sale_id']) && $intent['metadata']['sale_id'] === '9001',
          json_encode(isset($intent['metadata']) ? $intent['metadata'] : null));
}

// -----------------------------------------------------------------------------
echo "\n[2] Declined - generic decline card\n";
// -----------------------------------------------------------------------------
$res = run_sale(9002, '40.00', '4000000000000002', 'failed');
if ($res && $res['status'] === 'failed') {
    check('failure code present', !empty($res['failure_code']), json_encode($res));
    check('cashier message present', !empty($res['message']), json_encode($res));
    check('retry offered', !empty($res['can_retry']));
    echo "        code {$res['failure_code']} - {$res['message']}\n";
    $st = pos_db()->prepare('SELECT * FROM sales WHERE id = ?');
    $st->execute(array(9002));
    $sale = $st->fetch();
    check('sale row: declined', $sale['payment_status'] === 'declined', json_encode($sale));
    check('sale row: nothing paid', $sale['amount_paid'] === null, var_export($sale['amount_paid'], true));
    check('sale row: intent id kept for the dashboard', !empty($sale['stripe_payment_intent']));
}

// -----------------------------------------------------------------------------
echo "\n[3] Retry after the decline, good card second time\n";
// -----------------------------------------------------------------------------
$retry = pos_start_card_payment('9002', array('amount' => '40.00', 'retry' => true));
check('retry prompts the reader again', $retry['status'] === 'in_progress', json_encode($retry));
if ($retry['status'] === 'in_progress') {
    try {
        $stripe->testPresentPaymentMethod(pos_config('default_reader_id'), '4242424242424242');
    } catch (StripeApiException $e) {
        echo "        present_payment_method: {$e->getMessage()}\n";
    }
    $res = null;
    for ($i = 0; $i < 20; $i++) {
        $res = pos_check_card_payment('9002');
        if ($res['status'] !== 'in_progress') { break; }
        usleep(700000);
    }
    check('retry ends paid', $res['status'] === 'paid', json_encode($res));
    $st = pos_db()->prepare('SELECT * FROM sales WHERE id = ?');
    $st->execute(array(9002));
    $sale = $st->fetch();
    check('sale row now paid at 40.00', $sale['payment_status'] === 'paid' && abs($sale['amount_paid'] - 40.00) < 0.001,
          json_encode($sale));
}

// -----------------------------------------------------------------------------
echo "\n[4] Insufficient funds card reports its own reason\n";
// -----------------------------------------------------------------------------
$res = run_sale(9003, '7.25', '4000000000009995', 'failed');
if ($res) {
    echo "        code " . (isset($res['failure_code']) ? $res['failure_code'] : '-')
       . " - " . (isset($res['message']) ? $res['message'] : '-') . "\n";
    check('reason is about funds, not a generic decline',
          stripos(json_encode($res), 'funds') !== false, json_encode($res));
}

// -----------------------------------------------------------------------------
echo "\n[5] Cancel a live prompt\n";
// -----------------------------------------------------------------------------
$pdo = pos_db();
$pdo->prepare('DELETE FROM pos_card_payments WHERE sale_id = ?')->execute(array('9004'));
$pdo->prepare('DELETE FROM sales WHERE id = ?')->execute(array(9004));
$pdo->prepare('INSERT INTO sales (id, total, voided) VALUES (?,?,0)')->execute(array(9004, 5.00));
$start = pos_start_card_payment('9004', array('amount' => '5.00'));
check('prompt started', $start['status'] === 'in_progress', json_encode($start));
$cancel = pos_cancel_card_payment('9004');
check('cancel takes effect', $cancel['status'] === 'canceled', json_encode($cancel));
if (!empty($start['payment_intent_id'])) {
    $intent = $stripe->retrievePaymentIntent($start['payment_intent_id'], false);
    check('Stripe shows the intent canceled', $intent['status'] === 'canceled', $intent['status']);
}

// -----------------------------------------------------------------------------
echo "\n[6] Double-tap on the Card button - against real Stripe\n";
// -----------------------------------------------------------------------------
$pdo->prepare('DELETE FROM pos_card_payments WHERE sale_id = ?')->execute(array('9005'));
$pdo->prepare('DELETE FROM sales WHERE id = ?')->execute(array(9005));
$pdo->prepare('INSERT INTO sales (id, total, voided) VALUES (?,?,0)')->execute(array(9005, 6.00));
$a = pos_start_card_payment('9005', array('amount' => '6.00'));
$b = pos_start_card_payment('9005', array('amount' => '6.00'));
check('both clicks share one PaymentIntent at Stripe',
      $a['payment_intent_id'] === $b['payment_intent_id'],
      $a['payment_intent_id'] . ' vs ' . $b['payment_intent_id']);

// Pay it, then press the button again: must not create a second charge.
try {
    $stripe->testPresentPaymentMethod(pos_config('default_reader_id'), '4242424242424242');
} catch (StripeApiException $e) {
    echo "        present_payment_method: {$e->getMessage()}\n";
}
$res = null;
for ($i = 0; $i < 20; $i++) {
    $res = pos_check_card_payment('9005');
    if ($res['status'] !== 'in_progress') { break; }
    usleep(700000);
}
check('sale 9005 paid once', $res['status'] === 'paid', json_encode($res));
$afterPaid = pos_start_card_payment('9005', array('amount' => '6.00'));
check('pressing Card on a paid sale returns the same charge',
      $afterPaid['status'] === 'paid'
      && (!isset($afterPaid['charge_id']) || $afterPaid['charge_id'] === $res['charge_id']),
      json_encode($afterPaid));
// Ask Stripe directly how many charges exist for this sale.
$search = $stripe->request('GET', '/v1/charges', array('payment_intent' => $res['payment_intent_id'], 'limit' => 10));
check('Stripe holds exactly ONE charge for the sale', count($search['data']) === 1,
      'charges=' . count($search['data']));

// -----------------------------------------------------------------------------
echo "\n[7] Refund the approved sale\n";
// -----------------------------------------------------------------------------
$row = pos_payment_find_by_sale('9001');
if ($row && !empty($row['charge_id'])) {
    // Keyed on the charge id, same as api/terminal_refund.php does.
    $refund = $stripe->refundCharge($row['charge_id'], null, 'sandbox-refund-' . $row['charge_id']);
    check('refund succeeded at Stripe', isset($refund['status']) && $refund['status'] === 'succeeded',
          json_encode(isset($refund['status']) ? $refund['status'] : $refund));
    echo "        refund {$refund['id']}  " . pos_from_minor_units($refund['amount'], $row['currency']) . "\n";
} else {
    check('refund step had a charge to work with', false, 'no charge id on sale 9001');
}

echo "\n=== SANDBOX: $pass passed, $fail failed ===\n";
echo "Every object above is in the Stripe test dashboard under Payments / Terminal.\n";
exit($fail > 0 ? 1 : 0);
