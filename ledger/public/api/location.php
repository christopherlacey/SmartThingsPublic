<?php
/**
 * POST /api/location.php — where Chris is.
 *
 * This is the feed that makes the Now tab live. It takes a position from
 * whatever is already on his phone or in the house — an iOS Shortcut, Owntracks,
 * Home Assistant, a SmartThings presence sensor — and resolves it against the
 * `places` table so the dashboard can say "at Harris Teeter" rather than a pair
 * of coordinates.
 *
 * Auth is a bearer token, not the session cookie: the sender is a background
 * automation with no browser. The token is separate from the password so it can
 * be rotated without changing how Chris signs in, and an empty token in config
 * disables the endpoint outright rather than leaving it open.
 *
 *   curl -X POST https://c.lacey.me/api/location.php \
 *        -H 'Authorization: Bearer <token>' \
 *        -H 'Content-Type: application/json' \
 *        -d '{"lat":32.79,"lon":-79.86,"accuracy_m":12,"transition":"arrive"}'
 *
 * Accepts either a JSON body or ordinary form fields, because Shortcuts and
 * SmartThings differ on which they send.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/bootstrap.php';

$config = ledger_config();
date_default_timezone_set($config['timezone']);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function fail(int $code, string $message): never
{
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    fail(405, 'POST only.');
}

/* ------------------------------------------------------------------ auth -- */

$expected = (string) cfg('location_token', '');

if ($expected === '') {
    // No token configured means the feed was never set up. Closed is the right
    // default for an endpoint that writes to a private ledger.
    fail(503, 'Location endpoint is not configured.');
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$sent   = '';

if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
    $sent = trim($m[1]);
} elseif (isset($_GET['token'])) {
    // Some senders cannot set a header. Query tokens leak into access logs, so
    // this is a fallback, not the documented path.
    $sent = (string) $_GET['token'];
}

if ($sent === '' || !hash_equals($expected, $sent)) {
    fail(401, 'Bad token.');
}

/* ------------------------------------------------------------------ body -- */

$raw  = file_get_contents('php://input') ?: '';
$body = [];

if ($raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $body = $decoded;
    }
}
if (!$body && $_POST) {
    $body = $_POST;
}

/** Read a float that may arrive as a string, and reject anything unusable. */
function num(array $body, string $key): ?float
{
    if (!isset($body[$key]) || $body[$key] === '' || $body[$key] === null) {
        return null;
    }
    if (!is_numeric($body[$key])) {
        return null;
    }
    return (float) $body[$key];
}

$lat = num($body, 'lat') ?? num($body, 'latitude');
$lon = num($body, 'lon') ?? num($body, 'longitude') ?? num($body, 'lng');

// A ping with no usable position and no place name says nothing.
$label = isset($body['label']) ? mb_substr(trim((string) $body['label']), 0, 80) : null;
if ($lat === null && $label === null) {
    fail(400, 'Need lat/lon or a label.');
}

if ($lat !== null && ($lat < -90 || $lat > 90 || $lon === null || $lon < -180 || $lon > 180)) {
    fail(400, 'Coordinates out of range.');
}

$transition = strtolower(trim((string) ($body['transition'] ?? $body['event'] ?? 'ping')));
if (!in_array($transition, ['ping', 'arrive', 'depart'], true)) {
    // Owntracks and Home Assistant spell these differently; anything unrecognised
    // is recorded as a plain ping rather than rejected.
    $transition = match ($transition) {
        'enter', 'entered' => 'arrive',
        'exit', 'exited', 'leave', 'left' => 'depart',
        default => 'ping',
    };
}

$accuracy = num($body, 'accuracy_m') ?? num($body, 'accuracy') ?? num($body, 'acc');
$battery  = num($body, 'battery');
$source   = mb_substr(trim((string) ($body['source'] ?? 'api')), 0, 40) ?: 'api';

/* ------------------------------------------------------------- resolve --- */

// Prefer a geofence match. Fall back to a place whose name the sender supplied,
// which is how a SmartThings presence sensor reports without coordinates.
$place = place_for_coords($lat, $lon);

if ($place === null && $label !== null) {
    $place = q1('SELECT * FROM places WHERE name = ? COLLATE NOCASE', [$label]);
}

qx(
    'INSERT INTO location_pings (lat, lon, accuracy_m, place_id, transition, label, battery, source)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
    [
        $lat,
        $lon,
        $accuracy,
        $place['id'] ?? null,
        $transition,
        $label,
        $battery === null ? null : (int) $battery,
        $source,
    ]
);

// Keep the trail bounded. A month of breadcrumbs is plenty to answer "where was
// I on Tuesday" without the file growing forever.
qx("DELETE FROM location_pings WHERE at < datetime('now', '-90 days')");

// Keep the person record's own location in step, so the People tab agrees with
// the Now tab instead of drifting.
if ($place !== null) {
    $me = self_person();
    if ($me !== null && $transition !== 'depart') {
        qx(
            'UPDATE people SET location_confidence = ?, location_seen_at = datetime("now") WHERE id = ?',
            ['confirmed', $me['id']]
        );
    }
}

// What is on the list for this shop, so the sender can raise a notification
// without a second round trip.
$waiting = [];
if ($place !== null && !empty($place['store_tag']) && $transition !== 'depart') {
    $waiting = array_map(
        static fn(array $r): string => $r['item'] . ($r['qty'] ? ' (' . $r['qty'] . ')' : ''),
        open_shopping($place['store_tag'])
    );
}

echo json_encode([
    'ok'      => true,
    'place'   => $place['name'] ?? null,
    'kind'    => $place['kind'] ?? null,
    'waiting' => $waiting,
]);
