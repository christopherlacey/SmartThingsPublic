-- The Lacey Ledger — tables for the Finance and Shopping tabs.
--
-- SQLite 3. Run once:
--   sqlite3 /var/lib/ledger/ledger.sqlite < schema.sqlite.sql
--
-- Running MySQL instead? Use schema.mysql.sql — the two dialects differ on
-- AUTOINCREMENT and on CREATE INDEX IF NOT EXISTS, so one file cannot serve
-- both. The columns are identical either way.
--
-- Nothing here touches the existing people/medical/trip tables.

-- ---------------------------------------------------------------- finance --

CREATE TABLE IF NOT EXISTS transactions (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,

  -- When the money actually moved (YYYY-MM-DD). Indexed: every view on the
  -- Finance tab groups or filters by this.
  posted_on     DATE           NOT NULL,

  -- Signed, in the home currency. Negative = money out, positive = money in.
  -- Stored in minor units (cents) so no rounding drift ever creeps in.
  amount_cents  BIGINT         NOT NULL,

  description   VARCHAR(255)   NOT NULL,
  merchant      VARCHAR(160)   DEFAULT NULL,
  category      VARCHAR(60)    DEFAULT NULL,
  account       VARCHAR(80)    DEFAULT NULL,

  -- Where the row came from: 'csv', 'manual', the name of a bank feed, etc.
  source        VARCHAR(60)    DEFAULT NULL,

  -- Stable per-transaction id from the source, when it has one. The unique
  -- index on it is what makes re-importing the same export a no-op.
  external_id   VARCHAR(128)   DEFAULT NULL,

  note          TEXT           DEFAULT NULL,
  created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tx_posted   ON transactions (posted_on);
CREATE INDEX IF NOT EXISTS idx_tx_category ON transactions (category);
CREATE INDEX IF NOT EXISTS idx_tx_merchant ON transactions (merchant);
CREATE UNIQUE INDEX IF NOT EXISTS idx_tx_external ON transactions (external_id);

-- --------------------------------------------------------------- shopping --

CREATE TABLE IF NOT EXISTS shopping_items (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  item         VARCHAR(160)  NOT NULL,
  quantity     VARCHAR(40)   DEFAULT NULL,

  -- Free text: Produce, Dairy, Hardware … drives the grouping on the tab.
  aisle        VARCHAR(60)   DEFAULT NULL,

  -- Which store this is for, when it matters.
  store        VARCHAR(80)   DEFAULT NULL,

  -- Who put it on the list, so "our list" shows whose hand it came from.
  added_by     VARCHAR(60)   DEFAULT NULL,

  needed       TINYINT       NOT NULL DEFAULT 1,   -- 0 once it is in the cart
  checked_at   TIMESTAMP     DEFAULT NULL,
  created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_shop_needed ON shopping_items (needed, aisle);
