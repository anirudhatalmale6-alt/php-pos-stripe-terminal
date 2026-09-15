<?php
/**
 * The payment flow itself.
 * -----------------------------------------------------------------------------
 * Three public functions are all your POS ever calls:
 *
 *   pos_start_card_payment($saleId, $opts)   cashier taps "Card" -> reader wakes up
 *   pos_check_card_payment($saleId)          called every ~1.5s until it stops
 *                                            returning in_progress
 *   pos_cancel_card_payment($saleId)         cashier presses Cancel
 *
 * Each one returns a plain array in the same shape, so the till only needs one
 * piece of screen logic:
 *
 *   array(
 *     'ok'        => true,
 *     'status'    => 'in_progress' | 'paid' | 'failed' | 'canceled',
 *     'sale_id'   => '1043',
 *     'message'   => 'Present card on the reader',   // safe to show as-is
 *     'amount'    => '12.50',
 *     'currency'  => 'usd',
 *     'charge_id' => 'ch_...',      // on success
 *     'last4'     => '4242',        // on success
 *     'brand'     => 'visa',        // on success
 *     'failure_code' => 'card_declined',   // on failure
 *   )
 *
 * Nothing here needs a page refresh: the reader is driven server-side and the
 * POS just polls the status endpoint (or listens for the webhook).
 */

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/StripeApi.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/SaleWriteback.php';

/**
 * Which reader should this sale go to?
 * Priority: explicit reader_id > till name from config map > default reader.
 */
function pos_resolve_reader($readerId = null, $till = null)
{
    if ($readerId !== null && $readerId !== '') {
        return $readerId;
    }
    $tills = pos_config('till_readers', array());
    if ($till !== null && $till !== '' && isset($tills[$till])) {
        return $tills[$till];
    }
    $default = pos_config('default_reader_id', '');
    if ($default === '' || strpos($default, 'REPLACE') !== false) {
        throw new RuntimeException('No reader configured. Set default_reader_id in config.php or send reader_id.');
    }
    return $default;
}

/**
 * Work out the authoritative amount, in minor units.
 *
 * With amount_source = 'db' or 'both' the figure comes out of your own sales
 * table, so a tampered POS request cannot change what gets charged. 'both'
 * additionally refuses to continue if the POS-sent total disagrees with the
 * database - that mismatch is nearly always a bug worth surfacing, not
 * something to silently paper over.
 */
function pos_resolve_amount($saleId, $requestedAmount = null, $currency = null)
{
    $source   = pos_config('amount_source', 'both');
    $currency = $currency ? strtolower($currency) : strtolower(pos_config('currency', 'usd'));
    $lookup   = pos_config('sale_lookup', array());

    $reqMinor = ($requestedAmount === null || $requestedAmount === '')
        ? null
        : pos_to_minor_units($requestedAmount, $currency);

    $dbMinor = null;
    if (($source === 'db' || $source === 'both') && !empty($lookup['table']) && !empty($lookup['total_column'])) {
        $sql = 'SELECT ' . $lookup['total_column'] . ' AS total FROM ' . $lookup['table']
             . ' WHERE ' . $lookup['pk_column'] . ' = ? LIMIT 1';
        $st = pos_db()->prepare($sql);
        $st->execute(array((string) $saleId));
        $row = $st->fetch();
        if (!$row) {
            throw new RuntimeException('Sale ' . $saleId . ' not found in ' . $lookup['table']);
        }
        $dbMinor = pos_to_minor_units($row['total'], $currency);
    }

    if ($source === 'request' || $dbMinor === null) {
        if ($reqMinor === null) {
            throw new RuntimeException('No amount given and none could be read from the database.');
        }
        $amount = $reqMinor;
    } elseif ($source === 'db') {
        $amount = $dbMinor;
    } else { // both
        if ($reqMinor !== null && $reqMinor !== $dbMinor) {
            throw new RuntimeException(sprintf(
                'Amount mismatch for sale %s: POS sent %s, database says %s. Nothing charged.',
                $saleId,
                pos_from_minor_units($reqMinor, $currency),
                pos_from_minor_units($dbMinor, $currency)
            ));
        }
        $amount = $dbMinor;
    }

    if ($amount <= 0) {
        throw new RuntimeException('Amount must be greater than zero.');
    }
    $max = pos_to_minor_units(pos_config('max_amount', 5000), $currency);
    if ($max > 0 && $amount > $max) {
        throw new RuntimeException(sprintf(
            'Amount %s is above the configured max_amount %s - refusing to charge.',
            pos_from_minor_units($amount, $currency),
            pos_from_minor_units($max, $currency)
        ));
    }
    return $amount;
}

// -----------------------------------------------------------------------------
// 1. START
// -----------------------------------------------------------------------------

/**
 * Create the PaymentIntent and push it to the reader.
 *
 * @param string $saleId your ticket / sale id
 * @param array  $opts   amount, currency, reader_id, till, description,
 *                       line_items, retry (bool), customer, receipt_email
 */
function pos_start_card_payment($saleId, array $opts = array())
{
    $saleId   = (string) $saleId;
    $currency = isset($opts['currency']) && $opts['currency'] !== ''
        ? strtolower($opts['currency'])
        : strtolower(pos_config('currency', 'usd'));

    $readerId = pos_resolve_reader(
        isset($opts['reader_id']) ? $opts['reader_id'] : null,
        isset($opts['till']) ? $opts['till'] : null
    );

    $stripe = new StripeApi();
    $retry  = !empty($opts['retry']);

    // --- Already got something running or finished for this sale? ----------
    $existing = pos_payment_find_by_sale($saleId);
    if ($existing) {
        if ($existing['status'] === 'succeeded') {
            // Hard stop. A sale that is already paid must never be charged
            // again because a cashier double-tapped the button.
            return pos_public_status($existing, 'This sale is already paid.');
        }
        if (in_array($existing['status'], array('pending', 'in_progress'), true) && !$retry) {
            // Same tap arriving twice - hand back the live attempt rather
            // than starting a second one on the reader.
            $fresh = pos_check_card_payment($saleId);
            return $fresh;
        }
    }

    $amountMinor = pos_resolve_amount($saleId, isset($opts['amount']) ? $opts['amount'] : null, $currency);
    $capture     = pos_config('capture_method', 'automatic');

    // --- Reuse or create the PaymentIntent ---------------------------------
    $intent = null;
    if ($existing && !empty($existing['payment_intent_id']) && in_array($existing['status'], array('pending','in_progress','failed'), true)) {
        try {
            $candidate = $stripe->retrievePaymentIntent($existing['payment_intent_id']);
            // After a decline Stripe puts the intent back to
            // requires_payment_method, which means it can simply be re-presented.
            $reusable = in_array($candidate['status'], array('requires_payment_method', 'requires_confirmation'), true)
                && (int) $candidate['amount'] === $amountMinor;
            if ($reusable) {
                $intent = $candidate;
                pos_log('info', 'Reusing PaymentIntent after retry', array('sale_id' => $saleId, 'pi' => $intent['id']));
            }
        } catch (StripeApiException $e) {
            pos_log('error', 'Could not reload previous intent: ' . $e->getMessage());
        }
    }

    if ($intent === null) {
        // attempt number keeps the idempotency key unique per retry, while
        // still collapsing accidental duplicate clicks of the same attempt.
        $attempt = $existing ? ((int) $existing['attempt'] + 1) : 1;
        $intent = $stripe->createPaymentIntent(
            $amountMinor,
            $currency,
            array(
                'sale_id' => $saleId,
                'source'  => 'pos',
                'till'    => isset($opts['till']) ? (string) $opts['till'] : '',
            ),
            array(
                'capture_method'  => $capture,
                'description'     => isset($opts['description']) && $opts['description'] !== ''
                    ? $opts['description']
                    : ('POS sale #' . $saleId),
                'customer'        => isset($opts['customer']) ? $opts['customer'] : null,
                'receipt_email'   => isset($opts['receipt_email']) ? $opts['receipt_email'] : null,
                'idempotency_key' => 'pos-sale-' . $saleId . '-a' . $attempt . '-' . $amountMinor,
            )
        );
    } else {
        $attempt = (int) $existing['attempt'] + 1;
    }

    // --- Persist our own row BEFORE touching the reader --------------------
    // If the next call dies mid-flight we still know an intent exists for this
    // sale, so the status endpoint / webhook can pick the outcome up later.
    if ($existing && $existing['status'] !== 'succeeded') {
        $paymentId = (int) $existing['id'];
        pos_payment_update($paymentId, array(
            'payment_intent_id' => $intent['id'],
            'reader_id'         => $readerId,
            'amount_minor'      => $amountMinor,
            'currency'          => $currency,
            'status'            => 'pending',
            'attempt'           => $attempt,
            'failure_code'      => null,
            'failure_message'   => null,
            'started_at'        => pos_now(),
        ));
    } else {
        $paymentId = pos_payment_insert(array(
            'sale_id'           => $saleId,
            'payment_intent_id' => $intent['id'],
            'reader_id'         => $readerId,
            'amount_minor'      => $amountMinor,
            'currency'          => $currency,
            'status'            => 'pending',
            'attempt'           => 1,
            'capture_method'    => $capture,
            'till'              => isset($opts['till']) ? (string) $opts['till'] : null,
            'started_at'        => pos_now(),
        ));
    }
    pos_payment_event($paymentId, 'pos', 'intent_created', array('pi' => $intent['id'], 'amount' => $amountMinor));

    // Mark the sale as awaiting the card, so any other screen looking at the
    // ticket can see a payment is in flight.
    pos_writeback_sale($saleId, array(
        'status'            => 'pending',
        'amount_minor'      => $amountMinor,
        'currency'          => $currency,
        'payment_intent_id' => $intent['id'],
    ));

    // --- Optional: show the basket on the reader screen -------------------
    if (!empty($opts['line_items']) && is_array($opts['line_items'])) {
        try {
            $items = array();
            foreach ($opts['line_items'] as $li) {
                $items[] = array(
                    'description' => isset($li['description']) ? substr($li['description'], 0, 26) : 'Item',
                    'amount'      => pos_to_minor_units(isset($li['amount']) ? $li['amount'] : 0, $currency),
                    'quantity'    => isset($li['quantity']) ? (int) $li['quantity'] : 1,
                );
            }
            $stripe->setReaderDisplay($readerId, $currency, $items, $amountMinor,
                isset($opts['tax']) ? pos_to_minor_units($opts['tax'], $currency) : null);
        } catch (StripeApiException $e) {
            // Cosmetic only - never block the payment because the cart display failed.
            pos_log('info', 'set_reader_display skipped: ' . $e->getMessage());
        }
    }

    // --- Hand the intent to the reader ------------------------------------
    $processConfig = array(
        'enable_customer_cancellation' => (bool) pos_config('customer_cancellation', true),
        'skip_tipping'                 => !pos_config('tipping_enabled', false),
    );
    if (pos_config('tipping_enabled', false)) {
        $processConfig['tipping'] = array('amount_eligible' => $amountMinor);
    }

    try {
        $reader = $stripe->processPaymentIntentOnReader($readerId, $intent['id'], $processConfig);
    } catch (StripeApiException $e) {
        // The reader still had a previous prompt up. Clear it and try once more -
        // this is the single most common real-world hiccup on a busy counter.
        if ($e->stripeCode === 'terminal_reader_busy' || $e->httpStatus === 409) {
            pos_log('info', 'Reader busy, cancelling previous action and retrying', array('reader' => $readerId));
            try {
                $stripe->cancelReaderAction($readerId);
                usleep(500000);
                $reader = $stripe->processPaymentIntentOnReader($readerId, $intent['id'], $processConfig);
            } catch (StripeApiException $e2) {
                return pos_fail_payment($paymentId, $saleId, $e2->stripeCode ? $e2->stripeCode : 'reader_error', $e2->getMessage());
            }
        } else {
            return pos_fail_payment($paymentId, $saleId, $e->stripeCode ? $e->stripeCode : 'reader_error', $e->getMessage());
        }
    }

    $action = isset($reader['action']) && is_array($reader['action']) ? $reader['action'] : array();
    pos_payment_update($paymentId, array(
        'status'        => 'in_progress',
        'reader_status' => isset($action['status']) ? $action['status'] : 'in_progress',
    ));
    pos_payment_event($paymentId, 'pos', 'reader_action_started', array('reader' => $readerId));

    pos_log('info', 'Card payment started', array(
        'sale_id' => $saleId, 'pi' => $intent['id'], 'reader' => $readerId, 'amount' => $amountMinor,
    ));

    return array(
        'ok'                => true,
        'status'            => 'in_progress',
        'sale_id'           => $saleId,
        'payment_id'        => $paymentId,
        'payment_intent_id' => $intent['id'],
        'reader_id'         => $readerId,
        'amount'            => pos_from_minor_units($amountMinor, $currency),
        'amount_minor'      => $amountMinor,
        'currency'          => $currency,
        'poll_interval_ms'  => 1500,
        'message'           => 'Present card on the reader',
    );
}

// -----------------------------------------------------------------------------
// 2. POLL
// -----------------------------------------------------------------------------

/**
 * Where is this payment up to? Safe to call as often as you like (~1.5s is
 * plenty). Returns a terminal status exactly once the card has been answered.
 */
function pos_check_card_payment($saleId)
{
    $row = pos_payment_find_by_sale($saleId);
    if (!$row) {
        return array('ok' => false, 'status' => 'none', 'sale_id' => (string) $saleId,
                     'message' => 'No card payment has been started for this sale.');
    }

    // Already settled - answer from our own row, no Stripe call needed.
    if (in_array($row['status'], array('succeeded', 'failed', 'canceled'), true)) {
        return pos_public_status($row);
    }

    $stripe = new StripeApi();

    // Give up on a prompt nobody is answering, so the till is never stuck.
    $timeout = (int) pos_config('payment_timeout', 120);
    $started = strtotime($row['started_at'] ? $row['started_at'] : $row['created_at']);
    if ($timeout > 0 && $started && (time() - $started) > $timeout) {
        try {
            $stripe->cancelReaderAction($row['reader_id']);
        } catch (StripeApiException $e) {
            pos_log('info', 'cancel_action during timeout: ' . $e->getMessage());
        }
        // Check the intent once more before declaring a timeout: the card may
        // have been approved in the same second we gave up.
        try {
            $intent = $stripe->retrievePaymentIntent($row['payment_intent_id']);
            if (in_array($intent['status'], array('succeeded', 'requires_capture', 'processing'), true)) {
                return pos_finalize_from_intent($row, $intent, 'timeout-check');
            }
        } catch (StripeApiException $e) {
            pos_log('error', 'Intent re-check during timeout failed: ' . $e->getMessage());
        }
        return pos_fail_payment($row['id'], $row['sale_id'], 'reader_timeout',
            'No card was presented in time.');
    }

    // --- Ask the reader what it is doing ----------------------------------
    $action = array();
    try {
        $reader = $stripe->retrieveReader($row['reader_id']);
        $action = isset($reader['action']) && is_array($reader['action']) ? $reader['action'] : array();
        $readerStatus = isset($action['status']) ? $action['status'] : '';
        if ($readerStatus !== '' && $readerStatus !== $row['reader_status']) {
            pos_payment_update($row['id'], array('reader_status' => $readerStatus));
            pos_payment_event($row['id'], 'poll', 'reader_' . $readerStatus,
                isset($action['failure_code']) ? $action['failure_code'] : null);
            $row['reader_status'] = $readerStatus;
        }
    } catch (StripeApiException $e) {
        // A hiccup reading the reader is not a decline. Keep the till waiting
        // and let the next poll (or the webhook) settle it.
        pos_log('error', 'Reader poll failed: ' . $e->getMessage());
        return pos_public_status($row, 'Waiting for the reader...');
    }

    // Is the reader still on OUR action? If it has moved on to another sale,
    // the PaymentIntent is still the source of truth, so we fall through.
    $actionIntentId = null;
    if (isset($action['process_payment_intent']['payment_intent'])) {
        $pi = $action['process_payment_intent']['payment_intent'];
        $actionIntentId = is_array($pi) ? (isset($pi['id']) ? $pi['id'] : null) : $pi;
    }
    $sameAction = ($actionIntentId !== null && $actionIntentId === $row['payment_intent_id']);

    if ($sameAction && isset($action['status']) && $action['status'] === 'in_progress') {
        return pos_public_status($row, 'Waiting for the customer to tap, insert or swipe');
    }

    if ($sameAction && isset($action['status']) && $action['status'] === 'failed') {
        // Reader-level failure (cancelled on the reader, card unreadable,
        // issuer decline surfaced by the reader...). Ask the intent for the
        // precise reason where possible.
        $code = isset($action['failure_code']) ? $action['failure_code'] : '';
        $msg  = isset($action['failure_message']) ? $action['failure_message'] : '';
        try {
            $intent = $stripe->retrievePaymentIntent($row['payment_intent_id']);
            if (!empty($intent['last_payment_error'])) {
                $lpe  = $intent['last_payment_error'];
                $code = !empty($lpe['decline_code']) ? $lpe['decline_code'] : (isset($lpe['code']) ? $lpe['code'] : $code);
                $msg  = isset($lpe['message']) ? $lpe['message'] : $msg;
            }
            if (in_array($intent['status'], array('succeeded', 'requires_capture'), true)) {
                // Rare, but the money is what matters: trust the intent.
                return pos_finalize_from_intent($row, $intent, 'poll');
            }
        } catch (StripeApiException $e) {
            pos_log('error', 'Intent lookup after reader failure: ' . $e->getMessage());
        }
        return pos_fail_payment($row['id'], $row['sale_id'], $code, $msg);
    }

    // Reader says succeeded (or has moved on): the intent decides.
    try {
        $intent = $stripe->retrievePaymentIntent($row['payment_intent_id']);
    } catch (StripeApiException $e) {
        return pos_public_status($row, 'Waiting for confirmation...');
    }
    return pos_finalize_from_intent($row, $intent, 'poll');
}

// -----------------------------------------------------------------------------
// 3. CANCEL
// -----------------------------------------------------------------------------

/**
 * Cashier pressed Cancel on the POS. Stops the reader prompt and releases the
 * intent so the sale can be paid another way.
 */
function pos_cancel_card_payment($saleId, $reason = 'requested_by_customer')
{
    $row = pos_payment_find_by_sale($saleId);
    if (!$row) {
        return array('ok' => false, 'status' => 'none', 'sale_id' => (string) $saleId,
                     'message' => 'Nothing to cancel for this sale.');
    }
    if ($row['status'] === 'succeeded') {
        return pos_public_status($row, 'Already paid - cancel is not possible, refund instead.');
    }

    $stripe = new StripeApi();

    try {
        $stripe->cancelReaderAction($row['reader_id']);
    } catch (StripeApiException $e) {
        pos_log('info', 'cancel_action: ' . $e->getMessage());
    }

    // Before cancelling the intent, make sure it did not just succeed.
    try {
        $intent = $stripe->retrievePaymentIntent($row['payment_intent_id']);
        if (in_array($intent['status'], array('succeeded', 'requires_capture'), true)) {
            return pos_finalize_from_intent($row, $intent, 'cancel-check');
        }
        if (!in_array($intent['status'], array('canceled'), true)) {
            $stripe->cancelPaymentIntent($row['payment_intent_id'], $reason);
        }
    } catch (StripeApiException $e) {
        pos_log('error', 'Cancel intent: ' . $e->getMessage());
    }

    pos_payment_update($row['id'], array(
        'status'          => 'canceled',
        'failure_code'    => 'canceled',
        'failure_message' => 'Cancelled at the till',
        'finished_at'     => pos_now(),
    ));
    pos_payment_event($row['id'], 'pos', 'canceled', $reason);

    pos_writeback_sale($row['sale_id'], array(
        'status'            => 'failed',
        'amount_minor'      => (int) $row['amount_minor'],
        'currency'          => $row['currency'],
        'payment_intent_id' => $row['payment_intent_id'],
        'failure_code'      => 'canceled',
        'failure_message'   => 'Cancelled at the till',
    ));

    return array(
        'ok'      => true,
        'status'  => 'canceled',
        'sale_id' => (string) $row['sale_id'],
        'message' => 'Payment cancelled',
    );
}

// -----------------------------------------------------------------------------
// Shared finaliser - used by the poll AND by the webhook, so both paths write
// exactly the same thing and neither can double-apply.
// -----------------------------------------------------------------------------

/**
 * Turn a PaymentIntent into a final POS status + sale write-back.
 *
 * @param array  $row    pos_card_payments row
 * @param array  $intent PaymentIntent (latest_charge expanded if available)
 * @param string $source 'poll' | 'webhook' | ...
 */
function pos_finalize_from_intent(array $row, array $intent, $source = 'poll')
{
    $status = isset($intent['status']) ? $intent['status'] : '';
    $stripe = new StripeApi();

    // Manual capture: the reader authorised it, now take the money.
    if ($status === 'requires_capture') {
        if (pos_config('capture_method', 'automatic') === 'manual' || $row['capture_method'] === 'manual') {
            try {
                $intent = $stripe->capturePaymentIntent(
                    $intent['id'],
                    null,
                    'pos-capture-' . $row['sale_id'] . '-' . $row['attempt']
                );
                $status = isset($intent['status']) ? $intent['status'] : $status;
                pos_payment_event($row['id'], $source, 'captured');
            } catch (StripeApiException $e) {
                pos_log('error', 'Capture failed: ' . $e->getMessage(), array('pi' => $intent['id']));
                return pos_fail_payment($row['id'], $row['sale_id'],
                    $e->stripeCode ? $e->stripeCode : 'capture_failed', $e->getMessage());
            }
        }
    }

    if ($status === 'succeeded') {
        return pos_succeed_payment($row, $intent, $source);
    }

    if ($status === 'processing') {
        pos_payment_update($row['id'], array('status' => 'in_progress'));
        return pos_public_status($row, 'Authorising with the bank...');
    }

    if ($status === 'canceled') {
        pos_payment_update($row['id'], array(
            'status'          => 'canceled',
            'failure_code'    => 'canceled',
            'failure_message' => 'Payment cancelled',
            'finished_at'     => pos_now(),
        ));
        $row['status'] = 'canceled';
        $row['failure_code'] = 'canceled';
        return pos_public_status($row, 'Payment cancelled');
    }

    if ($status === 'requires_payment_method') {
        // No card yet, or the last card was declined and Stripe has reset the
        // intent for another attempt. last_payment_error tells us which.
        if (!empty($intent['last_payment_error'])) {
            $lpe  = $intent['last_payment_error'];
            $code = !empty($lpe['decline_code']) ? $lpe['decline_code'] : (isset($lpe['code']) ? $lpe['code'] : 'card_declined');
            return pos_fail_payment($row['id'], $row['sale_id'], $code,
                isset($lpe['message']) ? $lpe['message'] : '');
        }
        return pos_public_status($row, 'Waiting for the customer to tap, insert or swipe');
    }

    // requires_confirmation / requires_action / anything new: keep waiting.
    return pos_public_status($row, 'Waiting for the reader...');
}

/**
 * Success path. Idempotent: the row is only flipped to succeeded once, so a
 * webhook arriving right after a poll cannot write the sale twice.
 */
function pos_succeed_payment(array $row, array $intent, $source = 'poll')
{
    $card    = pos_extract_card_details($intent);
    $paidMinor = isset($intent['amount_received']) && $intent['amount_received'] > 0
        ? (int) $intent['amount_received']
        : (int) $intent['amount'];
    $currency = isset($intent['currency']) ? $intent['currency'] : $row['currency'];

    $pdo = pos_db();
    $pdo->beginTransaction();
    try {
        // Re-read under the transaction and only apply if still open.
        $st = $pdo->prepare('SELECT status FROM pos_card_payments WHERE id = ?');
        $st->execute(array((int) $row['id']));
        $current = $st->fetchColumn();

        if ($current !== 'succeeded') {
            pos_payment_update($row['id'], array(
                'status'         => 'succeeded',
                'reader_status'  => 'succeeded',
                'charge_id'      => $card['charge_id'],
                'card_last4'     => $card['last4'],
                'card_brand'     => $card['brand'],
                'read_method'    => $card['read_method'],
                'auth_code'      => $card['auth_code'],
                'amount_minor'   => $paidMinor,
                'failure_code'   => null,
                'failure_message'=> null,
                'finished_at'    => pos_now(),
            ));
            pos_payment_event($row['id'], $source, 'succeeded', array(
                'charge' => $card['charge_id'], 'last4' => $card['last4'],
            ));

            pos_writeback_sale($row['sale_id'], array(
                'status'            => 'paid',
                'amount_minor'      => $paidMinor,
                'currency'          => $currency,
                'charge_id'         => $card['charge_id'],
                'payment_intent_id' => $intent['id'],
                'last4'             => $card['last4'],
                'brand'             => $card['brand'],
            ));
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        // The money HAS moved - never report a failure here. Log loudly and
        // report success; the webhook will retry the write-back.
        pos_log('error', 'PAID BUT WRITE-BACK FAILED for sale ' . $row['sale_id'] . ': ' . $e->getMessage(),
            array('pi' => $intent['id'], 'charge' => $card['charge_id']));
    }

    pos_log('info', 'Card payment approved', array(
        'sale_id' => $row['sale_id'], 'charge' => $card['charge_id'],
        'last4' => $card['last4'], 'source' => $source,
    ));

    return array(
        'ok'                => true,
        'status'            => 'paid',
        'sale_id'           => (string) $row['sale_id'],
        'payment_id'        => (int) $row['id'],
        'payment_intent_id' => $intent['id'],
        'charge_id'         => $card['charge_id'],
        'amount'            => pos_from_minor_units($paidMinor, $currency),
        'amount_minor'      => $paidMinor,
        'currency'          => $currency,
        'last4'             => $card['last4'],
        'brand'             => $card['brand'],
        'read_method'       => $card['read_method'],
        'auth_code'         => $card['auth_code'],
        'receipt_url'       => $card['receipt_url'],
        'message'           => 'Approved' . ($card['last4'] ? ' - ' . strtoupper($card['brand']) . ' ****' . $card['last4'] : ''),
    );
}

/**
 * Failure path (decline, cancel on reader, timeout, reader error).
 * The sale is left unpaid and the till is free to retry or take cash.
 */
function pos_fail_payment($paymentId, $saleId, $code, $message = '')
{
    $friendly = pos_decline_message($code, $message);

    $row = null;
    $st = pos_db()->prepare('SELECT * FROM pos_card_payments WHERE id = ?');
    $st->execute(array((int) $paymentId));
    $row = $st->fetch();

    if ($row && $row['status'] === 'succeeded') {
        // Never downgrade a paid sale on the back of a late failure event.
        return pos_public_status($row);
    }

    pos_payment_update($paymentId, array(
        'status'          => 'failed',
        'failure_code'    => $code !== '' ? $code : 'failed',
        'failure_message' => $friendly,
        'finished_at'     => pos_now(),
    ));
    pos_payment_event($paymentId, 'pos', 'failed', array('code' => $code, 'message' => $message));

    pos_writeback_sale($saleId, array(
        'status'            => 'failed',
        'amount_minor'      => $row ? (int) $row['amount_minor'] : 0,
        'currency'          => $row ? $row['currency'] : pos_config('currency', 'usd'),
        'payment_intent_id' => $row ? $row['payment_intent_id'] : null,
        'failure_code'      => $code,
        'failure_message'   => $friendly,
    ));

    pos_log('info', 'Card payment failed', array('sale_id' => $saleId, 'code' => $code));

    return array(
        'ok'           => true,     // the request worked; the card did not
        'status'       => 'failed',
        'sale_id'      => (string) $saleId,
        'payment_id'   => (int) $paymentId,
        'failure_code' => $code !== '' ? $code : 'failed',
        'message'      => $friendly,
        'can_retry'    => !in_array($code, array('pin_try_exceeded', 'lost_card', 'stolen_card', 'pickup_card'), true),
    );
}

/**
 * Pull brand / last4 / auth code out of the expanded charge.
 * These four fields are all we keep - no PAN ever reaches your database,
 * which is what keeps the POS out of PCI scope.
 */
function pos_extract_card_details(array $intent)
{
    $out = array(
        'charge_id'   => null,
        'last4'       => null,
        'brand'       => null,
        'read_method' => null,
        'auth_code'   => null,
        'receipt_url' => null,
    );

    $charge = null;
    if (isset($intent['latest_charge'])) {
        if (is_array($intent['latest_charge'])) {
            $charge = $intent['latest_charge'];
        } else {
            $out['charge_id'] = $intent['latest_charge'];
        }
    }
    // Older API versions return charges.data[] instead of latest_charge.
    if ($charge === null && isset($intent['charges']['data'][0])) {
        $charge = $intent['charges']['data'][0];
    }
    if ($charge === null) {
        return $out;
    }

    $out['charge_id']   = isset($charge['id']) ? $charge['id'] : $out['charge_id'];
    $out['receipt_url'] = isset($charge['receipt_url']) ? $charge['receipt_url'] : null;

    $cp = isset($charge['payment_method_details']['card_present'])
        ? $charge['payment_method_details']['card_present']
        : array();
    if (!empty($cp)) {
        $out['last4']       = isset($cp['last4']) ? $cp['last4'] : null;
        $out['brand']       = isset($cp['brand']) ? $cp['brand'] : null;
        $out['read_method'] = isset($cp['read_method']) ? $cp['read_method'] : null;
        if (isset($cp['receipt']['authorization_code'])) {
            $out['auth_code'] = $cp['receipt']['authorization_code'];
        }
    }
    return $out;
}

/**
 * Render a ledger row as the standard POS response.
 */
function pos_public_status(array $row, $message = null)
{
    $map = array(
        'pending'     => 'in_progress',
        'in_progress' => 'in_progress',
        'succeeded'   => 'paid',
        'failed'      => 'failed',
        'canceled'    => 'canceled',
    );
    $status = isset($map[$row['status']]) ? $map[$row['status']] : $row['status'];

    $defaults = array(
        'in_progress' => 'Waiting for the customer to tap, insert or swipe',
        'paid'        => 'Approved',
        'failed'      => $row['failure_message'] ? $row['failure_message'] : 'Payment failed',
        'canceled'    => 'Payment cancelled',
    );

    $res = array(
        'ok'                => true,
        'status'            => $status,
        'sale_id'           => (string) $row['sale_id'],
        'payment_id'        => (int) $row['id'],
        'payment_intent_id' => $row['payment_intent_id'],
        'reader_id'         => $row['reader_id'],
        'amount'            => pos_from_minor_units($row['amount_minor'], $row['currency']),
        'amount_minor'      => (int) $row['amount_minor'],
        'currency'          => $row['currency'],
        'attempt'           => (int) $row['attempt'],
        'poll_interval_ms'  => 1500,
        'message'           => $message !== null ? $message : (isset($defaults[$status]) ? $defaults[$status] : ''),
    );

    if ($status === 'paid') {
        $res['charge_id']   = $row['charge_id'];
        $res['last4']       = $row['card_last4'];
        $res['brand']       = $row['card_brand'];
        $res['read_method'] = $row['read_method'];
        $res['auth_code']   = $row['auth_code'];
    }
    if ($status === 'failed' || $status === 'canceled') {
        $res['failure_code'] = $row['failure_code'];
        $res['can_retry']    = !in_array($row['failure_code'],
            array('pin_try_exceeded', 'lost_card', 'stolen_card', 'pickup_card'), true);
    }
    return $res;
}
