<?php
/**
 * GET|POST api/terminal_status.php?sale_id=1043
 * -----------------------------------------------------------------------------
 * Called by the POS every ~1.5s after terminal_charge.php until status stops
 * being in_progress. No page refresh, no user action - just this one poll.
 *
 * Returns the same status object as terminal_charge.php:
 *   status = in_progress | paid | failed | canceled | none
 *
 * On 'paid' it also carries charge_id, amount, last4, brand, auth_code, and by
 * that point the sale row in your database has already been updated.
 */

require_once __DIR__ . '/../lib/PosTerminal.php';

pos_require_auth();

$in     = pos_request_input();
$saleId = isset($in['sale_id']) ? trim((string) $in['sale_id']) : '';
if ($saleId === '') {
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
    pos_json_response(pos_check_card_payment($saleId));
} catch (StripeApiException $e) {
    // A Stripe blip must not look like a decline - tell the till to keep waiting.
    pos_json_response(array(
        'ok'      => true,
        'status'  => 'in_progress',
        'sale_id' => $saleId,
        'message' => 'Waiting for the reader...',
        'warning' => $e->getMessage(),
        'poll_interval_ms' => 2000,
    ));
} catch (Exception $e) {
    pos_json_error($e->getMessage(), 500, array('sale_id' => $saleId));
}
