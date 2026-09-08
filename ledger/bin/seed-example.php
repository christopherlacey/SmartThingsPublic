<?php
/**
 * Fill an empty ledger with obviously-fake example data.
 *
 *   php bin/seed-example.php
 *
 * This exists so the layout, the charts and the "you're in a store" path can be
 * seen working before any real record is entered. Everything it writes is
 * invented and labelled EXAMPLE, and it refuses to run against a database that
 * already holds people — your real medical and financial records are not
 * something a demo script should ever be able to touch.
 *
 * Clear it out when you're done:
 *   php bin/seed-example.php --wipe
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../src/bootstrap.php';

$config = ledger_config();
date_default_timezone_set($config['timezone']);

$wipe = in_array('--wipe', $argv, true);

if ($wipe) {
    foreach (['vitals', 'medications', 'appointments', 'conditions', 'transactions',
              'balances', 'bills', 'accounts', 'shopping_items', 'location_pings',
              'places', 'events', 'days', 'person_fields', 'people', 'changes'] as $table) {
        qx("DELETE FROM $table WHERE 1");
    }
    exit("Example data removed. The account and its password are untouched.\n");
}

if ((int) qv('SELECT COUNT(*) FROM people') > 0) {
    exit("This ledger already has people in it. Refusing to seed over real data.\n"
       . "Use --wipe first if this really is a scratch database.\n");
}

$today = new DateTimeImmutable('today');
$day   = static fn(int $offset): string => $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days')->format('Y-m-d');

/* ----------------------------------------------------------------- people -- */

qx('INSERT INTO people (name, relationship, is_self, city, region, country, location_confidence, location_seen_at)
    VALUES (?, ?, 1, ?, ?, ?, ?, datetime("now"))',
    ['EXAMPLE — You', 'self', 'Mount Pleasant', 'SC', 'US', 'confirmed']);
$meId = (int) db()->lastInsertId();

qx('INSERT INTO people (name, relationship, is_self, city, region, country, location_confidence)
    VALUES (?, ?, 0, ?, ?, ?, ?)',
    ['EXAMPLE — Family member', 'family', 'Charleston', 'SC', 'US', 'confirmed']);
$otherId = (int) db()->lastInsertId();

qx('INSERT INTO person_fields (person_id, section, field, value, sensitive, source) VALUES
    (?, "Contact", "Mobile", "555-0100", 0, "example"),
    (?, "Identity", "Policy number", "EX-000-000-000", 1, "example"),
    (?, "Health", "Blood type", "O+", 0, "example")',
    [$meId, $meId, $meId]);

/* ----------------------------------------------------------------- places -- */

// Coordinates are placeholders in the right part of the world. Replace them with
// real ones or the geofence will never match.
qx('INSERT INTO places (name, kind, lat, lon, radius_m, store_tag, address) VALUES
    ("EXAMPLE — Home",          "home",  32.7941, -79.8626, 120, NULL,            "Mount Pleasant, SC"),
    ("EXAMPLE — Harris Teeter", "store", 32.8000, -79.8700, 150, "Harris Teeter", "Mount Pleasant, SC"),
    ("EXAMPLE — Pharmacy",      "pharmacy", 32.8020, -79.8750, 120, "Pharmacy",   "Mount Pleasant, SC")');

$storeId = (int) qv('SELECT id FROM places WHERE kind = "store" LIMIT 1');

// A ping placing you inside the example store, so the Now tab shows the
// "you're here and these are on the list" path straight away.
qx('INSERT INTO location_pings (at, lat, lon, accuracy_m, place_id, transition, source)
    VALUES (datetime("now", "-4 minutes"), 32.8000, -79.8700, 12, ?, "arrive", "example")',
    [$storeId]);

/* --------------------------------------------------------------- errands -- */

qx('INSERT INTO shopping_items (item, qty, store_tag, category, urgent) VALUES
    ("EXAMPLE — Coffee beans", "2 bags", "Harris Teeter", "Groceries", 0),
    ("EXAMPLE — Olive oil",    "1 bottle", "Harris Teeter", "Groceries", 0),
    ("EXAMPLE — Batteries",    "AA, 8pk", NULL, "Household", 1),
    ("EXAMPLE — Prescription pickup", NULL, "Pharmacy", "Health", 1)');

/* ------------------------------------------------------------------- day -- */

qx('INSERT INTO days (day, brief, written_at) VALUES (?, ?, datetime("now"))',
    [$day(0), "EXAMPLE brief. Replace this with what's actually going on today."]);

qx('INSERT INTO events (day, starts_at, title, location, person_id, kind) VALUES
    (?, "09:00", "EXAMPLE — Shift starts", "Harris Teeter", NULL, "work"),
    (?, "18:30", "EXAMPLE — Dinner",       "Home", ?, "event")',
    [$day(0), $day(0), $otherId]);

/* ---------------------------------------------------------------- health -- */

qx('INSERT INTO medications (person_id, name, dose, schedule, purpose, refill_due, started_on) VALUES
    (?, "EXAMPLE — Vitamin D", "2000 IU", "Once daily", "Supplement", ?, ?)',
    [$meId, $day(9), $day(-200)]);

qx('INSERT INTO conditions (person_id, name, kind, detail, noted_on) VALUES
    (?, "EXAMPLE — Penicillin", "allergy", "Rash", ?)',
    [$meId, $day(-900)]);

qx('INSERT INTO appointments (person_id, what, provider, place, on_day, at_time) VALUES
    (?, "EXAMPLE — Annual physical", "Dr Example", "Mount Pleasant", ?, "10:30")',
    [$meId, $day(12)]);

// Twenty weekly weigh-ins wandering around a starting point, so the line chart
// has a real shape to render rather than a straight line.
$weight = 182.0;
for ($i = 19; $i >= 0; $i--) {
    $weight += sin($i / 2.6) * 0.7 - 0.09;
    qx('INSERT INTO vitals (person_id, metric, value, unit, measured_on, source)
        VALUES (?, "weight", ?, "lb", ?, "example")',
        [$meId, round($weight, 1), $day(-7 * $i)]);
}

for ($i = 11; $i >= 0; $i--) {
    qx('INSERT INTO vitals (person_id, metric, value, unit, measured_on, source)
        VALUES (?, "resting heart rate", ?, "bpm", ?, "example")',
        [$meId, 58 + (int) round(sin($i / 1.7) * 4), $day(-14 * $i)]);
}

/* ----------------------------------------------------------------- money -- */

qx('INSERT INTO accounts (name, institution, kind, last4, is_liability) VALUES
    ("EXAMPLE — Checking",    "Example Bank", "checking",   "0000", 0),
    ("EXAMPLE — Savings",     "Example Bank", "savings",    "0001", 0),
    ("EXAMPLE — Credit card", "Example Bank", "credit",     "0002", 1)');

$checking = (int) qv('SELECT id FROM accounts WHERE kind = "checking"');
$savings  = (int) qv('SELECT id FROM accounts WHERE kind = "savings"');
$credit   = (int) qv('SELECT id FROM accounts WHERE kind = "credit"');

// Fourteen months of month-end balances, so net worth has a trend.
for ($i = 13; $i >= 0; $i--) {
    $onDay = $today->modify("-$i months")->format('Y-m-t');
    qx('INSERT OR REPLACE INTO balances (account_id, on_day, amount, source) VALUES (?, ?, ?, "example")',
        [$checking, $onDay, round(3200 + sin($i / 2) * 700, 2)]);
    qx('INSERT OR REPLACE INTO balances (account_id, on_day, amount, source) VALUES (?, ?, ?, "example")',
        [$savings, $onDay, round(14000 + (13 - $i) * 420, 2)]);
    qx('INSERT OR REPLACE INTO balances (account_id, on_day, amount, source) VALUES (?, ?, ?, "example")',
        [$credit, $onDay, round(1400 + cos($i / 3) * 350, 2)]);
}

$categories = ['Groceries', 'Fuel', 'Dining', 'Utilities', 'Household', 'Health'];
for ($i = 0; $i < 150; $i++) {
    $daysBack = (int) floor($i * 1.2);
    qx('INSERT INTO transactions (account_id, on_day, merchant, category, amount, source)
        VALUES (?, ?, ?, ?, ?, "example")',
        [
            $checking,
            $day(-$daysBack),
            'EXAMPLE — Merchant ' . (($i % 9) + 1),
            $categories[$i % count($categories)],
            -round(9 + (($i * 37) % 120) + (($i % 5) * 4.25), 2),
        ]);
}

// Two paychecks a month, so the cash-flow chart has both series.
for ($i = 0; $i < 12; $i++) {
    qx('INSERT INTO transactions (account_id, on_day, merchant, category, amount, source)
        VALUES (?, ?, "EXAMPLE — Payroll", "Income", ?, "example")',
        [$checking, $day(-$i * 15), round(1850 + ($i % 3) * 60, 2)]);
}

qx('INSERT INTO bills (name, amount, cadence, account_id, autopay, next_due) VALUES
    ("EXAMPLE — Rent",      1450.00, "monthly", ?, 0, ?),
    ("EXAMPLE — Internet",    79.99, "monthly", ?, 1, ?),
    ("EXAMPLE — Phone",       62.00, "monthly", ?, 1, ?)',
    [$checking, $day(6), $checking, $day(3), $checking, $day(17)]);

log_change('seed', null, null, null, 'example data', 'import', null, 'seed-example.php');

echo "Seeded example data.\n";
echo "Everything is prefixed EXAMPLE and none of it is real.\n";
echo "Remove it with: php bin/seed-example.php --wipe\n";
