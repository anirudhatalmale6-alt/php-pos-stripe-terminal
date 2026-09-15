-- SQLite mirror of sql/schema.sql, used only by the test harness so the whole
-- flow can be proven without touching a MySQL server.
CREATE TABLE IF NOT EXISTS pos_card_payments (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  sale_id           TEXT NOT NULL,
  payment_intent_id TEXT,
  reader_id         TEXT,
  till              TEXT,
  amount_minor      INTEGER NOT NULL DEFAULT 0,
  currency          TEXT NOT NULL DEFAULT 'usd',
  capture_method    TEXT NOT NULL DEFAULT 'automatic',
  status            TEXT NOT NULL DEFAULT 'pending',
  reader_status     TEXT,
  attempt           INTEGER NOT NULL DEFAULT 1,
  charge_id         TEXT,
  card_last4        TEXT,
  card_brand        TEXT,
  read_method       TEXT,
  auth_code         TEXT,
  refunded_minor    INTEGER NOT NULL DEFAULT 0,
  refund_id         TEXT,
  failure_code      TEXT,
  failure_message   TEXT,
  started_at        TEXT,
  finished_at       TEXT,
  created_at        TEXT NOT NULL,
  updated_at        TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_sale   ON pos_card_payments (sale_id);
CREATE INDEX IF NOT EXISTS idx_intent ON pos_card_payments (payment_intent_id);

CREATE TABLE IF NOT EXISTS pos_card_payment_events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  payment_id INTEGER NOT NULL,
  source     TEXT NOT NULL,
  event_type TEXT NOT NULL,
  detail     TEXT,
  created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS pos_stripe_webhooks (
  event_id    TEXT PRIMARY KEY,
  received_at TEXT NOT NULL
);

-- Stand-in for the client's own sales table.
CREATE TABLE IF NOT EXISTS sales (
  id                    INTEGER PRIMARY KEY,
  total                 REAL NOT NULL,
  voided                INTEGER NOT NULL DEFAULT 0,
  payment_status        TEXT,
  payment_method        TEXT,
  amount_paid           REAL,
  stripe_payment_intent TEXT,
  stripe_charge_id      TEXT,
  card_last4            TEXT,
  card_brand            TEXT,
  paid_at               TEXT
);
