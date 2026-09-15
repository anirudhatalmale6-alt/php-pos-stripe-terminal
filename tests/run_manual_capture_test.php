<?php
/**
 * Manual-capture scenario: the reader only AUTHORISES the card, then this code
 * captures the money in a second step. Used for bar tabs, or any till that
 * adjusts the total after the card is presented.
 *
 * Runs against the same fake Stripe API, but with capture_method = manual, so
 * it needs its own config file (POS_CONFIG_FILE is set by run_tests.sh).
 */

require_once dirname(__DIR__) . '/lib/PosTerminal.php';

$pass = 0; $fail = 0;
function check($label, $cond, $detail = '')
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $label\n"; }
    else       { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  [$detail]" : '') . "\n"; }
}

$mock = getenv('MOCK_BASE') ? getenv('MOCK_BASE') : 'http://127.0.0.1:8899';
function mock_post($url, $body = array())
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
    $r = curl_exec($ch);
    curl_close($ch);
    return $r;
}

echo "\n[manual capture] authorise on the reader, then capture\n";

mock_post($mock . '/__mock/reset');
mock_post($mock . '/__mock/config', array('outcome' => 'approve', 'polls' => 1));

$pdo = pos_db();
$pdo->prepare('DELETE FROM pos_card_payments WHERE sale_id = ?')->execute(array('2001'));
$pdo->prepare('DELETE FROM sales WHERE id = ?')->execute(array(2001));
$pdo->prepare('INSERT INTO sales (id, total, voided) VALUES (?,?,0)')->execute(array(2001, 15.00));

$start = pos_start_card_payment('2001', array('amount' => '15.00'));
check('capture_method is manual in this config', pos_config('capture_method') === 'manual');
check('payment started', $start['status'] === 'in_progress', json_encode($start));

$row = pos_payment_find_by_sale('2001');
check('ledger records manual capture', $row['capture_method'] === 'manual', $row['capture_method']);

$final = null;
for ($i = 0; $i < 6; $i++) {
    $final = pos_check_card_payment('2001');
    if ($final['status'] !== 'in_progress') {
        break;
    }
    usleep(150000);
}
check('ends paid after the explicit capture', $final['status'] === 'paid', json_encode($final));
check('amount captured in full', isset($final['amount']) && $final['amount'] === '15.00', json_encode($final));

$st = $pdo->prepare('SELECT * FROM sales WHERE id = ?');
$st->execute(array(2001));
$sale = $st->fetch();
check('sale row updated to paid', $sale['payment_status'] === 'paid', json_encode($sale));
check('amount_paid written', abs($sale['amount_paid'] - 15.00) < 0.001, var_export($sale['amount_paid'], true));
check('charge id written', !empty($sale['stripe_charge_id']), json_encode($sale));

$events = $pdo->query("SELECT event_type FROM pos_card_payment_events WHERE payment_id = " . (int) $row['id'])->fetchAll();
$types  = array();
foreach ($events as $e) { $types[] = $e['event_type']; }
check('a capture step is in the audit trail', in_array('captured', $types, true), implode(',', $types));

echo "\n=== MANUAL CAPTURE: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
