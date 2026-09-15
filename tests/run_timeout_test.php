<?php
/**
 * Timeout scenario: the customer walks away and never presents a card.
 * The till must not hang - after payment_timeout seconds the reader prompt is
 * cancelled and the sale comes back as failed with reader_timeout, free to be
 * retried or paid in cash.
 *
 * Uses a config with payment_timeout = 1 (set by run_tests.sh).
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

echo "\n[timeout] nobody presents a card\n";

mock_post($mock . '/__mock/reset');
mock_post($mock . '/__mock/config', array('outcome' => 'never', 'polls' => 1));

$pdo = pos_db();
$pdo->prepare('DELETE FROM pos_card_payments WHERE sale_id = ?')->execute(array('3001'));
$pdo->prepare('DELETE FROM sales WHERE id = ?')->execute(array(3001));
$pdo->prepare('INSERT INTO sales (id, total, voided) VALUES (?,?,0)')->execute(array(3001, 8.00));

check('timeout is 1s in this config', (int) pos_config('payment_timeout') === 1);

$start = pos_start_card_payment('3001', array('amount' => '8.00'));
check('payment started', $start['status'] === 'in_progress', json_encode($start));

$mid = pos_check_card_payment('3001');
check('still waiting immediately after starting', $mid['status'] === 'in_progress', json_encode($mid));

sleep(2);

$final = pos_check_card_payment('3001');
check('times out instead of hanging', $final['status'] === 'failed', json_encode($final));
check('failure code is reader_timeout', isset($final['failure_code']) && $final['failure_code'] === 'reader_timeout',
      json_encode($final));
check('cashier is told to retry', !empty($final['can_retry']));

$st = $pdo->prepare('SELECT * FROM sales WHERE id = ?');
$st->execute(array(3001));
$sale = $st->fetch();
check('sale is NOT marked paid', $sale['payment_status'] === 'declined', json_encode($sale));
check('no amount written', $sale['amount_paid'] === null, var_export($sale['amount_paid'], true));

// And a fresh attempt after the timeout must work.
mock_post($mock . '/__mock/config', array('outcome' => 'approve', 'polls' => 1));
$retry = pos_start_card_payment('3001', array('amount' => '8.00', 'retry' => true));
check('retry after timeout starts cleanly', $retry['status'] === 'in_progress', json_encode($retry));
$after = null;
for ($i = 0; $i < 6; $i++) {
    $after = pos_check_card_payment('3001');
    if ($after['status'] !== 'in_progress') { break; }
    usleep(150000);
}
check('retry gets paid', $after['status'] === 'paid', json_encode($after));

echo "\n=== TIMEOUT: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
