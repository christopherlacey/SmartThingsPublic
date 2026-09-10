<?php
/**
 * Queries. Pages stay presentational; the thinking lives here.
 */

declare(strict_types=1);

/* ------------------------------------------------------------------ people */

function self_person(): ?array
{
    return q1('SELECT * FROM people WHERE is_self = 1 ORDER BY id LIMIT 1');
}

function all_people(): array
{
    return q('SELECT * FROM people ORDER BY is_self DESC, name COLLATE NOCASE');
}

function person(int $id): ?array
{
    return q1('SELECT * FROM people WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------- where I am */

/**
 * The most recent ping, joined to the place it resolved to.
 */
function latest_location(): ?array
{
    return q1(
        'SELECT p.*, pl.name AS place_name, pl.kind AS place_kind,
                pl.store_tag, pl.address
           FROM location_pings p
           LEFT JOIN places pl ON pl.id = p.place_id
          ORDER BY p.at DESC, p.id DESC
          LIMIT 1'
    );
}

/**
 * Match coordinates to a known place.
 *
 * Equirectangular approximation — at the radius we care about (tens to hundreds
 * of metres) the error against a great-circle distance is far below GPS noise,
 * and it costs one cheap pass over a table that holds a few dozen rows.
 */
function place_for_coords(?float $lat, ?float $lon): ?array
{
    if ($lat === null || $lon === null) {
        return null;
    }

    $best = null;
    $bestDistance = null;

    foreach (q('SELECT * FROM places WHERE lat IS NOT NULL AND lon IS NOT NULL') as $place) {
        $dLat = deg2rad((float) $place['lat'] - $lat);
        $dLon = deg2rad((float) $place['lon'] - $lon) * cos(deg2rad($lat));
        $metres = 6371000.0 * sqrt($dLat * $dLat + $dLon * $dLon);

        if ($metres <= (float) $place['radius_m'] && ($bestDistance === null || $metres < $bestDistance)) {
            $best = $place;
            $bestDistance = $metres;
        }
    }

    return $best;
}

/**
 * How to describe where he is, in one line, plus how much to trust it.
 *
 * Returns null when there is no fix at all — the Now tab says so plainly rather
 * than showing a stale position as if it were current.
 */
function location_summary(): ?array
{
    $ping = latest_location();
    if ($ping === null) {
        return null;
    }

    $ageMinutes = (time() - (strtotime($ping['at'] . ' UTC') ?: time())) / 60;

    // A fix goes stale quickly. Under 20 minutes it is where he is; under a few
    // hours it is where he was; past that it is only a last known position.
    $confidence = $ageMinutes < 20 ? 'confirmed' : ($ageMinutes < 240 ? 'inferred' : 'stale');

    $where = $ping['place_name'] ?? $ping['label'] ?? null;

    return [
        'place'      => $where,
        'kind'       => $ping['place_kind'] ?? null,
        'store_tag'  => $ping['store_tag'] ?? null,
        'address'    => $ping['address'] ?? null,
        'at'         => $ping['at'],
        'age_min'    => $ageMinutes,
        'confidence' => $confidence,
        'lat'        => $ping['lat'],
        'lon'        => $ping['lon'],
        'transition' => $ping['transition'],
        'source'     => $ping['source'],
        // "In a store right now" is the trigger for surfacing the shopping list,
        // so it needs both a shop-shaped place and a fix recent enough to act on.
        'in_store'   => in_array($ping['place_kind'] ?? '', ['store', 'pharmacy'], true)
                        && $ping['transition'] !== 'depart'
                        && $ageMinutes < 90,
    ];
}

/* ---------------------------------------------------------------- errands */

function open_shopping(?string $storeTag = null): array
{
    if ($storeTag !== null) {
        return q(
            'SELECT * FROM shopping_items
              WHERE bought_at IS NULL AND store_tag = ?
              ORDER BY urgent DESC, category, item COLLATE NOCASE',
            [$storeTag]
        );
    }

    return q(
        'SELECT * FROM shopping_items
          WHERE bought_at IS NULL
          ORDER BY urgent DESC, store_tag IS NULL, store_tag, item COLLATE NOCASE'
    );
}

/* ------------------------------------------------------------------- day */

function day_brief(string $day): ?array
{
    return q1('SELECT * FROM days WHERE day = ?', [$day]);
}

function day_events(string $day): array
{
    return q(
        'SELECT e.*, p.name AS person_name
           FROM events e
           LEFT JOIN people p ON p.id = e.person_id
          WHERE e.day = ?
          ORDER BY e.starts_at IS NULL DESC, e.starts_at',
        [$day]
    );
}

/* ---------------------------------------------------------------- health */

/** Medications currently being taken (not ended). */
function active_medications(int $personId): array
{
    return q(
        'SELECT * FROM medications
          WHERE person_id = ? AND (ended_on IS NULL OR ended_on > date("now"))
          ORDER BY name COLLATE NOCASE',
        [$personId]
    );
}

function refills_due(int $personId, int $withinDays = 14): array
{
    return q(
        'SELECT * FROM medications
          WHERE person_id = ?
            AND ended_on IS NULL
            AND refill_due IS NOT NULL
            AND refill_due <= date("now", ?)
          ORDER BY refill_due',
        [$personId, '+' . $withinDays . ' days']
    );
}

/** A metric's readings oldest-first, ready to plot. */
function vitals_series(int $personId, string $metric, int $limit = 60): array
{
    $rows = q(
        'SELECT measured_on, value, unit FROM vitals
          WHERE person_id = ? AND metric = ?
          ORDER BY measured_on DESC
          LIMIT ?',
        [$personId, $metric, $limit]
    );
    return array_reverse($rows);
}

/** Which metrics this person actually has data for. */
function tracked_metrics(int $personId): array
{
    return q(
        'SELECT metric, unit, COUNT(*) AS n, MAX(measured_on) AS latest
           FROM vitals WHERE person_id = ?
          GROUP BY metric, unit
          ORDER BY metric',
        [$personId]
    );
}

/** Source documents on file for someone, newest first. */
function person_documents(int $personId, int $limit = 200): array
{
    return q(
        'SELECT * FROM documents
          WHERE person_id = ?
          ORDER BY doc_date IS NULL, doc_date DESC, added_at DESC
          LIMIT ?',
        [$personId, $limit]
    );
}

function upcoming_appointments(int $limit = 6): array
{
    return q(
        'SELECT a.*, p.name AS person_name
           FROM appointments a
           JOIN people p ON p.id = a.person_id
          WHERE a.status = "scheduled" AND a.on_day >= date("now")
          ORDER BY a.on_day, a.at_time
          LIMIT ?',
        [$limit]
    );
}

/* ----------------------------------------------------------------- money */

/**
 * Latest known balance per open account.
 *
 * The subquery picks each account's most recent balance date, so an account
 * updated last week still shows its real figure instead of dropping out.
 */
function account_balances(): array
{
    return q(
        'SELECT a.*, b.amount, b.on_day
           FROM accounts a
           LEFT JOIN balances b
             ON b.account_id = a.id
            AND b.on_day = (SELECT MAX(on_day) FROM balances WHERE account_id = a.id)
          WHERE a.closed_on IS NULL
          ORDER BY a.is_liability, a.kind, a.name COLLATE NOCASE'
    );
}

function net_worth(): float
{
    $total = 0.0;
    foreach (account_balances() as $account) {
        if ($account['amount'] === null) {
            continue;
        }
        $total += $account['is_liability'] ? -abs((float) $account['amount']) : (float) $account['amount'];
    }
    return $total;
}

/** Net worth at each month end, oldest first — the trend line on the Money tab. */
function net_worth_series(int $months = 12): array
{
    $rows = q(
        "SELECT strftime('%Y-%m', b.on_day) AS month,
                a.is_liability,
                b.account_id,
                b.amount,
                b.on_day
           FROM balances b
           JOIN accounts a ON a.id = b.account_id
          ORDER BY b.on_day"
    );

    // Walk forward carrying each account's most recent balance, so a month where
    // one account went unrecorded still totals every other account.
    $carry  = [];
    $byMonth = [];

    foreach ($rows as $row) {
        $carry[$row['account_id']] = [
            'amount'    => (float) $row['amount'],
            'liability' => (bool) $row['is_liability'],
        ];

        $total = 0.0;
        foreach ($carry as $held) {
            $total += $held['liability'] ? -abs($held['amount']) : $held['amount'];
        }
        $byMonth[$row['month']] = $total;
    }

    $series = [];
    foreach ($byMonth as $month => $total) {
        $series[] = ['month' => $month, 'value' => round($total, 2)];
    }

    return array_slice($series, -$months);
}

/** Spending by category over a window, largest first. */
function spend_by_category(int $days = 30, int $limit = 8): array
{
    return q(
        'SELECT COALESCE(NULLIF(category, ""), "Uncategorised") AS category,
                ROUND(SUM(-amount), 2) AS total,
                COUNT(*) AS n
           FROM transactions
          WHERE amount < 0 AND on_day >= date("now", ?)
          GROUP BY category
          ORDER BY total DESC
          LIMIT ?',
        ['-' . $days . ' days', $limit]
    );
}

/** Money in and money out per month, for the cash-flow chart. */
function monthly_cashflow(int $months = 6): array
{
    $rows = q(
        "SELECT strftime('%Y-%m', on_day) AS month,
                ROUND(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 2)  AS money_in,
                ROUND(SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END), 2) AS money_out
           FROM transactions
          WHERE on_day >= date('now', ?)
          GROUP BY month
          ORDER BY month",
        ['-' . $months . ' months']
    );
    return $rows;
}

function bills_due(int $withinDays = 10): array
{
    return q(
        'SELECT b.*, a.name AS account_name
           FROM bills b
           LEFT JOIN accounts a ON a.id = b.account_id
          WHERE b.ended_on IS NULL
            AND b.next_due IS NOT NULL
            AND b.next_due <= date("now", ?)
          ORDER BY b.next_due',
        ['+' . $withinDays . ' days']
    );
}

function recent_transactions(int $limit = 12): array
{
    return q(
        'SELECT t.*, a.name AS account_name
           FROM transactions t
           LEFT JOIN accounts a ON a.id = t.account_id
          ORDER BY t.on_day DESC, t.id DESC
          LIMIT ?',
        [$limit]
    );
}

/* ------------------------------------------------------------ change log */

function recent_changes(int $limit = 200): array
{
    return q(
        'SELECT c.*, p.name AS person_name
           FROM changes c
           LEFT JOIN people p ON p.id = c.person_id
          ORDER BY c.at DESC, c.id DESC
          LIMIT ?',
        [$limit]
    );
}
