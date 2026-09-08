-- Chris Lacey's Dashboard — schema
--
-- One SQLite file holds everything. It contains medical, financial and personal
-- records, so it lives OUTSIDE the web root (see config.php: LEDGER_DB) and the
-- deploy script checks that it is not reachable over HTTP.
--
-- Every table that a human edits carries a mirror row in `changes`, which is what
-- the Change log tab reads. Nothing is deleted in place; rows are closed with an
-- `ended_on` date so history stays intact.

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

-- ---------------------------------------------------------------- identity --

-- Single-user auth. There is exactly one row; `id` is pinned to 1 so a second
-- account cannot be created by accident.
CREATE TABLE IF NOT EXISTS account (
  id             INTEGER PRIMARY KEY CHECK (id = 1),
  username       TEXT    NOT NULL,
  password_hash  TEXT    NOT NULL,
  totp_secret    TEXT,                 -- reserved; not yet enforced
  created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
  password_set_at TEXT   NOT NULL DEFAULT (datetime('now'))
);

-- Single-use codes for getting back in when the authenticator is gone. Only
-- their hashes are stored; the plaintext exists once, at the moment they are
-- generated. Without these, turning on a second factor is one lost phone away
-- from being locked out of your own medical records.
CREATE TABLE IF NOT EXISTS recovery_codes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  code_hash  TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  used_at    TEXT
);

-- Login throttling. Rows are pruned on successful login and by age.
CREATE TABLE IF NOT EXISTS login_attempts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  ip         TEXT NOT NULL,
  ok         INTEGER NOT NULL DEFAULT 0,
  at         TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_at ON login_attempts (ip, at);

-- ------------------------------------------------------------------ people --

CREATE TABLE IF NOT EXISTS people (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL,
  relationship TEXT,                   -- 'self', 'daughter', 'friend', ...
  is_self      INTEGER NOT NULL DEFAULT 0,
  city         TEXT,
  region        TEXT,
  country      TEXT,
  -- How much to trust `city`/`region`: confirmed | inferred | stale
  location_confidence TEXT NOT NULL DEFAULT 'stale'
    CHECK (location_confidence IN ('confirmed','inferred','stale')),
  location_seen_at TEXT,
  notes        TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_people_name ON people (name);

-- Free-form per-person facts ("Everything on file" on the person page).
CREATE TABLE IF NOT EXISTS person_fields (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  person_id  INTEGER NOT NULL REFERENCES people (id) ON DELETE CASCADE,
  section    TEXT NOT NULL DEFAULT 'General',
  field      TEXT NOT NULL,
  value      TEXT,
  -- Sensitive fields are masked in the UI until explicitly revealed.
  sensitive  INTEGER NOT NULL DEFAULT 0,
  source     TEXT,
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_person_fields_person ON person_fields (person_id, section);

-- ---------------------------------------------------------------- the day --

-- One row per day: the written brief that heads the Now tab.
CREATE TABLE IF NOT EXISTS days (
  day        TEXT PRIMARY KEY,          -- YYYY-MM-DD
  brief      TEXT,
  mood       TEXT,
  written_at TEXT
);

CREATE TABLE IF NOT EXISTS events (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  day        TEXT NOT NULL,
  starts_at  TEXT,                      -- HH:MM, null = all day
  ends_at    TEXT,
  title      TEXT NOT NULL,
  location   TEXT,
  person_id  INTEGER REFERENCES people (id) ON DELETE SET NULL,
  kind       TEXT NOT NULL DEFAULT 'event'
    CHECK (kind IN ('event','appointment','work','travel','reminder')),
  source     TEXT
);
CREATE INDEX IF NOT EXISTS idx_events_day ON events (day, starts_at);

-- ------------------------------------------------------------- where I am --

-- Known places, used to turn raw coordinates into "at Harris Teeter".
CREATE TABLE IF NOT EXISTS places (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  kind       TEXT NOT NULL DEFAULT 'place'
    CHECK (kind IN ('home','work','store','pharmacy','clinic','gym','place')),
  lat        REAL,
  lon        REAL,
  radius_m   INTEGER NOT NULL DEFAULT 150,
  -- Shopping-list items tagged with this store surface when you arrive here.
  store_tag  TEXT,
  address    TEXT
);

-- Append-only breadcrumb trail. The newest row is "where I am now".
CREATE TABLE IF NOT EXISTS location_pings (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  at         TEXT NOT NULL DEFAULT (datetime('now')),
  lat        REAL,
  lon        REAL,
  accuracy_m REAL,
  place_id   INTEGER REFERENCES places (id) ON DELETE SET NULL,
  -- 'arrive' / 'depart' when the sender knows; 'ping' otherwise.
  transition TEXT NOT NULL DEFAULT 'ping'
    CHECK (transition IN ('ping','arrive','depart')),
  label      TEXT,                      -- sender-supplied place name, if any
  battery    INTEGER,
  source     TEXT NOT NULL DEFAULT 'unknown'
);
CREATE INDEX IF NOT EXISTS idx_location_pings_at ON location_pings (at DESC);

-- --------------------------------------------------------------- errands --

CREATE TABLE IF NOT EXISTS shopping_items (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  item       TEXT NOT NULL,
  qty        TEXT,
  -- Matches places.store_tag, so arriving at a store raises its items.
  store_tag  TEXT,
  category   TEXT,
  urgent     INTEGER NOT NULL DEFAULT 0,
  added_at   TEXT NOT NULL DEFAULT (datetime('now')),
  bought_at  TEXT,
  note       TEXT
);
CREATE INDEX IF NOT EXISTS idx_shopping_open ON shopping_items (bought_at, store_tag);

-- ---------------------------------------------------------------- health --

CREATE TABLE IF NOT EXISTS medications (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  person_id   INTEGER NOT NULL REFERENCES people (id) ON DELETE CASCADE,
  name        TEXT NOT NULL,
  dose        TEXT,
  schedule    TEXT,
  purpose     TEXT,
  prescriber  TEXT,
  pharmacy    TEXT,
  started_on  TEXT,
  ended_on    TEXT,                     -- set instead of deleting
  refill_due  TEXT,
  note        TEXT
);
CREATE INDEX IF NOT EXISTS idx_medications_person ON medications (person_id, ended_on);

-- Numeric health readings, one row per measurement. `metric` is free text so a
-- new thing to track needs no migration; `unit` travels with the value.
CREATE TABLE IF NOT EXISTS vitals (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  person_id INTEGER NOT NULL REFERENCES people (id) ON DELETE CASCADE,
  metric    TEXT NOT NULL,              -- 'weight', 'bp_systolic', 'a1c', ...
  value     REAL NOT NULL,
  unit      TEXT,
  measured_on TEXT NOT NULL,            -- YYYY-MM-DD
  source    TEXT,
  note      TEXT
);
CREATE INDEX IF NOT EXISTS idx_vitals_lookup ON vitals (person_id, metric, measured_on);

CREATE TABLE IF NOT EXISTS appointments (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  person_id  INTEGER NOT NULL REFERENCES people (id) ON DELETE CASCADE,
  what       TEXT NOT NULL,
  provider   TEXT,
  place      TEXT,
  on_day     TEXT NOT NULL,
  at_time    TEXT,
  status     TEXT NOT NULL DEFAULT 'scheduled'
    CHECK (status IN ('scheduled','done','cancelled','missed')),
  note       TEXT
);
CREATE INDEX IF NOT EXISTS idx_appointments_day ON appointments (on_day);

CREATE TABLE IF NOT EXISTS conditions (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  person_id   INTEGER NOT NULL REFERENCES people (id) ON DELETE CASCADE,
  name        TEXT NOT NULL,
  kind        TEXT NOT NULL DEFAULT 'condition'
    CHECK (kind IN ('condition','allergy','surgery','immunization')),
  detail      TEXT,
  severity    TEXT,
  noted_on    TEXT,
  resolved_on TEXT
);

-- ----------------------------------------------------------------- money --

CREATE TABLE IF NOT EXISTS accounts (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  name         TEXT NOT NULL,
  institution  TEXT,
  kind         TEXT NOT NULL DEFAULT 'checking'
    CHECK (kind IN ('checking','savings','credit','loan','investment','cash')),
  -- Last four only. Full numbers do not belong in this database.
  last4        TEXT,
  -- Liabilities (credit, loan) count against net worth.
  is_liability INTEGER NOT NULL DEFAULT 0,
  closed_on    TEXT
);

CREATE TABLE IF NOT EXISTS balances (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER NOT NULL REFERENCES accounts (id) ON DELETE CASCADE,
  on_day     TEXT NOT NULL,
  amount     REAL NOT NULL,
  source     TEXT
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_balances_unique ON balances (account_id, on_day);

CREATE TABLE IF NOT EXISTS transactions (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  account_id INTEGER REFERENCES accounts (id) ON DELETE SET NULL,
  on_day     TEXT NOT NULL,
  merchant   TEXT,
  category   TEXT,
  -- Negative = money out, positive = money in.
  amount     REAL NOT NULL,
  note       TEXT,
  source     TEXT
);
CREATE INDEX IF NOT EXISTS idx_transactions_day ON transactions (on_day DESC);
CREATE INDEX IF NOT EXISTS idx_transactions_cat ON transactions (category, on_day);

CREATE TABLE IF NOT EXISTS bills (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  name        TEXT NOT NULL,
  amount      REAL,
  due_day     INTEGER,                  -- day of month, 1-31
  cadence     TEXT NOT NULL DEFAULT 'monthly'
    CHECK (cadence IN ('monthly','weekly','yearly','quarterly','once')),
  account_id  INTEGER REFERENCES accounts (id) ON DELETE SET NULL,
  autopay     INTEGER NOT NULL DEFAULT 0,
  next_due    TEXT,
  ended_on    TEXT
);

-- ------------------------------------------------------------ change log --

-- Every write goes here. This is the audit trail the Change log tab renders and
-- the only record of who/what touched a medical or financial field.
CREATE TABLE IF NOT EXISTS changes (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  at         TEXT NOT NULL DEFAULT (datetime('now')),
  entity     TEXT NOT NULL,             -- table name
  entity_id  INTEGER,
  person_id  INTEGER REFERENCES people (id) ON DELETE SET NULL,
  field      TEXT,
  before_val TEXT,
  after_val  TEXT,
  action     TEXT NOT NULL DEFAULT 'update'
    CHECK (action IN ('create','update','delete','login','import')),
  source     TEXT
);
CREATE INDEX IF NOT EXISTS idx_changes_at ON changes (at DESC);
