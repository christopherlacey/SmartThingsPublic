-- The Lacey Ledger — tables for the Finance and Shopping tabs (MySQL 8).
--
--   mysql ledger < schema.mysql.sql
--
-- Same columns as schema.sqlite.sql; only the dialect differs. Nothing here
-- touches the existing people/medical/trip tables.

-- ---------------------------------------------------------------- finance --

CREATE TABLE IF NOT EXISTS transactions (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,

  -- When the money actually moved. Indexed: every view groups or filters on it.
  posted_on     DATE            NOT NULL,

  -- Signed, in minor units (cents) so no rounding drift ever creeps in.
  -- Negative = money out, positive = money in.
  amount_cents  BIGINT          NOT NULL,

  description   VARCHAR(255)    NOT NULL,
  merchant      VARCHAR(160)    DEFAULT NULL,
  category      VARCHAR(60)     DEFAULT NULL,
  account       VARCHAR(80)     DEFAULT NULL,
  source        VARCHAR(60)     DEFAULT NULL,

  -- Stable id from the source. The unique key is what makes re-importing the
  -- same export a no-op rather than a pile of duplicates.
  external_id   VARCHAR(128)    DEFAULT NULL,

  note          TEXT            DEFAULT NULL,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_tx_posted   (posted_on),
  KEY idx_tx_category (category),
  KEY idx_tx_merchant (merchant),
  UNIQUE KEY idx_tx_external (external_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- shopping --

CREATE TABLE IF NOT EXISTS shopping_items (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item         VARCHAR(160)    NOT NULL,
  quantity     VARCHAR(40)     DEFAULT NULL,
  aisle        VARCHAR(60)     DEFAULT NULL,
  store        VARCHAR(80)     DEFAULT NULL,
  added_by     VARCHAR(60)     DEFAULT NULL,
  needed       TINYINT         NOT NULL DEFAULT 1,   -- 0 once it is in the cart
  checked_at   TIMESTAMP       NULL DEFAULT NULL,
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY idx_shop_needed (needed, aisle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
