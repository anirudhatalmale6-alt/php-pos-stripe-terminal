<?php
/**
 * A typo in the column mapping must not cost a payment.
 * -----------------------------------------------------------------------------
 * The write-back happens AFTER the card is charged. If config.php names a
 * column that does not exist, the naive behaviour is an SQL error at exactly
 * the worst moment: money taken, sale not updated, cashier staring at an error.
 *
 * This config maps two bogus columns. The payment must still complete, the
 * real columns must still be written, and the bad ones must be logged loudly.
 *
 * Run by tests/run_tests.sh with POS_CONFIG_FILE=config.badmap.php
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

echo "\n[bad mapping] a column that does not exist must not break the payment\n";

mock_post($mock . '/__mock/reset');
mock_post($mock . '/__mock/config', array('outcome' => 'approve', 'polls' => 1));

$map = pos_config('sale_writeback');
check('this config really does map a bogus column',
      $map['last4_column'] === 'column_that_does_not_exist', json_encode($map['last4_column']));

// The introspection helper itself.
check('pos_column_exists finds a real column', pos_column_exists('sales', 'payment_status'));
check('pos_column_exists rejects a fake one', !pos_column_exists('sales', 'column_that_does_not_exist'));
check('an unreadable table degrades to "cannot verify", not "empty"',
      pos_table_columns('no_such_table_at_all') === null
      && pos_column_exists('no_such_table_at_all', 'whatever') === true);

$pdo = pos_db();
$pdo->prepare('DELETE FROM pos_card_payments WHERE sale_id = ?')->execute(array('4001'));
$pdo->prepare('DELETE FROM sales WHERE id = ?')->execute(array(4001));
$pdo->prepare('INSERT INTO sales (id, total, voided) VALUES (?,?,0)')->execute(array(4001, 18.00));

$logFile = pos_config('log_file');
$before  = is_file($logFile) ? filesize($logFile) : 0;

$start = pos_start_card_payment('4001', array('amount' => '18.00'));
check('payment starts', $start['status'] === 'in_progress', json_encode($start));

$res = null;
for ($i = 0; $i < 10; $i++) {
    $res = pos_check_card_payment('4001');
    if ($res['status'] !== 'in_progress') { break; }
    usleep(150000);
}
check('payment still reports PAID despite the bad mapping', $res['status'] === 'paid', json_encode($res));

$st = $pdo->prepare('SELECT * FROM sales WHERE id = ?');
$st->execute(array(4001));
$sale = $st->fetch();
check('the good columns were still written',
      $sale['payment_status'] === 'paid' && abs($sale['amount_paid'] - 18.00) < 0.001,
      json_encode($sale));
check('charge id still written', !empty($sale['stripe_charge_id']), json_encode($sale));
check('the bogus field simply was not written', $sale['card_last4'] === null, var_export($sale['card_last4'], true));

$newLog = is_file($logFile) ? file_get_contents($logFile) : '';
$tail   = substr($newLog, $before);
check('the bad column is logged loudly, with the fix',
      strpos($tail, 'column that does not exist') !== false
      && strpos($tail, 'tools_check_sales_table.php') !== false,
      substr($tail, -300));

// And the ledger is intact, so a receipt reprint still has the card details.
$row = pos_payment_find_by_sale('4001');
check('last4 is still on the ledger row even though the sale column was bad',
      $row['card_last4'] === '4242', json_encode($row['card_last4']));

echo "\n=== BAD MAPPING: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
