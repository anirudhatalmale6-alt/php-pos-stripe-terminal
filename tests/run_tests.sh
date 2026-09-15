#!/bin/sh
# -----------------------------------------------------------------------------
# One command to prove the whole integration works, with no Stripe keys and no
# hardware:
#
#   sh tests/run_tests.sh
#
# It starts a fake Stripe API and a PHP web server for this code, builds a
# throwaway SQLite database with a stand-in "sales" table, then drives every
# scenario over real HTTP: approved, declined, retry, double-click, cancel,
# reader busy, timeout guard, webhook (signed, unsigned, replayed) and refund.
# -----------------------------------------------------------------------------
set -e

ROOT=$(cd "$(dirname "$0")/.." && pwd)
WORK=${WORK_DIR:-$(mktemp -d)}
mkdir -p "$WORK"
MOCK_PORT=${MOCK_PORT:-8899}
APP_PORT=${APP_PORT:-8900}

DB="$WORK/pos_test.sqlite"
MOCK_STATE="$WORK/mock_state.json"
CONF="$WORK/config.test.php"
CONF_MANUAL="$WORK/config.manual.php"
CONF_TIMEOUT="$WORK/config.timeout.php"
CONF_BADMAP="$WORK/config.badmap.php"
SECRET="whsec_testsecret"
TOKEN="test-token-abc123"

echo "workdir: $WORK"

# --- database -----------------------------------------------------------------
rm -f "$DB"
sqlite3 "$DB" < "$ROOT/tests/schema.sqlite.sql" 2>/dev/null || \
  php -r '
    $db = $argv[1];
    $pdo = new PDO("sqlite:" . $db);
    $pdo->exec(file_get_contents($argv[2]));
  ' "$DB" "$ROOT/tests/schema.sqlite.sql"

# --- config files -------------------------------------------------------------
write_config() {
  target=$1
  capture=$2
  timeout=${3:-120}
  cat > "$target" <<PHPCONF
<?php
return array(
  'stripe_secret_key'     => 'sk_test_mock',
  'stripe_webhook_secret' => '$SECRET',
  'stripe_api_version'    => '2024-06-20',
  'stripe_api_base'       => 'http://127.0.0.1:$MOCK_PORT',
  'default_reader_id'     => 'tmr_mock001',
  'till_readers'          => array('TILL1' => 'tmr_mock001'),
  'currency'              => 'usd',
  'capture_method'        => '$capture',
  'amount_source'         => 'both',
  'max_amount'            => 5000.00,
  'tipping_enabled'       => false,
  'customer_cancellation' => true,
  'db' => array('dsn' => 'sqlite:$DB', 'user' => null, 'password' => null),
  'sale_lookup' => array('table' => 'sales', 'pk_column' => 'id', 'total_column' => 'total'),
  'sale_writeback' => array(
     'table' => 'sales', 'pk_column' => 'id',
     'amount_column' => 'amount_paid',
     'status_column' => 'payment_status',
     'status_paid' => 'paid', 'status_failed' => 'declined', 'status_pending' => 'awaiting_card',
     'method_column' => 'payment_method', 'method_value' => 'card_present',
     'charge_id_column' => 'stripe_charge_id', 'intent_id_column' => 'stripe_payment_intent',
     'last4_column' => 'card_last4', 'brand_column' => 'card_brand',
     'paid_at_column' => 'paid_at', 'guard_sql' => 'voided = 0',
  ),
  'api_token'       => '$TOKEN',
  'allowed_ips'     => array(),
  'payment_timeout' => $timeout,
  'http_timeout'    => 15,
  'http_retries'    => 1,
  'log_file'        => '$WORK/stripe_terminal.log',
  'log_level'       => 'debug',
);
PHPCONF
}
write_config "$CONF" automatic 120
write_config "$CONF_MANUAL" manual 120
write_config "$CONF_TIMEOUT" automatic 1
# Same as the main config but with two deliberately wrong column names, to
# prove a mapping typo cannot cost a payment.
sed -e "s/'last4_column' => 'card_last4'/'last4_column' => 'column_that_does_not_exist'/" \
    -e "s/'brand_column' => 'card_brand'/'brand_column' => 'another_missing_column'/" \
    "$CONF" > "$CONF_BADMAP"

# --- servers ------------------------------------------------------------------
# Logged to files and stopped by PID, never by process pattern.
MOCK_STATE="$MOCK_STATE" nohup php -S 127.0.0.1:$MOCK_PORT "$ROOT/tests/mock_stripe.php" \
  > "$WORK/mock.log" 2>&1 &
MOCK_PID=$!
POS_CONFIG_FILE="$CONF" nohup php -S 127.0.0.1:$APP_PORT -t "$ROOT" \
  > "$WORK/app.log" 2>&1 &
APP_PID=$!

cleanup() {
  kill "$MOCK_PID" 2>/dev/null || true
  kill "$APP_PID"  2>/dev/null || true
}
trap cleanup EXIT INT TERM

# Wait for both to answer before testing anything.
i=0
while [ $i -lt 40 ]; do
  if curl -s -o /dev/null "http://127.0.0.1:$MOCK_PORT/__mock/state" \
     && curl -s -o /dev/null "http://127.0.0.1:$APP_PORT/api/terminal_readers.php"; then
    break
  fi
  i=$((i+1))
  usleep 200000 2>/dev/null || php -r 'usleep(200000);'
done

# --- run ----------------------------------------------------------------------
RC=0
APP_BASE="http://127.0.0.1:$APP_PORT" \
MOCK_BASE="http://127.0.0.1:$MOCK_PORT" \
TEST_DB="$DB" WEBHOOK_SECRET="$SECRET" \
  php "$ROOT/tests/run_flow_test.php" || RC=$?

MOCK_BASE="http://127.0.0.1:$MOCK_PORT" \
POS_CONFIG_FILE="$CONF_MANUAL" \
  php "$ROOT/tests/run_manual_capture_test.php" || RC=$?

MOCK_BASE="http://127.0.0.1:$MOCK_PORT" \
POS_CONFIG_FILE="$CONF_TIMEOUT" \
  php "$ROOT/tests/run_timeout_test.php" || RC=$?

MOCK_BASE="http://127.0.0.1:$MOCK_PORT" \
POS_CONFIG_FILE="$CONF_BADMAP" \
  php "$ROOT/tests/run_bad_mapping_test.php" || RC=$?

echo ""
echo "server logs: $WORK/app.log  $WORK/mock.log"
echo "app log:     $WORK/stripe_terminal.log"
exit $RC
