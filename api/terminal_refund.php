<?php
/**
 * POST api/terminal_refund.php   { sale_id: "1043", amount: 12.50 }
 * -----------------------------------------------------------------------------
 * Refunds a card-present charge back to the same card. Omit amount for a full
 * refund, or pass a smaller figure for a partial one.
 *
 * Not part of the original scope - included because a till that can charge a
 * card always ends up needing to give money back too.
 */

require_once __DIR__ . '/../lib/PosTerminal.php';

pos_require_auth();

$in     = pos_request_input();
$saleId = isset($in['sale_id']) ? trim((string) $in['sale_id']) : '';
if ($saleId === '') {
    pos_json_error('sale_id is required');
}

$row = pos_payment_find_by_sale($saleId);
if (!$row || $row['status'] !== 'succeeded' || empty($row['charge_id'])) {
    pos_json_error('No settled card charge found for sale ' . $saleId, 404);
}

$currency = $row['currency'];
$amountMinor = null;
if (isset($in['amount']) && $in['amount'] !== '') {
    $amountMinor = pos_to_minor_units($in['amount'], $currency);
    if ($amountMinor <= 0 || $amountMinor > (int) $row['amount_minor']) {
        pos_json_error('Refund amount must be between 0 and ' . pos_from_minor_units($row['amount_minor'], $currency));
    }
}

try {
    $stripe = new StripeApi();
    $refund = $stripe->refundCharge(
        $row['charge_id'],
        $amountMinor,
        // Keyed on the CHARGE, not the sale: charge ids are globally unique, so
        // a POS that reuses ticket numbers can never have one sale's refund
        // replayed against another's charge. (Stripe remembers a key for 24h.)
        'pos-refund-' . $row['charge_id'] . '-' . ($amountMinor === null ? 'full' : $amountMinor)
    );

    $refunded = (int) $refund['amount'];
    pos_payment_update($row['id'], array(
        'refunded_minor' => (int) $row['refunded_minor'] + $refunded,
        'refund_id'      => $refund['id'],
    ));
    pos_payment_event($row['id'], 'pos', 'refunded', array('refund' => $refund['id'], 'amount' => $refunded));

    pos_json_response(array(
        'ok'        => true,
        'status'    => isset($refund['status']) ? $refund['status'] : 'succeeded',
        'sale_id'   => $saleId,
        'refund_id' => $refund['id'],
        'amount'    => pos_from_minor_units($refunded, $currency),
        'currency'  => $currency,
        'message'   => 'Refunded ' . pos_from_minor_units($refunded, $currency) . ' ' . strtoupper($currency),
    ));
} catch (StripeApiException $e) {
    pos_json_error($e->getMessage(), 502, array('failure_code' => $e->stripeCode, 'sale_id' => $saleId));
} catch (Exception $e) {
    pos_json_error($e->getMessage(), 500, array('sale_id' => $saleId));
}
