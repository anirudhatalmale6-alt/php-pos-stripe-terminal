# Stripe Terminal card payments for a PHP POS

Drop-in PHP for taking card payments on a **BBPOS WisePOS E** (or any
Stripe-registered WiFi reader) straight from your existing till, and writing the
result back onto the sale.

- Plain PHP **7.0+**, no frameworks, **no Composer** — the Stripe calls are raw
  cURL against the REST API, with the API version pinned.
- Your POS only ever calls **two endpoints**: one to start the payment, one to
  poll it. No page refreshes, no user action in between.
- Status, amount, Stripe charge id, last 4 and card brand are written onto the
  sale record the moment the reader answers — by the poll, and again by the
  webhook if the till has gone away.
- Declines, cancellations, timeouts, a busy reader and double-clicks are all
  handled explicitly, not left to chance.

---

## 1. The flow

```
  Cashier presses "Card"
        │
        │  POST api/terminal_charge.php   { sale_id, amount }
        ▼
  ┌─────────────────────────────────────────────────────────────┐
  │ 1. amount read from YOUR sales table (and checked against   │
  │    the amount the POS sent)                                 │
  │ 2. PaymentIntent created  (card_present, metadata.sale_id)  │
  │ 3. intent handed to the reader:                             │
  │    POST /v1/terminal/readers/{tmr_…}/process_payment_intent │
  └─────────────────────────────────────────────────────────────┘
        │                                    WisePOS E lights up:
        │  { status: "in_progress" }         "Present card"
        ▼
  POS polls every 1.5s
        │  GET api/terminal_status.php?sale_id=…
        ▼
  ┌─────────────────────────────────────────────────────────────┐
  │ reads the reader action + the PaymentIntent                 │
  │ approved → (captures if manual) → writes the sale row       │
  │ declined → writes the failure, offers a retry               │
  └─────────────────────────────────────────────────────────────┘
        │
        ▼
  { status:"paid", amount, charge_id, last4, brand, auth_code }
  …and the sale row in your database is already updated.

  In parallel, Stripe calls webhook.php with the same outcome, so the sale is
  written even if the browser was closed mid-payment.
```

---

## 2. Files

| File | What it is |
|---|---|
| `config.sample.php` | copy to `config.php` and fill in — keys, reader, DB, column mapping |
| `api/terminal_charge.php` | **the "Tap Card" call.** Creates the intent, pushes it to the reader |
| `api/terminal_status.php` | **the poll.** Returns `in_progress` / `paid` / `failed` / `canceled` |
| `api/terminal_cancel.php` | cashier pressed Cancel |
| `api/terminal_refund.php` | refund a settled card sale (full or partial) |
| `api/terminal_readers.php` | setup/diagnostics: list readers, see if one is online |
| `webhook.php` | Stripe webhook receiver (signature-verified, replay-safe) |
| `lib/StripeApi.php` | the Stripe client (cURL, retries, webhook signature check) |
| `lib/PosTerminal.php` | the payment logic: start / poll / cancel / finalise |
| `lib/SaleWriteback.php` | **the only file tied to your schema** — maps the result onto your sale row |
| `lib/Db.php`, `lib/Support.php` | PDO + ledger helpers, config, logging, auth, money |
| `sql/schema.sql` | the 3 tables this integration adds (MySQL) |
| `tools_list_readers.php` | `php tools_list_readers.php` → your reader ids and their status |
| `tools_check_sales_table.php` | `php tools_check_sales_table.php` → reads your sales table and **writes the mapping for you** |
| `pay/pay_window.php` | **the card payment window.** Open it from your Card button; it does the whole payment and shows the status |
| `pay/pos_pay.js` | `PosPay.open({...})` — opens the window and hands your POS the result |
| `demo/till_button.php` | a stand-in till screen showing the Card button wired up |
| `demo/pos_demo.php` | an inline (no popup) version of the same flow |
| `tests/run_tests.sh` | full end-to-end suite with a fake Stripe API (no keys, no hardware) |
| `tests/run_sandbox_test.php` | the same flow against the REAL Stripe API in test mode, on a simulated reader |
| `tests/run_window_ui_test.py` | drives the payment window in a real browser (needs Playwright) |

---

## 3. Setup

### 3.1 Stripe side

1. **Dashboard → Terminal → Locations** — create a location for the shop.
2. **Dashboard → Terminal → Readers → Register reader** — register the WisePOS E
   (on the reader: swipe down → Settings → enter the pairing code). It must be
   on the same WiFi with outbound internet access.
3. **Developers → API keys** — copy the secret key (`sk_test_…` first,
   `sk_live_…` when you go live).
4. Run `php tools_list_readers.php` to get the `tmr_…` id and confirm the reader
   shows `online`.

### 3.2 Files

```sh
cp config.sample.php config.php
# fill in: stripe_secret_key, default_reader_id, db{}, api_token
php -r "echo bin2hex(random_bytes(24)), \"\n\";"   # generate api_token
mysql -u root -p your_pos < sql/schema.sql
mkdir -p logs && chmod 775 logs
```

Keep `config.php` and `logs/` outside anything web-served if you can; the
included `.gitignore` and the deny rules below cover the common case.

### 3.3 Column mapping — let the tool do it

Fill in `db{}` and the table name, then run:

```sh
php tools_check_sales_table.php
```

It reads your sales table, lists the columns, checks every column you have
mapped actually exists, and prints a ready-to-paste `sale_lookup` /
`sale_writeback` block built from your real column names. It writes nothing —
it only reads the table definition. Fix anything it flags `WRONG`, re-run until
it says `ALL GREEN`, and the write-back is wired.

It also copes with schemas that look nothing like the sample: given
`saleid / grand_total / pay_status / paid_amount / txn_id / cc_last4 / cardtype`
it suggests exactly those, and leaves a field `null` rather than guessing when
there is no sensible match.

`config.php → sale_writeback` is what that block fills in.
Set a column name to `null` and it is skipped, so you only fill in what you have:

```php
'sale_writeback' => array(
    'table'            => 'sales',
    'pk_column'        => 'id',
    'status_column'    => 'payment_status',
    'status_paid'      => 'paid',
    'status_failed'    => 'declined',
    'status_pending'   => 'awaiting_card',
    'amount_column'    => 'amount_paid',
    'charge_id_column' => 'stripe_charge_id',
    'last4_column'     => 'card_last4',
    'brand_column'     => 'card_brand',
    'paid_at_column'   => 'paid_at',
    'guard_sql'        => 'voided = 0',   // never touch a voided ticket
),
```

If closing a sale in your POS means more than an UPDATE (a payments child
table, stock movements, your own `close_ticket()` function), put it in
`pos_writeback_hook()` at the bottom of `lib/SaleWriteback.php` — it runs
inside the same transaction.

### 3.4 Webhook

**Developers → Webhooks → Add endpoint** → `https://your-pos/path/webhook.php`,
with these events:

```
terminal.reader.action_succeeded
terminal.reader.action_failed
payment_intent.succeeded
payment_intent.payment_failed
payment_intent.canceled
```

Copy the `whsec_…` signing secret into `config.php`.

The webhook is a safety net, not the primary path — the cashier's screen is
driven by the poll. Unsigned, wrongly-signed and replayed events are rejected,
and a duplicate delivery can never write the sale twice.

---

## 4. Wiring it into your POS

### Option A — the payment window (least work)

One line behind your existing Card button:

```html
<script src="/pay/pos_pay.js"></script>
<script>
document.getElementById('cardButton').onclick = function () {
  PosPay.open({
    saleId: <?php echo (int) $sale['id']; ?>,
    amount: '<?php echo number_format($sale['total'], 2); ?>',
    token:  '<?php echo POS_API_TOKEN; ?>',   // or use pay_window_guard.php and drop this

    onApproved: function (r) {
      // The sale row is ALREADY marked paid at this point.
      // Print the receipt / close the ticket here.
      location.reload();
    },
    onDeclined: function (r) { alert(r.message); },   // sale left unpaid
    onClosed:   function ()  { }                      // closed with no result
  });
};
</script>
```

The window handles the rest on its own:

```
 Idle        Ready. "Charge card" button.   (skipped if autostart is on)
 Sending     creating the intent, waking the reader
 Waiting     "Present card" - polling every 1.5s, Cancel available
 Approved    amount, brand, last 4, auth code, "Complete transaction"
 Declined    the reason in plain words, plus "Try another card"
```

**"Complete transaction" does not take the money.** The money is taken the
moment the card is approved — that is what the reader tells the customer. The
button hands the result back to your POS and closes the window. If you want the
button itself to take the money, set `capture_method => 'manual'` in
`config.php`: then Approved means *authorised*, and Complete captures it.

Protecting the window: create `pay/pay_window_guard.php` with your own staff
session check in it (best — same login as the rest of your POS), or let your POS
add `?token=<api_token>` when it builds the URL. `allowed_ips` applies here too.
Closing the window mid-payment cancels the reader prompt, so a live prompt is
never left on the counter.

### Option B — wire the two calls yourself

Two calls. That is the whole client side (from `demo/pos_demo.php`):

```js
// 1) cashier pressed "Card"
fetch('/api/terminal_charge.php', {
  method: 'POST',
  headers: { 'X-POS-Token': POS_TOKEN, 'Content-Type': 'application/json' },
  body: JSON.stringify({ sale_id: SALE_ID, amount: '12.50', till: 'TILL1' })
}).then(r => r.json()).then(handle);

// 2) then poll until it is no longer in_progress
function poll() {
  fetch('/api/terminal_status.php?sale_id=' + SALE_ID, { headers: { 'X-POS-Token': POS_TOKEN } })
    .then(r => r.json()).then(handle);
}

function handle(r) {
  if (r.status === 'in_progress') { setTimeout(poll, r.poll_interval_ms || 1500); return; }
  if (r.status === 'paid')        { showApproved(r); return; }   // sale row is already updated
  showFailed(r.message, r.can_retry);                            // r.failure_code has the reason
}
```

Or from PHP, if your till posts server-side:

```php
require_once '/path/to/lib/PosTerminal.php';

$res = pos_start_card_payment($saleId, array('amount' => 12.50, 'till' => 'TILL1'));
while ($res['status'] === 'in_progress') {
    sleep(1);
    $res = pos_check_card_payment($saleId);
}
// $res['status'] is now paid | failed | canceled
```

### Request / response

`POST api/terminal_charge.php` — header `X-POS-Token: <api_token>`

| field | | |
|---|---|---|
| `sale_id` | required | your ticket id (aliases `saleid`, `ticket_id`, `order_id` also accepted) |
| `amount` | optional | `12.50`; validated against the DB total |
| `currency`, `reader_id`, `till`, `description` | optional | |
| `retry` | optional | `1` = start a fresh attempt after a decline |
| `line_items` | optional | `[{description, amount, quantity}]` shown on the reader screen |

Every response, from every endpoint:

```json
{
  "ok": true,
  "status": "paid",
  "sale_id": "1043",
  "message": "Approved - VISA ****4242",
  "amount": "12.50",
  "currency": "usd",
  "charge_id": "ch_3Q…",
  "payment_intent_id": "pi_3Q…",
  "last4": "4242",
  "brand": "visa",
  "read_method": "contactless_emv",
  "auth_code": "123456"
}
```

`status` is one of `in_progress` · `paid` · `failed` · `canceled` · `none`.
On `failed` you also get `failure_code` and `can_retry`, and `message` is
already worded for the cashier (“Insufficient funds - ask for another card”).

---

## 5. What happens when things go wrong

| Situation | Behaviour |
|---|---|
| Card declined | `status: failed`, `failure_code`, cashier-ready message, `can_retry: true`. Sale left unpaid. |
| Customer never taps | After `payment_timeout` (120s) the reader prompt is cancelled and the sale fails with `reader_timeout`. The till never hangs. |
| Cashier double-clicks "Card" | Second click returns the **same** attempt — one intent, one prompt. The `UNIQUE (sale_id, attempt)` row *is* the claim, so even two tills racing on the same ticket cannot both create a payment. |
| POS reuses ticket numbers (daily counters, per-till numbering) | Safe. The Stripe idempotency key is a random per-attempt nonce, never derived from the sale id or amount — see the note in section 9. |
| Sale already paid, button pressed again | Returns the existing `paid` result. Never re-charges. |
| Reader busy with the last customer | Previous action is cancelled and the payment is retried automatically. |
| Browser closed mid-payment | The webhook writes the sale. Reopening the ticket shows the real state. |
| Network blip during the poll | Reported as still `in_progress`, never as a decline. |
| Stripe says approved but the DB write fails | Logged as `PAID BUT WRITE-BACK FAILED` with the charge id, still reported as paid, and the webhook retries the write. Money is never silently lost. |
| A column name in the mapping is wrong | That one field is skipped and logged loudly (pointing at `tools_check_sales_table.php`); the payment still completes and every other field is still written. A typo in config costs you a field, never a sale. The card details stay on the ledger row regardless, so a receipt reprint still works. |
| Reader "failed" but the intent succeeded | The PaymentIntent wins — if the money moved, the sale is marked paid. |
| Late failure event for a paid sale | Ignored. A paid sale is never downgraded. |
| POS sends a different amount than the DB | Refused before anything reaches the reader. |

Everything is logged to `logs/stripe_terminal.log`, and every state change is
also in `pos_card_payment_events` with a timestamp and its source (`poll` or
`webhook`) — which is what you read when a cashier insists the reader said
approved.

---

## 6. Testing

### Without keys or hardware

```sh
sh tests/run_tests.sh
```

Starts a fake Stripe API plus a PHP web server, builds a throwaway SQLite
database, and drives every scenario over real HTTP — approved, declined, retry,
double-click, cancel, busy reader, timeout, manual capture, webhook (signed /
unsigned / replayed / late), refund, auth, idempotency-key regression, and a
deliberately broken column mapping.
Current run: **105 checks, 0 failures.**

The payment window has its own browser test - it presses Card on a till page,
watches the popup through Idle → Waiting → Approved/Declined, presses the
button and checks the POS window receives the result:

```sh
python3 tests/run_window_ui_test.py     # needs Playwright
```

Current run: **34 checks, 0 failures.**

### Against the real Stripe API, still no hardware

Register a **simulated WisePOS E** (Dashboard → Terminal → Readers → Register →
“Simulated reader”, or `POST /v1/terminal/readers registration_code=simulated-wpe`),
put its `tmr_…` in a config with your `sk_test_…` key, then:

```sh
POS_CONFIG_FILE=/path/to/config.sandbox.php php tests/run_sandbox_test.php
```

Real PaymentIntents, real declines, real refunds — it presents the test cards on
the simulated reader itself, so nothing has to be tapped and the reader on the
counter is never disturbed. It refuses to run against a live key.
Current run: **37 checks, 0 failures.**

### With the physical reader, test mode

Point `default_reader_id` at the real `tmr_…`, keep the `sk_test_…` key, and tap
a real card — genuine taps, no real money. The sandbox script deliberately
*won't* drive a physical reader unattended; it tells you to point it at a
simulated one instead.

Then swap in the live key and the live `whsec_…`.

---

## 7. Security notes

- `api/*.php` requires the `X-POS-Token` header (constant-time compared) and can
  be restricted to your shop LAN with `allowed_ips`.
- The webhook verifies the `Stripe-Signature` HMAC with a 5-minute tolerance.
- No card number ever reaches your server or database — only brand, last 4,
  read method and the EMV authorisation code, which are all safe to store and
  are what a receipt needs. This is what keeps the POS out of PCI scope.
- The amount is taken from your database, not from the browser.
- Delete `demo/` and `tests/` before going live (`tests/mock_stripe.php` is a
  development tool and must never be deployed).

Add to Apache (or the nginx equivalent) so nothing sensitive is servable:

```apache
<FilesMatch "^(config\.php)$">
  Require all denied
</FilesMatch>
<Directory "logs">
  Require all denied
</Directory>
```

---

## 8. Going live checklist

- [ ] `sk_live_…` in `config.php`, live webhook endpoint added, live `whsec_…` in place
- [ ] reader registered against the **live** location (test and live readers are separate)
- [ ] `php tools_list_readers.php` shows the live reader `online`
- [ ] `max_amount` set to something sane for your shop
- [ ] `logs/` writable, and not web-accessible
- [ ] `demo/` and `tests/` removed
- [ ] one real card tapped end to end, and the sale row checked in the database

---

## 9. One trap worth knowing about

Stripe remembers an idempotency key for **24 hours** and replays the *original*
response for it. The first version of this code keyed the PaymentIntent on
`sale_id + attempt + amount`, which looks sensible and is quietly wrong: a POS
that reuses ticket numbers (daily counters, per-till numbering), or any sale
retried after the ledger row went away, gets handed back **yesterday's intent** —
already succeeded or cancelled — and the reader then refuses it with
`intent_invalid_state`. The till simply cannot charge that sale.

I only found it by running the sandbox suite twice in a row against real Stripe.

The fix, and the shape to keep:

- the Stripe key is a **random per-attempt nonce**, stored in
  `pos_card_payments.idem_key`, never derived from sale data;
- protection against a double charge comes from the database instead —
  `UNIQUE (sale_id, attempt)`, where inserting the row *is* claiming the attempt;
- refunds are keyed on the **charge id**, which is globally unique, for the same
  reason;
- and if an intent ever does come back unusable, the code mints a fresh one and
  prompts the reader with that rather than failing the sale.

`tests/run_flow_test.php` case 19 is the regression guard.
