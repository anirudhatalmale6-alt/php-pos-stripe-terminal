<?php
/**
 * POST api/terminal_cancel.php   { sale_id: "1043" }
 * -----------------------------------------------------------------------------
 * Cashier pressed Cancel. Clears the reader prompt and releases the intent.
 * Checks one last time that the card was not approved a split second earlier -
 * if it was, you get status 'paid' back instead of 'canceled'.
 */

require_once __DIR__ . '/../lib/PosTerminal.php';

pos_require_auth();

$in     = pos_request_input();
$saleId = isset($in['sale_id']) ? trim((string) $in['sale_id']) : '';
if ($saleId === '') {
    pos_json_error('sale_id is required');
}
$reason = isset($in['reason']) ? $in['reason'] : 'requested_by_customer';
$allowed = array('duplicate', 'fraudulent', 'requested_by_customer', 'abandoned');
if (!in_array($reason, $allowed, true)) {
    $reason = 'requested_by_customer';
}

try {
    pos_json_response(pos_cancel_card_payment($saleId, $reason));
} catch (Exception $e) {
    pos_json_error($e->getMessage(), 500, array('sale_id' => $saleId));
}
