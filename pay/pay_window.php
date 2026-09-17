<?php
/**
 * The card payment window.
 * -----------------------------------------------------------------------------
 * A self-contained popup for your till. One line behind your "Card" button:
 *
 *   window.open('/pay/pay_window.php?sale_id=1043&amount=12.50',
 *               'pay', 'width=420,height=620');
 *
 * or use pay/pos_pay.js, which does the opening and hands you the result.
 *
 * What the window does, on its own:
 *   Idle            -> shows the sale and a Charge card button
 *   Sending         -> creates the intent and pushes it to the reader
 *   Waiting         -> "Present card", polling every 1.5s
 *   Approved        -> amount, brand, last 4, auth code, Complete button
 *   Declined        -> the reason, plus Try another card
 *
 * Pressing "Complete transaction" hands the result back to your POS window
 * (window.opener) and closes. It does NOT take the money - the money is taken
 * the moment the card is approved, which is what the reader tells the customer.
 * If you want the button to be the thing that takes the money, set
 * capture_method = 'manual' in config.php; then Approved means "authorised" and
 * Complete captures it. Say the word and I will switch it over.
 *
 * ---------------------------------------------------------------------------
 * ACCESS: this page can start a card payment, so it must not be open to the
 * world. It protects itself in one of two ways:
 *
 *   1. pay/pay_window_guard.php  - if that file exists it is included first.
 *      Put your own staff check in it, e.g.
 *          <?php session_start(); if (empty($_SESSION['user_id'])) { http_response_code(403); exit('Not logged in'); }
 *      This is the better option: the window is then protected by the same
 *      login as the rest of your POS.
 *
 *   2. Otherwise it requires ?token=<api_token from config.php>, which your POS
 *      adds when it builds the URL server-side.
 *
 * Either way, keep it on the till LAN - see allowed_ips in config.php.
 */

require_once dirname(__DIR__) . '/lib/PosTerminal.php';

// --- access control ----------------------------------------------------------
$guard = __DIR__ . '/pay_window_guard.php';
if (is_file($guard)) {
    require $guard;               // your own staff check
} else {
    $expected = (string) pos_config('api_token', '');
    $given    = isset($_GET['token']) ? (string) $_GET['token'] : '';
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(401);
        header('Content-Type: text/plain');
        echo "Not authorised.\n\n";
        echo "Either add ?token=<api_token> to this URL (your POS should build it\n";
        echo "server-side), or create pay/pay_window_guard.php with your own\n";
        echo "staff/session check in it.\n";
        exit;
    }
}
$ipAllowed = pos_config('allowed_ips', array());
if (!empty($ipAllowed) && !pos_ip_allowed(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', $ipAllowed)) {
    http_response_code(403);
    exit('Not allowed from this IP.');
}

// --- inputs ------------------------------------------------------------------
$saleId = '';
foreach (array('sale_id', 'saleid', 'ticket_id', 'order_id') as $k) {
    if (isset($_REQUEST[$k]) && $_REQUEST[$k] !== '') {
        $saleId = trim((string) $_REQUEST[$k]);
        break;
    }
}
$amount    = isset($_REQUEST['amount']) && $_REQUEST['amount'] !== '' ? $_REQUEST['amount'] : null;
$till      = isset($_REQUEST['till']) ? $_REQUEST['till'] : null;
$autostart = !empty($_REQUEST['autostart']);
$action    = isset($_GET['action']) ? $_GET['action'] : '';

// -----------------------------------------------------------------------------
// The window talks to itself. No POS token is ever exposed to the browser, and
// there is nothing extra to deploy or configure.
// -----------------------------------------------------------------------------
if ($action !== '') {
    if ($saleId === '') {
        pos_json_response(array('ok' => false, 'status' => 'error', 'message' => 'No sale id given.'), 400);
    }
    try {
        switch ($action) {
            case 'start':
            case 'retry':
                pos_json_response(pos_start_card_payment($saleId, array(
                    'amount' => $amount,
                    'till'   => $till,
                    'retry'  => ($action === 'retry'),
                )));
                break;

            case 'status':
                pos_json_response(pos_check_card_payment($saleId));
                break;

            case 'cancel':
                pos_json_response(pos_cancel_card_payment($saleId));
                break;

            default:
                pos_json_response(array('ok' => false, 'status' => 'error', 'message' => 'Unknown action.'), 400);
        }
    } catch (StripeApiException $e) {
        pos_json_response(array(
            'ok'      => false,
            'status'  => 'error',
            'message' => pos_decline_message($e->stripeCode, $e->getMessage()),
        ));
    } catch (Exception $e) {
        // e.g. sale not found, amount mismatch, no reader configured - show the
        // real reason, the cashier needs to know which it is.
        pos_json_response(array('ok' => false, 'status' => 'error', 'message' => $e->getMessage()));
    }
}

// --- the window itself -------------------------------------------------------
$currency = strtoupper(pos_config('currency', 'gbp'));
$selfUrl  = basename(__FILE__);
$qs       = array('sale_id' => $saleId);
if ($amount !== null) { $qs['amount'] = $amount; }
if ($till !== null)   { $qs['till'] = $till; }
if (!is_file($guard) && isset($_GET['token'])) { $qs['token'] = $_GET['token']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Card payment<?php echo $saleId !== '' ? ' - sale ' . htmlspecialchars($saleId) : ''; ?></title>
<style>
  /* ===========================================================================
     LOOK AND FEEL - THIS IS THE BIT TO EDIT
     ---------------------------------------------------------------------------
     Every size and colour in the window comes from the values below. Change a
     number or a colour here and the whole window follows - you never need to
     hunt through the CSS underneath.

     Sizes are in px. For a till with a big touchscreen at arm's length, try
     --size-headline:34px, --size-detail:19px, --size-amount:40px.

     Prefer not to edit this file at all? Create pay/pay_window_custom.css and
     put your overrides in there - it is loaded last and survives any update I
     send you. Example contents:

         :root {
           --size-headline: 34px;
           --ok: #0a7d3f;
           --bad: #c0132b;
         }
     =========================================================================== */
  :root {
    /* --- text sizes ------------------------------------------------------- */
    --size-badge:     11px;   /* the little IDLE / WAITING / APPROVED label */
    --size-headline:  21px;   /* the big status word: Approved, Declined... */
    --size-detail:    14px;   /* the explanation line under it              */
    --size-amount:    26px;   /* the total at the top                       */
    --size-button:    16px;   /* button text                                */
    --size-cardinfo:  13.5px; /* the card details block                     */

    /* --- status colours --------------------------------------------------- */
    --ok:     #1ec98b;        /* approved  */
    --bad:    #ff5d6c;        /* declined / cancelled */
    --wait:   #f2b53c;        /* waiting for the card */
    --accent: #5b7cff;        /* main buttons */

    /* --- background and text ---------------------------------------------- */
    --bg:     #0f1220;        /* window background */
    --panel:  #191d33;        /* the status panel  */
    --card:   #141832;        /* card details panel */
    --line:   #2b3157;        /* borders */
    --ink:    #e9ecff;        /* normal text */
    --muted:  #9aa2c8;        /* secondary text */

    /* --- weights, if you want them heavier/lighter ------------------------ */
    --weight-headline: 700;
    --weight-badge:    800;
  }
  /* ===================== end of the bit to edit ============================ */

  * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
  html,body { height:100%; }
  body {
    margin:0; background:var(--bg); color:var(--ink);
    font:15px/1.45 -apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;
    display:flex; flex-direction:column;
  }
  header { padding:14px 18px; border-bottom:1px solid var(--line); flex:0 0 auto; }
  header .sale { font-size:12px; letter-spacing:.7px; text-transform:uppercase; color:var(--muted); }
  header .amt { font-size:var(--size-amount); font-weight:700; margin-top:2px; }
  main { flex:1 1 auto; overflow-y:auto; padding:18px; display:flex; flex-direction:column; gap:14px; }
  footer { flex:0 0 auto; padding:14px 18px 18px; border-top:1px solid var(--line); display:flex; flex-direction:column; gap:9px; }

  .status { background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:20px 18px; text-align:center; }
  .dot { width:11px; height:11px; border-radius:50%; display:inline-block; margin-right:7px; vertical-align:middle; background:var(--muted); }
  .badge { font-size:var(--size-badge); letter-spacing:.9px; text-transform:uppercase; font-weight:var(--weight-badge); color:var(--muted); }
  .headline { font-size:var(--size-headline); font-weight:var(--weight-headline); margin-top:9px; }
  .detail { color:var(--muted); font-size:var(--size-detail); margin-top:7px; min-height:20px; }

  .s-idle .dot{ background:var(--muted); }
  .s-wait .dot{ background:var(--wait); animation:pulse 1.1s infinite; }
  .s-ok   .dot{ background:var(--ok); }
  .s-bad  .dot{ background:var(--bad); }
  .s-wait .badge{ color:var(--wait); } .s-ok .badge{ color:var(--ok); } .s-bad .badge{ color:var(--bad); }
  .s-ok .headline{ color:var(--ok); } .s-bad .headline{ color:var(--bad); }
  @keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.25} }

  .card-info { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:13px 15px; font-size:var(--size-cardinfo); }
  .card-info div { display:flex; justify-content:space-between; padding:3px 0; }
  .card-info span:first-child { color:var(--muted); }
  .card-info span:last-child { font-family:ui-monospace,Menlo,Consolas,monospace; text-align:right; word-break:break-all; }

  button {
    width:100%; padding:15px 16px; border:0; border-radius:10px; font-size:var(--size-button); font-weight:700;
    cursor:pointer; font-family:inherit;
  }
  .primary { background:var(--accent); color:#fff; }
  .go      { background:var(--ok); color:#06281c; }
  .ghost   { background:transparent; color:var(--muted); border:1px solid var(--line); }
  button[disabled] { opacity:.4; cursor:not-allowed; }
  .hide { display:none !important; }
</style>
<?php
// Your own overrides, loaded last so they win. Create pay/pay_window_custom.css
// and it is picked up automatically - no need to edit this file.
if (is_file(__DIR__ . '/pay_window_custom.css')) {
    echo '<style>' . "\n" . file_get_contents(__DIR__ . '/pay_window_custom.css') . "\n" . '</style>' . "\n";
}
?>
</head>
<body>

<header>
  <div class="sale">Sale <?php echo htmlspecialchars($saleId !== '' ? $saleId : '-'); ?></div>
  <div class="amt" id="amt"><?php echo $amount !== null ? htmlspecialchars(number_format((float) $amount, 2)) . ' ' . $currency : '&mdash;'; ?></div>
</header>

<main>
  <div class="status s-idle" id="statusBox">
    <div><span class="dot"></span><span class="badge" id="badge">Idle</span></div>
    <div class="headline" id="headline">Ready</div>
    <div class="detail" id="detail">Press Charge card to send this sale to the reader.</div>
  </div>

  <div class="card-info hide" id="cardInfo"></div>
</main>

<footer>
  <button class="primary" id="btnCharge">Charge card</button>
  <button class="go hide" id="btnComplete">Complete transaction</button>
  <button class="primary hide" id="btnRetry">Try another card</button>
  <button class="ghost" id="btnCancel">Cancel</button>
  <button class="ghost hide" id="btnClose">Close</button>
</footer>

<script>
(function () {
  var SALE_ID  = <?php echo json_encode($saleId); ?>;
  var BASE     = <?php echo json_encode($selfUrl . '?' . http_build_query($qs)); ?>;
  var AUTO     = <?php echo $autostart ? 'true' : 'false'; ?>;
  var CURRENCY = <?php echo json_encode($currency); ?>;

  var el = function (id) { return document.getElementById(id); };
  var timer = null, lastResult = null, finished = false;

  var buttons = {
    charge:   el('btnCharge'),
    complete: el('btnComplete'),
    retry:    el('btnRetry'),
    cancel:   el('btnCancel'),
    close:    el('btnClose')
  };

  function show(b, on) { b.classList[on ? 'remove' : 'add']('hide'); }

  function setState(kind, badge, headline, detail) {
    var box = el('statusBox');
    box.className = 'status s-' + kind;
    el('badge').textContent = badge;
    el('headline').textContent = headline;
    el('detail').textContent = detail || '';
  }

  function showCard(r) {
    var rows = [];
    if (r.amount)      { rows.push(['Amount', r.amount + ' ' + (r.currency || CURRENCY).toUpperCase()]); }
    if (r.brand || r.last4) { rows.push(['Card', ((r.brand || '').toUpperCase() + ' ****' + (r.last4 || '')).trim()]); }
    if (r.read_method) { rows.push(['Read', r.read_method.replace(/_/g, ' ')]); }
    if (r.auth_code)   { rows.push(['Auth code', r.auth_code]); }
    if (r.charge_id)   { rows.push(['Charge', r.charge_id]); }
    if (!rows.length) { return; }
    el('cardInfo').innerHTML = rows.map(function (kv) {
      return '<div><span>' + kv[0] + '</span><span>' + kv[1] + '</span></div>';
    }).join('');
    show(el('cardInfo'), true);
  }

  function call(action) {
    return fetch(BASE + '&action=' + action, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      cache: 'no-store'
    }).then(function (r) { return r.json(); });
  }

  // --- the one place every outcome is handled -------------------------------
  function handle(r) {
    lastResult = r;

    if (r.amount) {
      el('amt').textContent = r.amount + ' ' + (r.currency || CURRENCY).toUpperCase();
    }

    if (r.status === 'in_progress') {
      setState('wait', 'Waiting', 'Present card',
               r.message || 'Ask the customer to tap, insert or swipe');
      show(buttons.charge, false);
      show(buttons.cancel, true);
      timer = setTimeout(poll, r.poll_interval_ms || 1500);
      return;
    }

    clearTimeout(timer);

    if (r.status === 'paid') {
      finished = true;
      setState('ok', 'Approved', 'Approved', r.message || 'Payment taken');
      showCard(r);
      show(buttons.charge, false);
      show(buttons.retry, false);
      show(buttons.cancel, false);
      show(buttons.complete, true);
      buttons.complete.focus();
      return;
    }

    if (r.status === 'failed' || r.status === 'canceled' || r.status === 'error') {
      var isCancel = (r.status === 'canceled');
      setState('bad', isCancel ? 'Cancelled' : 'Declined',
               isCancel ? 'Cancelled' : 'Declined',
               r.message || r.error || 'The payment did not go through');
      show(buttons.charge, false);
      show(buttons.cancel, false);
      // can_retry is false for a card we must not ask for again.
      show(buttons.retry, r.can_retry !== false);
      show(buttons.close, true);
      return;
    }

    if (r.status === 'none') {
      setState('idle', 'Idle', 'Ready', 'Press Charge card to send this sale to the reader.');
      show(buttons.charge, true);
      show(buttons.cancel, true);
      return;
    }

    setState('idle', 'Idle', 'Ready', r.message || '');
    show(buttons.charge, true);
  }

  function poll() {
    call('status').then(handle)['catch'](function () {
      // A dropped poll is not a decline - keep waiting and try again.
      setState('wait', 'Waiting', 'Present card', 'Reconnecting...');
      timer = setTimeout(poll, 2500);
    });
  }

  function start(isRetry) {
    show(buttons.retry, false);
    show(buttons.close, false);
    show(buttons.charge, false);
    show(el('cardInfo'), false);
    setState('wait', 'Sending', 'Sending to reader', 'Waking the card reader...');
    call(isRetry ? 'retry' : 'start').then(handle)['catch'](function (e) {
      setState('bad', 'Error', 'Could not reach the till server', String(e));
      show(buttons.charge, true);
      show(buttons.close, true);
    });
  }

  /**
   * Hand the result back to the POS window and close.
   * Your POS gets the full result object: status, amount, charge_id, last4,
   * brand, auth_code. See pay/pos_pay.js.
   */
  function handBackAndClose(result) {
    try {
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage({ source: 'pos_pay', result: result }, window.location.origin);
      }
    } catch (e) { /* opener gone - nothing to tell */ }
    window.close();
    // If the browser refuses to close a window it did not open, say so rather
    // than leaving the cashier looking at a dead button.
    setTimeout(function () {
      setState('ok', 'Done', 'Done', 'You can close this window.');
      show(buttons.complete, false);
      show(buttons.close, true);
    }, 400);
  }

  buttons.charge.onclick   = function () { start(false); };
  buttons.retry.onclick    = function () { start(true); };
  buttons.complete.onclick = function () { handBackAndClose(lastResult); };
  buttons.close.onclick    = function () { handBackAndClose(lastResult); };

  buttons.cancel.onclick = function () {
    clearTimeout(timer);
    setState('wait', 'Cancelling', 'Cancelling', 'Clearing the reader...');
    call('cancel').then(handle)['catch'](function () { handBackAndClose(lastResult); });
  };

  // Closing the window mid-payment must not leave the reader prompting.
  window.addEventListener('beforeunload', function () {
    if (!finished && lastResult && lastResult.status === 'in_progress') {
      // keepalive so the request survives the window going away
      try {
        fetch(BASE + '&action=cancel', { method: 'POST', keepalive: true });
      } catch (e) {}
    }
  });

  if (SALE_ID === '') {
    setState('bad', 'Error', 'No sale id', 'Open this window with ?sale_id=... from your POS.');
    show(buttons.charge, false);
    show(buttons.cancel, false);
    show(buttons.close, true);
  } else if (AUTO) {
    start(false);
  } else {
    // Pick up a payment already in flight for this sale (window reopened).
    call('status').then(function (r) {
      if (r.status === 'in_progress' || r.status === 'paid') { handle(r); }
    })['catch'](function () {});
  }
})();
</script>
</body>
</html>
