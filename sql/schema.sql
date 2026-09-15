-- -----------------------------------------------------------------------------
-- Stripe Terminal for POS - tables added by this integration (MySQL / MariaDB)
--
-- Nothing in your existing schema is altered by this file. These three tables
-- are the integration's own ledger; your sales table is only UPDATEd, using the
-- column names you set in config.php -> sale_writeback.
--
--   mysql -u root -p your_pos < sql/schema.sql
-- -----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS pos_card_payments (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sale_id           VARCHAR(64)     NOT NULL,             -- your ticket id
  payment_intent_id VARCHAR(64)     DEFAULT NULL,         -- pi_...
  reader_id         VARCHAR(64)     DEFAULT NULL,         -- tmr_...
  till              VARCHAR(64)     DEFAULT NULL,
  amount_minor      INT             NOT NULL DEFAULT 0,   -- 1250 = 12.50
  currency          VARCHAR(8)      NOT NULL DEFAULT 'usd',
  capture_method    VARCHAR(16)     NOT NULL DEFAULT 'automatic',
  -- random per-attempt Stripe idempotency key (never derived from sale_id)
  idem_key          VARCHAR(64)     DEFAULT NULL,
  -- pending | in_progress | succeeded | failed | canceled
  status            VARCHAR(16)     NOT NULL DEFAULT 'pending',
  reader_status     VARCHAR(16)     DEFAULT NULL,         -- in_progress|succeeded|failed
  attempt           INT             NOT NULL DEFAULT 1,
  charge_id         VARCHAR(64)     DEFAULT NULL,         -- ch_...
  card_last4        VARCHAR(4)      DEFAULT NULL,         -- safe to store
  card_brand        VARCHAR(32)     DEFAULT NULL,
  read_method       VARCHAR(32)     DEFAULT NULL,         -- contactless_emv | chip | swipe...
  auth_code         VARCHAR(32)     DEFAULT NULL,         -- EMV authorisation code for receipts
  refunded_minor    INT             NOT NULL DEFAULT 0,
  refund_id         VARCHAR(64)     DEFAULT NULL,
  failure_code      VARCHAR(64)     DEFAULT NULL,
  failure_message   VARCHAR(255)    DEFAULT NULL,
  started_at        DATETIME        DEFAULT NULL,
  finished_at       DATETIME        DEFAULT NULL,
  created_at        DATETIME        NOT NULL,
  updated_at        DATETIME        NOT NULL,
  PRIMARY KEY (id),
  -- THIS is what makes a double charge impossible: the row is the claim on an
  -- attempt, so two simultaneous "Card" clicks cannot both create a payment.
  UNIQUE KEY uniq_sale_attempt (sale_id, attempt),
  -- The poll and the webhook both look rows up by these two.
  KEY idx_sale   (sale_id),
  KEY idx_intent (payment_intent_id),
  KEY idx_reader_status (reader_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Append-only history of every state change, from both the poll and the
-- webhook. This is what you read when a cashier insists the reader said
-- approved - it has the exact sequence with timestamps.
CREATE TABLE IF NOT EXISTS pos_card_payment_events (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id BIGINT UNSIGNED NOT NULL,
  source     VARCHAR(16)     NOT NULL,          -- pos | poll | webhook
  event_type VARCHAR(64)     NOT NULL,
  detail     TEXT            DEFAULT NULL,
  created_at DATETIME        NOT NULL,
  PRIMARY KEY (id),
  KEY idx_payment (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stripe retries webhooks. The UNIQUE key on event_id is what makes replays
-- harmless.
CREATE TABLE IF NOT EXISTS pos_stripe_webhooks (
  event_id    VARCHAR(64) NOT NULL,
  received_at DATETIME    NOT NULL,
  PRIMARY KEY (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- -----------------------------------------------------------------------------
-- OPTIONAL: columns on your own sales table.
--
-- Only run these if your sales table does not already have somewhere to put
-- the result. If it does, just point config.php -> sale_writeback at your
-- existing column names instead and skip this entirely.
-- -----------------------------------------------------------------------------
-- ALTER TABLE sales
--   ADD COLUMN payment_status        VARCHAR(24) DEFAULT NULL,
--   ADD COLUMN payment_method        VARCHAR(24) DEFAULT NULL,
--   ADD COLUMN amount_paid           DECIMAL(10,2) DEFAULT NULL,
--   ADD COLUMN stripe_payment_intent VARCHAR(64) DEFAULT NULL,
--   ADD COLUMN stripe_charge_id      VARCHAR(64) DEFAULT NULL,
--   ADD COLUMN card_last4            VARCHAR(4)  DEFAULT NULL,
--   ADD COLUMN card_brand            VARCHAR(32) DEFAULT NULL,
--   ADD COLUMN paid_at               DATETIME    DEFAULT NULL;
