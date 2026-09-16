<?php
/**
 * Demo: what your POS side looks like - development only.
 * -----------------------------------------------------------------------------
 * A stand-in for your sale screen with a Card button on it. The only lines that
 * matter are the PosPay.open() call in the script at the bottom; everything
 * else is here so the page has something to look at.
 *
 * Open:  /demo/till_button.php?sale_id=1001&amount=12.50
 */

require_once dirname(__DIR__) . '/lib/Support.php';

$saleId = isset($_GET['sale_id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['sale_id']) : '1001';
$amount = isset($_GET['amount']) ? number_format((float) $_GET['amount'], 2, '.', '') : '12.50';
// Your POS builds the URL server-side, so the token never sits in a page a
// customer could be looking at. Better still: use pay/pay_window_guard.php and
// drop the token entirely.
$token    = pos_config('api_token');
$currency = strtoupper(pos_config('currency', 'gbp'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Till - sale <?php echo htmlspecialchars($saleId); ?></title>
<style>
  :root { --bg:#0f1220; --panel:#191d33; --line:#2b3157; --ink:#e9ecff; --muted:#9aa2c8;
          --ok:#1ec98b; --bad:#ff5d6c; --accent:#5b7cff; }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--bg); color:var(--ink); padding:28px 16px;
         font:15px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
         display:flex; justify-content:center; }
  .wrap { width:100%; max-width:640px; }
  h1 { font-size:18px; margin:0 0 3px; }
  .sub { color:var(--muted); font-size:13px; margin-bottom:20px; }
  .card { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:18px; }
  .row { display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px dashed #262c4d; font-size:14px; }
  .total { display:flex; justify-content:space-between; margin-top:12px; padding-top:12px;
           border-top:2px solid var(--line); font-size:20px; font-weight:700; }
  button { padding:15px 16px; border:0; border-radius:10px; font-size:16px; font-weight:700;
           cursor:pointer; width:100%; margin-top:14px; background:var(--accent); color:#fff; }
  .out { margin-top:16px; padding:14px; border-radius:10px; border:1px solid var(--line);
         background:#141832; font-size:13.5px; white-space:pre-wrap;
         font-family:ui-monospace,Menlo,Consolas,monospace; color:var(--muted); min-height:54px; }
  .ok { color:var(--ok); } .bad { color:var(--bad); }
  .label { font-size:11px; text-transform:uppercase; letter-spacing:.7px; color:var(--muted); margin-bottom:8px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Sale #<?php echo htmlspecialchars($saleId); ?></h1>
  <div class="sub">Stand-in for your till screen. The Card button opens the payment window.</div>

  <div class="card">
    <div class="label">Basket</div>
    <div class="row"><span>Flat white x2</span><span>7.00</span></div>
    <div class="row"><span>Almond croissant</span><span>3.60</span></div>
    <div class="row"><span>Tax</span><span>1.90</span></div>
    <div class="total"><span>Total</span><span><?php echo $amount; ?> <?php echo $currency; ?></span></div>

    <button id="btnCard">Card</button>

    <div class="label" style="margin-top:18px">What the POS receives back</div>
    <div class="out" id="out">Nothing yet - press Card.</div>
  </div>
</div>

<script src="../pay/pos_pay.js"></script>
<script>
var out = document.getElementById('out');

document.getElementById('btnCard').onclick = function () {
  out.className = 'out';
  out.textContent = 'Payment window open...';

  PosPay.open({
    base:   '../pay/pay_window.php',
    saleId: <?php echo json_encode($saleId); ?>,
    amount: <?php echo json_encode($amount); ?>,
    token:  <?php echo json_encode($token); ?>,
    till:   'TILL1',

    onApproved: function (r) {
      // The sale row is already paid in the database by this point.
      out.className = 'out ok';
      out.textContent =
        'APPROVED\n' +
        'amount    ' + r.amount + ' ' + (r.currency || '').toUpperCase() + '\n' +
        'card      ' + (r.brand || '') + ' ****' + (r.last4 || '') + '\n' +
        'auth      ' + (r.auth_code || '-') + '\n' +
        'charge    ' + (r.charge_id || '') + '\n' +
        'intent    ' + (r.payment_intent_id || '') + '\n\n' +
        '-> print the receipt / close the ticket here';
    },

    onDeclined: function (r) {
      out.className = 'out bad';
      out.textContent =
        (r.status || 'failed').toUpperCase() + '\n' +
        'reason    ' + (r.message || '') + '\n' +
        'code      ' + (r.failure_code || '-') + '\n\n' +
        '-> sale left unpaid, take another card or cash';
    },

    onClosed: function () {
      out.className = 'out';
      out.textContent = 'Window closed with no result - sale untouched.';
    }
  });
};
</script>
</body>
</html>
