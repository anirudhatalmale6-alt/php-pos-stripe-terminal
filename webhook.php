<?php
/**
 * Stripe webhook endpoint.
 * -----------------------------------------------------------------------------
 * The POS poll is what drives the cashier's screen. This endpoint is the
 * safety net: it writes the outcome into your database even when the till
 * closed the tab, lost WiFi, or the browser was killed mid-payment.
 *
 * Dashboard > Developers > Webhooks > Add endpoint
 *   URL:    https://your-pos-domain/path/webhook.php
 *   Events: terminal.reader.action_succeeded
 *           terminal.reader.action_failed
 *           payment_intent.succeeded
 *           payment_intent.payment_failed
 *           payment_intent.canceled
 * Then copy the whsec_... signing secret into config.php.
 *
 * Both this file and the poll go through the same finaliser, and every write is
 * gated on the event id + the row's current status, so whichever arrives second
 * changes nothing.
 */

require_once __DIR__ . '/lib/PosTerminal.php';

// Always answer 2xx for anything we have deliberately handled, otherwise
// Stripe retries for days over something we already dealt with.
$payload   = file_get_contents('php://input');
$sigHeader = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
$secret    = (string) pos_config('stripe_webhook_secret', '');

if ($secret === '' || strpos($secret, 'REPLACE') !== false) {
    pos_log('error', 'Webhook received but stripe_webhook_secret is not configured');
    http_response_code(500);
    echo 'webhook secret not configured';
    exit;
}

try {
    $event = StripeApi::verifyWebhook($payload, $sigHeader, $secret, 300);
} catch (Exception $e) {
    // Unsigned or tampered - refuse it. 400 keeps it out of our database.
    pos_log('error', 'Webhook rejected: ' . $e->getMessage());
    http_response_code(400);
    echo 'invalid signature';
    exit;
}

$eventId   = isset($event['id']) ? $event['id'] : '';
$eventType = isset($event['type']) ? $event['type'] : '';
$object    = isset($event['data']['object']) ? $event['data']['object'] : array();

pos_log('info', 'Webhook ' . $eventType, array('id' => $eventId));

// Replay / duplicate guard.
if ($eventId !== '' && pos_webhook_seen($eventId)) {
    pos_log('debug', 'Webhook already processed', array('id' => $eventId));
    http_response_code(200);
    echo 'duplicate';
    exit;
}

try {
    $stripe = new StripeApi();

    switch ($eventType) {

        // ------------------------------------------------------------------
        // Reader finished (or failed) collecting. The intent is authoritative,
        // so we reload it rather than trusting the event payload.
        // ------------------------------------------------------------------
        case 'terminal.reader.action_succeeded':
        case 'terminal.reader.action_failed':
            $action = isset($object['action']) ? $object['action'] : array();
            $intentId = null;
            if (isset($action['process_payment_intent']['payment_intent'])) {
                $pi = $action['process_payment_intent']['payment_intent'];
                $intentId = is_array($pi) ? (isset($pi['id']) ? $pi['id'] : null) : $pi;
            }
            if ($intentId === null) {
                // Not a payment action (cart display, setup intent, refund) - nothing to do.
                break;
            }

            $row = pos_payment_find_by_intent($intentId);
            if (!$row) {
                pos_log('info', 'Webhook for unknown intent', array('pi' => $intentId));
                break;
            }
            pos_payment_event($row['id'], 'webhook', $eventType,
                isset($action['failure_code']) ? $action['failure_code'] : null);

            if ($eventType === 'terminal.reader.action_failed') {
                $code = isset($action['failure_code']) ? $action['failure_code'] : 'failed';
                $msg  = isset($action['failure_message']) ? $action['failure_message'] : '';
                // Still confirm against the intent: a "failed" action with a
                // succeeded intent means the money moved.
                $intent = $stripe->retrievePaymentIntent($intentId);
                if (in_array($intent['status'], array('succeeded', 'requires_capture'), true)) {
                    pos_finalize_from_intent($row, $intent, 'webhook');
                } elseif ($row['status'] !== 'succeeded') {
                    pos_fail_payment($row['id'], $row['sale_id'], $code, $msg);
                }
            } else {
                $intent = $stripe->retrievePaymentIntent($intentId);
                pos_finalize_from_intent($row, $intent, 'webhook');
            }
            break;

        // ------------------------------------------------------------------
        // Intent-level events - the belt to the reader events' braces.
        // ------------------------------------------------------------------
        case 'payment_intent.succeeded':
        case 'payment_intent.payment_failed':
        case 'payment_intent.canceled':
            $intentId = isset($object['id']) ? $object['id'] : '';
            if ($intentId === '') {
                break;
            }
            $row = pos_payment_find_by_intent($intentId);
            if (!$row) {
                // Could be an online storefront payment, not a till one.
                pos_log('debug', 'Intent event not for a POS sale', array('pi' => $intentId));
                break;
            }
            pos_payment_event($row['id'], 'webhook', $eventType);

            if ($eventType === 'payment_intent.succeeded') {
                // Reload with the charge expanded so we get brand/last4.
                $intent = $stripe->retrievePaymentIntent($intentId);
                pos_finalize_from_intent($row, $intent, 'webhook');
            } elseif ($eventType === 'payment_intent.canceled') {
                if (!in_array($row['status'], array('succeeded', 'canceled'), true)) {
                    pos_fail_payment($row['id'], $row['sale_id'], 'canceled', 'Payment cancelled');
                }
            } else {
                $lpe  = isset($object['last_payment_error']) ? $object['last_payment_error'] : array();
                $code = !empty($lpe['decline_code']) ? $lpe['decline_code']
                      : (isset($lpe['code']) ? $lpe['code'] : 'card_declined');
                $msg  = isset($lpe['message']) ? $lpe['message'] : '';
                if ($row['status'] !== 'succeeded') {
                    pos_fail_payment($row['id'], $row['sale_id'], $code, $msg);
                }
            }
            break;

        default:
            pos_log('debug', 'Unhandled webhook type: ' . $eventType);
    }

    http_response_code(200);
    echo 'ok';
} catch (Exception $e) {
    // 500 makes Stripe retry with backoff, which is what we want for a
    // transient database or network problem. Release the duplicate marker
    // first, or that retry would be dropped as "already seen".
    if ($eventId !== '') {
        pos_webhook_unsee($eventId);
    }
    pos_log('error', 'Webhook handling failed: ' . $e->getMessage(), array('type' => $eventType, 'id' => $eventId));
    http_response_code(500);
    echo 'error';
}
