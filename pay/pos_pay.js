/**
 * PosPay - opens the card payment window and hands you back the result.
 * -----------------------------------------------------------------------------
 * Include it once:
 *
 *   <script src="/pay/pos_pay.js"></script>
 *
 * Then your Card button is one call:
 *
 *   PosPay.open({
 *     saleId: 1043,
 *     amount: '12.50',
 *     onApproved: function (r) {
 *       // The sale row is ALREADY marked paid in the database at this point.
 *       // Do your POS-side finishing here: print the receipt, close the ticket.
 *       console.log(r.charge_id, r.brand, r.last4, r.auth_code);
 *       location.reload();
 *     },
 *     onDeclined: function (r) { alert(r.message); },
 *     onClosed:   function () { }   // window closed without a result
 *   });
 *
 * No framework, no build step, works in any browser your till already runs.
 */
var PosPay = (function () {
  'use strict';

  var DEFAULTS = {
    base:      '/pay/pay_window.php',
    token:     null,      // only if you are NOT using pay_window_guard.php
    till:      null,
    autostart: true,      // open straight into "Present card"
    width:     420,
    height:    640
  };

  function buildUrl(o) {
    var q = ['sale_id=' + encodeURIComponent(o.saleId)];
    if (o.amount !== null && o.amount !== undefined && o.amount !== '') {
      q.push('amount=' + encodeURIComponent(o.amount));
    }
    if (o.till)      { q.push('till=' + encodeURIComponent(o.till)); }
    if (o.token)     { q.push('token=' + encodeURIComponent(o.token)); }
    if (o.autostart) { q.push('autostart=1'); }
    return o.base + (o.base.indexOf('?') === -1 ? '?' : '&') + q.join('&');
  }

  function open(opts) {
    var o = {}, k;
    for (k in DEFAULTS) { if (DEFAULTS.hasOwnProperty(k)) { o[k] = DEFAULTS[k]; } }
    for (k in opts)     { if (opts.hasOwnProperty(k))     { o[k] = opts[k]; } }

    if (o.saleId === undefined || o.saleId === null || o.saleId === '') {
      throw new Error('PosPay.open needs a saleId');
    }

    // Centre it over the till screen.
    var left = Math.max(0, Math.round((window.screen.width  - o.width)  / 2));
    var top  = Math.max(0, Math.round((window.screen.height - o.height) / 2));

    var win = window.open(
      buildUrl(o),
      'pos_pay_' + o.saleId,
      'width=' + o.width + ',height=' + o.height + ',left=' + left + ',top=' + top +
      ',resizable=yes,scrollbars=yes'
    );

    if (!win) {
      // Popup blocker. Tell the cashier something useful instead of nothing.
      if (o.onError) { o.onError(new Error('The payment window was blocked - allow popups for this site.')); }
      else { alert('The payment window was blocked. Allow popups for this site and try again.'); }
      return null;
    }

    var done = false;

    function onMessage(ev) {
      // Only listen to our own window, on our own origin.
      if (ev.origin !== window.location.origin) { return; }
      if (!ev.data || ev.data.source !== 'pos_pay') { return; }
      done = true;
      window.removeEventListener('message', onMessage);
      clearInterval(watch);

      var r = ev.data.result || {};
      if (r.status === 'paid' && o.onApproved) { o.onApproved(r); }
      else if (o.onDeclined)                   { o.onDeclined(r); }
      if (o.onResult) { o.onResult(r); }
    }
    window.addEventListener('message', onMessage);

    // If the cashier closes the window with the X, we still want to know.
    var watch = setInterval(function () {
      if (win.closed) {
        clearInterval(watch);
        window.removeEventListener('message', onMessage);
        if (!done && o.onClosed) { o.onClosed(); }
      }
    }, 500);

    win.focus();
    return win;
  }

  return { open: open, defaults: DEFAULTS };
})();
