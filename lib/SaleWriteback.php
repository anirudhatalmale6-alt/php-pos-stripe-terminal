<?php
/**
 * The ONE file you may need to touch for your schema.
 * -----------------------------------------------------------------------------
 * Everything else is generic. This file maps a finished card payment onto your
 * own sale/ticket row, driven entirely by config.php -> sale_writeback.
 *
 * If your POS needs more than a column update (stock movements, a payments
 * child table, a stored procedure, an internal endpoint), put it in
 * pos_writeback_hook() at the bottom - it is called after the update with the
 * full result, inside the same transaction.
 */

require_once __DIR__ . '/Db.php';

/**
 * Write the outcome of a card attempt onto the sale row.
 *
 * @param string $saleId
 * @param array  $result keys: status ('paid'|'failed'|'pending'),
 *                       amount_minor, currency, charge_id, payment_intent_id,
 *                       last4, brand, failure_code, failure_message
 * @return bool true if a sale row was actually updated
 */
function pos_writeback_sale($saleId, array $result)
{
    $map = pos_config('sale_writeback', array());
    if (empty($map['table']) || empty($map['pk_column'])) {
        pos_log('error', 'sale_writeback.table / pk_column not configured - skipping sale update');
        return false;
    }

    $status = isset($result['status']) ? $result['status'] : 'pending';

    $set    = array();
    $params = array();

    // --- payment status -------------------------------------------------
    if (!empty($map['status_column'])) {
        $key = 'status_' . ($status === 'paid' ? 'paid' : ($status === 'failed' ? 'failed' : 'pending'));
        if (!empty($map[$key])) {
            $set[] = $map['status_column'] . ' = ?';
            $params[] = $map[$key];
        }
    }

    // --- amount, charge id, card details: only on success ---------------
    if ($status === 'paid') {
        if (!empty($map['amount_column'])) {
            $set[] = $map['amount_column'] . ' = ?';
            $params[] = pos_from_minor_units($result['amount_minor'], $result['currency']);
        }
        if (!empty($map['charge_id_column']) && !empty($result['charge_id'])) {
            $set[] = $map['charge_id_column'] . ' = ?';
            $params[] = $result['charge_id'];
        }
        if (!empty($map['last4_column']) && !empty($result['last4'])) {
            $set[] = $map['last4_column'] . ' = ?';
            $params[] = $result['last4'];
        }
        if (!empty($map['brand_column']) && !empty($result['brand'])) {
            $set[] = $map['brand_column'] . ' = ?';
            $params[] = $result['brand'];
        }
        if (!empty($map['method_column']) && !empty($map['method_value'])) {
            $set[] = $map['method_column'] . ' = ?';
            $params[] = $map['method_value'];
        }
        if (!empty($map['paid_at_column'])) {
            $set[] = $map['paid_at_column'] . ' = ?';
            $params[] = pos_now();
        }
    }

    // The intent id is useful on every outcome - it is how you find the
    // attempt in the Stripe dashboard, declines included.
    if (!empty($map['intent_id_column']) && !empty($result['payment_intent_id'])) {
        $set[] = $map['intent_id_column'] . ' = ?';
        $params[] = $result['payment_intent_id'];
    }

    if (empty($set)) {
        pos_log('info', 'Nothing mapped to write back for sale ' . $saleId);
        return false;
    }

    $sql = 'UPDATE ' . $map['table'] . ' SET ' . implode(', ', $set)
         . ' WHERE ' . $map['pk_column'] . ' = ?';
    if (!empty($map['guard_sql'])) {
        // e.g. "voided = 0" - never resurrect a cancelled ticket.
        $sql .= ' AND (' . $map['guard_sql'] . ')';
    }
    $params[] = (string) $saleId;

    $pdo = pos_db();
    $st  = $pdo->prepare($sql);
    $st->execute($params);
    $affected = $st->rowCount();

    pos_log('info', 'Sale write-back', array(
        'sale_id'  => $saleId,
        'status'   => $status,
        'affected' => $affected,
    ));

    pos_writeback_hook($saleId, $result, $affected);

    return $affected > 0;
}

/**
 * Extra things your POS may need after a successful payment.
 * Left deliberately empty - add what your system needs. Examples:
 *
 *   // 1) a payments child table
 *   $st = pos_db()->prepare('INSERT INTO sale_payments (sale_id, method, amount, ref) VALUES (?,?,?,?)');
 *   $st->execute(array($saleId, 'card', pos_from_minor_units($result['amount_minor'], $result['currency']), $result['charge_id']));
 *
 *   // 2) close the ticket through your own internal function
 *   require_once '/path/to/your/pos/includes/sales.php';
 *   pos_close_ticket($saleId);
 */
function pos_writeback_hook($saleId, array $result, $rowsAffected)
{
    // no-op by default
}
