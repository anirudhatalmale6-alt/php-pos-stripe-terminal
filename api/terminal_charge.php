<?php
/**
 * POST api/terminal_charge.php
 * -----------------------------------------------------------------------------
 * The "Tap Card" button. Creates the PaymentIntent and pushes it to the
 * WisePOS E, then returns immediately so your screen can start polling.
 *
 * Headers
 *   X-POS-Token: <api_token from config.php>
 *   Content-Type: application/json
 *
 * Body (JSON or form-encoded)
 *   sale_id      required  your ticket id
 *   amount       optional  12.50 - validated against the DB total (see amount_source)
 *   currency     optional  defaults to config currency
 *   reader_id    optional  tmr_... - defaults to config default_reader_id
 *   till         optional  workstation name, resolved through till_readers
 *   description  optional  shows on the Stripe dashboard / receipt
 *   retry        optional  1 = start a fresh attempt after a decline
 *   line_items   optional  [{description, amount, quantity}, ...] shown on the reader
 *
 * Response: the standard status object, status = in_progress|paid|failed
 */

require_once __DIR__ . '/../lib/PosTerminal.php';

pos_require_auth();

if (isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    pos_json_error('Use POST', 405);
}

$in = pos_request_input();

$saleId = isset($in['sale_id']) ? trim((string) $in['sale_id']) : '';
if ($saleId === '') {
    // Accept the common aliases so you do not have to rename anything in the POS.
    foreach (array('saleid', 'saleId', 'ticket_id', 'order_id') as $alias) {
        if (!empty($in[$alias])) {
            $saleId = trim((string) $in[$alias]);
            break;
        }
    }
}
if ($saleId === '') {
    pos_json_error('sale_id is required');
}

try {
    $result = pos_start_card_payment($saleId, array(
        'amount'       => isset($in['amount']) ? $in['amount'] : null,
        'currency'     => isset($in['currency']) ? $in['currency'] : null,
        'reader_id'    => isset($in['reader_id']) ? $in['reader_id'] : null,
        'till'         => isset($in['till']) ? $in['till'] : null,
        'description'  => isset($in['description']) ? $in['description'] : null,
        'customer'     => isset($in['customer']) ? $in['customer'] : null,
        'receipt_email'=> isset($in['receipt_email']) ? $in['receipt_email'] : null,
        'retry'        => !empty($in['retry']),
        'line_items'   => isset($in['line_items']) && is_array($in['line_items']) ? $in['line_items'] : null,
        'tax'          => isset($in['tax']) ? $in['tax'] : null,
    ));
    pos_json_response($result);
} catch (StripeApiException $e) {
    pos_json_error(pos_decline_message($e->stripeCode, $e->getMessage()), 502, array(
        'status'       => 'failed',
        'failure_code' => $e->stripeCode,
        'sale_id'      => $saleId,
    ));
} catch (Exception $e) {
    pos_json_error($e->getMessage(), 400, array('status' => 'failed', 'sale_id' => $saleId));
}
