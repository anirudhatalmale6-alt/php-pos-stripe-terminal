<?php
/**
 * Demo till screen - development only.
 * -----------------------------------------------------------------------------
 * This is not part of your POS; it exists so you can see the whole flow working
 * (and so I could test it) before wiring the two calls into your own screen.
 * The JavaScript at the bottom is exactly what your real "Tap Card" button
 * needs to do: POST once, then poll. Copy it across and delete this folder.
 *
 * Open:  http://your-host/path/demo/pos_demo.php?sale_id=1001
 */

require_once dirname(__DIR__) . '/lib/Support.php';

$saleId   = isset($_GET['sale_id']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['sale_id']) : '1001';
$amount   = isset($_GET['amount']) ? (float) $_GET['amount'] : 12.50;
$token    = pos_config('api_token');
$currency = strtoupper(pos_config('currency', 'usd'));
$base     = '../api/';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>POS - Card payment demo</title>
<style>
  :root { --bg:#0f1220; --card:#191d33; --line:#2b3157; --ink:#e9ecff; --muted:#9aa2c8;
          --ok:#1ec98b; --bad:#ff5d6c; --wait:#f2b53c; --accent:#5b7cff; }
  * { box-sizing:border-box; }
  body { margin:0; font:15px/1.5 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
         background:var(--bg); color:var(--ink); display:flex; justify-content:center; padding:28px 16px; }
  .wrap { width:100%; max-width:760px; }
  h1 { font-size:18px; margin:0 0 4px; letter-spacing:.2px; }
  .sub { color:var(--muted); font-size:13px; margin-bottom:20px; }
  .grid { display:grid; grid-template-columns:1fr 300px; gap:18px; }
  @media (max-width:680px){ .grid { grid-template-columns:1fr; } }
  .card { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:18px; }
  .row { display:flex; justify-content:space-between; padding:7px 0; border-bottom:1px dashed #262c4d; font-size:14px; }
  .row:last-of-type { border-bottom:0; }
  .total { display:flex; justify-content:space-between; margin-top:12px; padding-top:12px;
           border-top:2px solid var(--line); font-size:20px; font-weight:600; }
  button { width:100%; padding:13px 16px; border:0; border-radius:9px; font-size:15px; font-weight:600;
           cursor:pointer; margin-top:10px; }
  .primary { background:var(--accent); color:#fff; }
  .ghost { background:transparent; color:var(--muted); border:1px solid var(--line); }
  button[disabled] { opacity:.45; cursor:not-allowed; }
  .state { margin-top:14px; padding:14px; border-radius:10px; border:1px solid var(--line); background:#141832; }
  .badge { display:inline-block; font-size:11px; letter-spacing:.8px; text-transform:uppercase;
           padding:3px 9px; border-radius:20px; font-weight:700; }
  .b-idle{ background:#2b3157; color:var(--muted); }
  .b-wait{ background:rgba(242,181,60,.16); color:var(--wait); }
  .b-ok  { background:rgba(30,201,139,.16); color:var(--ok); }
  .b-bad { background:rgba(255,93,108,.16); color:var(--bad); }
  .msg { margin-top:9px; font-size:15px; }
  .meta { margin-top:12px; font-size:12.5px; color:var(--muted); font-family:ui-monospace,Menlo,Consolas,monospace;
          white-space:pre-wrap; word-break:break-all; }
  .dots::after { content:''; animation:d 1.2s infinite; }
  @keyframes d { 0%{content:''} 25%{content:'.'} 50%{content:'..'} 75%{content:'...'} }
  .label { font-size:11px; text-transform:uppercase; letter-spacing:.7px; color:var(--muted); margin-bottom:8px; }
</style>
</head>
<body>
<div class="wrap">
  <h1>Sale #<?php echo htmlspecialchars($saleId); ?></h1>
  <div class="sub">Demo till screen - shows what your POS does with the two endpoints.</div>

  <div class="grid">
    <div class="card">
      <div class="label">Basket</div>
      <div class="row"><span>Flat white x2</span><span>7.00</span></div>
      <div class="row"><span>Almond croissant</span><span>3.60</span></div>
      <div class="row"><span>Tax</span><span>1.90</span></div>
      <div class="total"><span>Total</span><span id="total"><?php echo number_format($amount, 2); ?> <?php echo $currency; ?></span></div>

      <div class="state" id="state">
        <span class="badge b-idle" id="badge">Idle</span>
        <div class="msg" id="msg">Press Charge card to send this sale to the reader.</div>
        <div class="meta" id="meta"></div>
      </div>
    </div>

    <div class="card">
      <div class="label">Payment</div>
      <button class="primary" id="btnCharge">Charge card</button>
      <button class="ghost"  id="btnCancel" disabled>Cancel</button>
      <button class="ghost"  id="btnRetry" style="display:none">Try another card</button>
    </div>
  </div>
</div>

<script>
// -----------------------------------------------------------------------------
// This is the whole client side of the integration: one POST, then poll.
// -----------------------------------------------------------------------------
var SALE_ID = <?php echo json_encode($saleId); ?>;
var AMOUNT  = <?php echo json_encode(number_format($amount, 2, '.', '')); ?>;
var TOKEN   = <?php echo json_encode($token); ?>;   // keep this server-side in your POS
var API     = <?php echo json_encode($base); ?>;

var pollTimer = null;

function setState(kind, message, meta) {
  var badge = document.getElementById('badge');
  var names = { idle:'Idle', wait:'Waiting', ok:'Approved', bad:'Failed' };
  badge.className = 'badge b-' + kind;
  badge.textContent = names[kind] || kind;
  var msg = document.getElementById('msg');
  msg.className = 'msg' + (kind === 'wait' ? ' dots' : '');
  msg.textContent = message || '';
  document.getElementById('meta').textContent = meta || '';
}

function call(file, body, method) {
  var opts = {
    method: method || 'POST',
    headers: { 'X-POS-Token': TOKEN, 'Content-Type': 'application/json' }
  };
  var url = API + file;
  if ((method || 'POST') === 'GET') {
    url += '?' + new URLSearchParams(body).toString();
  } else {
    opts.body = JSON.stringify(body);
  }
  return fetch(url, opts).then(function (r) { return r.json(); });
}

function charge(isRetry) {
  document.getElementById('btnCharge').disabled = true;
  document.getElementById('btnRetry').style.display = 'none';
  document.getElementById('btnCancel').disabled = false;
  setState('wait', 'Sending sale to the reader');

  call('terminal_charge.php', {
    sale_id: SALE_ID,
    amount: AMOUNT,
    till: 'TILL1',
    retry: isRetry ? 1 : 0
  }).then(handle).catch(function (e) {
    setState('bad', 'Could not reach the POS server: ' + e);
    document.getElementById('btnCharge').disabled = false;
  });
}

function poll() {
  call('terminal_status.php', { sale_id: SALE_ID }, 'GET').then(handle).catch(function () {
    // A dropped poll is not a decline - just try again.
    pollTimer = setTimeout(poll, 2000);
  });
}

function handle(r) {
  if (r.status === 'in_progress') {
    setState('wait', r.message || 'Present card on the reader');
    pollTimer = setTimeout(poll, r.poll_interval_ms || 1500);
    return;
  }

  clearTimeout(pollTimer);
  document.getElementById('btnCancel').disabled = true;

  if (r.status === 'paid') {
    // The sale row in the database is ALREADY updated at this point.
    setState('ok', r.message || 'Approved',
      'amount   ' + r.amount + ' ' + (r.currency || '').toUpperCase() +
      '\ncard     ' + (r.brand || '') + ' ****' + (r.last4 || '') +
      '\ncharge   ' + (r.charge_id || '') +
      '\nauth     ' + (r.auth_code || '') +
      '\nread     ' + (r.read_method || ''));
    return;
  }

  setState('bad', r.message || r.error || 'Payment failed',
    r.failure_code ? 'code     ' + r.failure_code : '');
  document.getElementById('btnCharge').disabled = false;
  if (r.can_retry !== false) {
    document.getElementById('btnRetry').style.display = 'block';
  }
}

document.getElementById('btnCharge').onclick = function () { charge(false); };
document.getElementById('btnRetry').onclick  = function () { charge(true); };
document.getElementById('btnCancel').onclick = function () {
  clearTimeout(pollTimer);
  setState('wait', 'Cancelling on the reader');
  call('terminal_cancel.php', { sale_id: SALE_ID }).then(handle);
};
</script>
</body>
</html>
