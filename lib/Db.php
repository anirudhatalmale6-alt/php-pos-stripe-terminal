<?php
/**
 * PDO connection + the small ledger table this integration keeps.
 *
 * Two tables are added to your database (see sql/schema.sql):
 *   pos_card_payments        one row per card attempt on a sale
 *   pos_card_payment_events  append-only audit trail (every poll/webhook)
 *
 * Your own sales table is only ever UPDATEd, using the column names you set
 * in config.php -> sale_writeback. Nothing in your schema has to change.
 */

require_once __DIR__ . '/Support.php';

function pos_db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $cfg = pos_config('db');
    $pdo = new PDO(
        $cfg['dsn'],
        isset($cfg['user']) ? $cfg['user'] : null,
        isset($cfg['password']) ? $cfg['password'] : null,
        array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        )
    );
    return $pdo;
}

/** Portable "now" for both MySQL and SQLite. */
function pos_now()
{
    return date('Y-m-d H:i:s');
}

/**
 * The live payment row for a sale (there is at most one open attempt).
 */
function pos_payment_find_by_sale($saleId)
{
    $st = pos_db()->prepare('SELECT * FROM pos_card_payments WHERE sale_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute(array((string) $saleId));
    $row = $st->fetch();
    return $row ? $row : null;
}

function pos_payment_find_by_intent($intentId)
{
    $st = pos_db()->prepare('SELECT * FROM pos_card_payments WHERE payment_intent_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute(array((string) $intentId));
    $row = $st->fetch();
    return $row ? $row : null;
}

function pos_payment_find_by_reader_pending($readerId)
{
    $st = pos_db()->prepare(
        "SELECT * FROM pos_card_payments
          WHERE reader_id = ? AND status IN ('pending','in_progress')
          ORDER BY id DESC LIMIT 1"
    );
    $st->execute(array((string) $readerId));
    $row = $st->fetch();
    return $row ? $row : null;
}

/**
 * One specific attempt of one sale.
 */
function pos_payment_find_attempt($saleId, $attempt)
{
    $st = pos_db()->prepare('SELECT * FROM pos_card_payments WHERE sale_id = ? AND attempt = ? LIMIT 1');
    $st->execute(array((string) $saleId, (int) $attempt));
    $row = $st->fetch();
    return $row ? $row : null;
}

/**
 * Try to claim (sale_id, attempt) by inserting the ledger row.
 *
 * The UNIQUE key on those two columns is what makes this a claim: if two tills
 * or two clicks race, one INSERT succeeds and the other hits the constraint and
 * gets null back - so only one PaymentIntent is ever created for an attempt.
 *
 * @return int|null row id, or null if someone else already claimed it
 */
function pos_payment_claim_attempt(array $data)
{
    try {
        return pos_payment_insert($data);
    } catch (PDOException $e) {
        // 23000 = integrity constraint violation (MySQL and SQLite both).
        if ($e->getCode() === '23000' || stripos($e->getMessage(), 'unique') !== false) {
            pos_log('info', 'Attempt already claimed', array(
                'sale_id' => isset($data['sale_id']) ? $data['sale_id'] : '',
                'attempt' => isset($data['attempt']) ? $data['attempt'] : '',
            ));
            return null;
        }
        throw $e;
    }
}

function pos_payment_insert(array $data)
{
    $data['created_at'] = pos_now();
    $data['updated_at'] = pos_now();
    $cols = array_keys($data);
    $sql  = 'INSERT INTO pos_card_payments (' . implode(',', $cols) . ') VALUES ('
          . implode(',', array_fill(0, count($cols), '?')) . ')';
    $st = pos_db()->prepare($sql);
    $st->execute(array_values($data));
    return (int) pos_db()->lastInsertId();
}

function pos_payment_update($id, array $data)
{
    if (empty($data)) {
        return;
    }
    $data['updated_at'] = pos_now();
    $set = array();
    foreach (array_keys($data) as $c) {
        $set[] = $c . ' = ?';
    }
    $sql = 'UPDATE pos_card_payments SET ' . implode(', ', $set) . ' WHERE id = ?';
    $vals = array_values($data);
    $vals[] = (int) $id;
    pos_db()->prepare($sql)->execute($vals);
}

/**
 * Append-only history. Useful when a cashier swears "it said approved" - the
 * exact sequence of reader states is right here with timestamps.
 */
function pos_payment_event($paymentId, $source, $type, $detail = null)
{
    try {
        $st = pos_db()->prepare(
            'INSERT INTO pos_card_payment_events (payment_id, source, event_type, detail, created_at) VALUES (?,?,?,?,?)'
        );
        $st->execute(array(
            (int) $paymentId,
            (string) $source,
            (string) $type,
            $detail === null ? null : (is_string($detail) ? $detail : json_encode($detail)),
            pos_now(),
        ));
    } catch (Exception $e) {
        // Never let the audit trail break a payment.
        pos_log('error', 'Could not write payment event: ' . $e->getMessage());
    }
}

/**
 * Has this webhook event id already been handled? Stripe retries webhooks, and
 * the reader poll can land on the same transition, so every finaliser is gated
 * on this.
 */
function pos_webhook_seen($eventId)
{
    try {
        $st = pos_db()->prepare('INSERT INTO pos_stripe_webhooks (event_id, received_at) VALUES (?, ?)');
        $st->execute(array((string) $eventId, pos_now()));
        return false;
    } catch (PDOException $e) {
        // Unique key violation = we have processed this event before.
        return true;
    }
}

/**
 * Undo the mark above. Called when handling threw and we answer 500: Stripe
 * will retry the event, and without this the retry would be skipped as a
 * duplicate and the sale would never be written.
 */
function pos_webhook_unsee($eventId)
{
    try {
        $st = pos_db()->prepare('DELETE FROM pos_stripe_webhooks WHERE event_id = ?');
        $st->execute(array((string) $eventId));
    } catch (Exception $e) {
        pos_log('error', 'Could not clear webhook marker: ' . $e->getMessage());
    }
}
