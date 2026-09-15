<?php
/**
 * Setup tool: inspect YOUR sales table and build the config mapping for you.
 * -----------------------------------------------------------------------------
 *   php tools_check_sales_table.php
 *
 * It does three things and changes nothing:
 *   1. lists the columns of the table named in config.php (sale_writeback.table)
 *   2. checks every column you have mapped actually exists
 *   3. suggests a ready-to-paste mapping based on the column names it finds
 *
 * Run this once after filling in config.php. If it prints ALL GREEN you are
 * wired up; if it flags a column, fix the name and run it again. Nothing here
 * writes to your database - it only reads the table definition.
 *
 * Safe to run on a live system. Delete it before going live if you prefer.
 */

require_once __DIR__ . '/lib/PosTerminal.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    // Do not expose the schema of a live database to the open internet.
    pos_require_auth();
}

function out($line = '')
{
    echo $line . "\n";
}

/** Candidate column names, best guess first. */
function pos_mapping_candidates()
{
    return array(
        'status_column'    => array('payment_status', 'pay_status', 'paid_status', 'payment_state', 'status'),
        'amount_column'    => array('amount_paid', 'paid_amount', 'amount_tendered', 'total_paid', 'amount'),
        'method_column'    => array('payment_method', 'pay_method', 'payment_type', 'paytype', 'method'),
        'charge_id_column' => array('stripe_charge_id', 'charge_id', 'stripe_charge', 'transaction_id', 'txn_id', 'trans_id'),
        'intent_id_column' => array('stripe_payment_intent', 'payment_intent', 'payment_intent_id', 'stripe_intent'),
        'last4_column'     => array('card_last4', 'last4', 'card_last_four', 'cc_last4', 'card_number_last4'),
        'brand_column'     => array('card_brand', 'brand', 'card_type', 'cc_type', 'cardtype'),
        'paid_at_column'   => array('paid_at', 'payment_date', 'paid_date', 'date_paid', 'paid_on'),
    );
}

function pos_pk_candidates()
{
    return array('id', 'sale_id', 'sales_id', 'saleid', 'ticket_id', 'invoice_id', 'order_id');
}

function pos_total_candidates()
{
    return array('total', 'total_amount', 'grand_total', 'sale_total', 'amount_due', 'amount', 'net_total');
}

// pos_table_columns() / pos_column_exists() live in lib/Db.php so the
// write-back can use the same check at runtime.

// -----------------------------------------------------------------------------

$map    = pos_config('sale_writeback', array());
$lookup = pos_config('sale_lookup', array());
$table  = !empty($map['table']) ? $map['table'] : (!empty($lookup['table']) ? $lookup['table'] : 'sales');

out('Stripe Terminal POS - sales table check');
out(str_repeat('=', 78));
out('database: ' . pos_config('db')['dsn']);
out('table:    ' . $table);
out('');

try {
    $cols = pos_table_columns($table);
} catch (Exception $e) {
    out('COULD NOT READ THE TABLE: ' . $e->getMessage());
    out('');
    out('Check config.php -> db{} credentials, and that sale_writeback.table is');
    out('the right table name.');
    exit(1);
}

if (empty($cols)) {
    out('The table "' . $table . '" has no columns, or does not exist.');
    exit(1);
}

out('Columns found (' . count($cols) . '):');
foreach ($cols as $name => $meta) {
    out(sprintf('  %-28s %-22s %s%s', $name, $meta['type'],
        $meta['null'] ? 'NULL' : 'NOT NULL', $meta['pk'] ? '  [PK]' : ''));
}
out('');

// --- 1. validate what is configured now --------------------------------------
out('Checking your current mapping');
out(str_repeat('-', 78));

$problems = 0;
$checked  = 0;

$pk = !empty($map['pk_column']) ? $map['pk_column'] : (!empty($lookup['pk_column']) ? $lookup['pk_column'] : null);
if ($pk === null) {
    out('  MISSING  pk_column is not set');
    $problems++;
} else {
    $checked++;
    if (isset($cols[$pk])) {
        out(sprintf('  ok       pk_column           -> %s', $pk));
    } else {
        out(sprintf('  WRONG    pk_column           -> %s  (no such column)', $pk));
        $problems++;
    }
}

if (!empty($lookup['total_column'])) {
    $checked++;
    if (isset($cols[$lookup['total_column']])) {
        out(sprintf('  ok       sale total (read)   -> %s', $lookup['total_column']));
    } else {
        out(sprintf('  WRONG    sale total (read)   -> %s  (no such column)', $lookup['total_column']));
        $problems++;
    }
} else {
    out('  note     sale_lookup.total_column not set - the amount will come from');
    out('           the POS request instead of being verified against the database');
}

foreach (pos_mapping_candidates() as $key => $_) {
    if (empty($map[$key])) {
        out(sprintf('  skip     %-19s not mapped (that field will not be written)', $key));
        continue;
    }
    $checked++;
    if (isset($cols[$map[$key]])) {
        out(sprintf('  ok       %-19s -> %s', $key, $map[$key]));
    } else {
        out(sprintf('  WRONG    %-19s -> %s  (no such column)', $key, $map[$key]));
        $problems++;
    }
}

if (!empty($map['guard_sql'])) {
    try {
        pos_db()->query('SELECT 1 FROM ' . $table . ' WHERE (' . $map['guard_sql'] . ') LIMIT 1');
        out('  ok       guard_sql           -> ' . $map['guard_sql']);
    } catch (Exception $e) {
        out('  WRONG    guard_sql           -> ' . $map['guard_sql'] . '  (' . $e->getMessage() . ')');
        $problems++;
    }
}

out('');

// --- 2. suggest a mapping from the real column names -------------------------
$suggest = array();
foreach (pos_mapping_candidates() as $key => $candidates) {
    foreach ($candidates as $c) {
        if (isset($cols[$c])) {
            $suggest[$key] = $c;
            break;
        }
    }
}
$suggestPk = null;
foreach ($cols as $name => $meta) {
    if ($meta['pk']) {
        $suggestPk = $name;
        break;
    }
}
if ($suggestPk === null) {
    foreach (pos_pk_candidates() as $c) {
        if (isset($cols[$c])) { $suggestPk = $c; break; }
    }
}
$suggestTotal = null;
foreach (pos_total_candidates() as $c) {
    if (isset($cols[$c])) { $suggestTotal = $c; break; }
}

out('Suggested mapping, built from the columns above');
out(str_repeat('-', 78));
out("'sale_lookup' => array(");
out("    'table'        => '" . $table . "',");
out("    'pk_column'    => " . ($suggestPk ? "'" . $suggestPk . "'," : "null,   // <-- tell me your primary key"));
out("    'total_column' => " . ($suggestTotal ? "'" . $suggestTotal . "'," : "null,   // <-- the sale total column"));
out('),');
out("'sale_writeback' => array(");
out("    'table'            => '" . $table . "',");
out("    'pk_column'        => " . ($suggestPk ? "'" . $suggestPk . "'," : "null,"));
foreach (pos_mapping_candidates() as $key => $_) {
    if (isset($suggest[$key])) {
        out(sprintf("    %-18s => '%s',", "'" . $key . "'", $suggest[$key]));
    } else {
        out(sprintf("    %-18s => null,   // no obvious match - leave null to skip, or name it", "'" . $key . "'"));
    }
}
out("    'status_paid'      => 'paid',");
out("    'status_failed'    => 'declined',");
out("    'status_pending'   => 'awaiting_card',");
out("    'method_value'     => 'card_present',");
out("    'guard_sql'        => '',");
out('),');
out('');

// --- 3. verdict ---------------------------------------------------------------
out(str_repeat('=', 78));
if ($problems === 0) {
    out('ALL GREEN - ' . $checked . ' mapped columns all exist. The write-back will work.');
    out('');
    out('Unmapped fields are simply skipped, so if you want the charge id or last 4');
    out('stored and they show as "skip" above, add those columns (see sql/schema.sql)');
    out('or point them at columns you already have.');
    exit(0);
}
out($problems . ' PROBLEM(S) - fix the names flagged WRONG/MISSING in config.php, then re-run.');
out('Nothing is written to a column that does not exist, so a payment will still');
out('go through - but the fields flagged above would silently not be recorded.');
exit(1);
