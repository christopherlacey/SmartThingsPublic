<?php
/**
 * alert.php — fires when someone taps a 911 button on connect.chrislacey.com.
 *
 * Design rules, in priority order:
 *   1. Never delay the call. The page uses sendBeacon and ignores the response,
 *      so nothing here can sit between a person and the dialer.
 *   2. Write the local record FIRST. Network alerts can fail; the on-disk log
 *      is the copy that always survives.
 *   3. Alert Chris's phone and Chris's computer only. No shared inbox, no team
 *      channel, no group chat, no third party. Every destination in
 *      alert-config.php is a personal endpoint by design.
 *   4. Never enrich the visitor's data through an outside service. Everything
 *      reported below is either sent by the browser, attached by the CDN, or
 *      already in Chris's own server logs.
 */

declare(strict_types=1);

ignore_user_abort(true);          // the browser is leaving for the dialer; finish anyway
set_time_limit(30);
header('Content-Type: text/plain; charset=utf-8');
header('Access-Control-Allow-Origin: https://connect.chrislacey.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo "POST only.";
    exit;
}

// Answer the browser immediately, then keep working. The person is mid-emergency.
http_response_code(204);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$configPath = __DIR__ . '/alert-config.php';
$cfg = is_readable($configPath) ? require $configPath : [];
if (!is_array($cfg)) {
    $cfg = [];
}

// ---------------------------------------------------------------------------
// 1. Collect everything we legitimately know about this person.
// ---------------------------------------------------------------------------

// Public endpoint: read a bounded amount. A legitimate beacon is ~1 KB; anything
// larger is either broken or an attempt to make us allocate and log megabytes.
const MAX_PAYLOAD = 16384;

$stream = fopen('php://input', 'rb');
$raw = $stream ? (string) stream_get_contents($stream, MAX_PAYLOAD) : '';
if ($stream) {
    fclose($stream);
}

$client = json_decode($raw, true);
if (!is_array($client)) {
    $client = ['_note' => 'no usable client payload', '_raw_bytes' => strlen($raw)];
}

/** Trim any client-supplied string before it reaches the log or a text message. */
function clip($v, int $max = 512): ?string
{
    if ($v === null || is_array($v) || is_object($v)) {
        return null;
    }
    $s = (string) $v;
    return strlen($s) > $max ? substr($s, 0, $max) . '…[truncated]' : $s;
}

/**
 * Coordinates arrive from the browser and are therefore untrusted. A bad shape used
 * to be fatal here, which killed the alert before a single channel was tried; a
 * non-numeric lat/lon used to format as 0,0 — a real place in the Gulf of Guinea.
 * Both now degrade to "no GPS", and the rest of the alert still goes out.
 */
function valid_gps($g): ?array
{
    if (!is_array($g) || !isset($g['lat'], $g['lon'])) {
        return null;
    }
    if (!is_numeric($g['lat']) || !is_numeric($g['lon'])) {
        return null;
    }
    $lat = (float) $g['lat'];
    $lon = (float) $g['lon'];
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
        return null;
    }
    return [
        'lat'        => $lat,
        'lon'        => $lon,
        'accuracy_m' => isset($g['accuracy_m']) && is_numeric($g['accuracy_m']) ? (int) $g['accuracy_m'] : null,
        'at'         => clip($g['at'] ?? null, 40),
    ];
}

/** Real client IP, preferring CDN headers over the socket peer. */
function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_TRUE_CLIENT_IP', 'HTTP_X_REAL_IP'] as $h) {
        if (!empty($_SERVER[$h]) && filter_var($_SERVER[$h], FILTER_VALIDATE_IP)) {
            return (string) $_SERVER[$h];
        }
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $part) {
            $part = trim($part);
            if (filter_var($part, FILTER_VALIDATE_IP)) {
                return $part;
            }
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

$ip = client_ip();

// Geo/ASN attached by the CDN in front of the site. Free, already present on the
// request, and never leaves the visitor's address with an outside lookup service.
$edge = [];
foreach ([
    'HTTP_CF_IPCOUNTRY'    => 'country',
    'HTTP_CF_IPCITY'       => 'city',
    'HTTP_CF_REGION'       => 'region',
    'HTTP_CF_IPLATITUDE'   => 'latitude',
    'HTTP_CF_IPLONGITUDE'  => 'longitude',
    'HTTP_CF_IPTIMEZONE'   => 'timezone',
    'HTTP_CF_RAY'          => 'cf_ray',
] as $header => $label) {
    if (!empty($_SERVER[$header])) {
        $edge[$label] = (string) $_SERVER[$header];
    }
}

// Every request header, so nothing knowable is silently dropped.
$headers = [];
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0 && !in_array($k, ['HTTP_COOKIE'], true)) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
    }
}

/**
 * "Search through every log you can" — this person's other hits on this site,
 * pulled from Chris's own access log. Bounded to the tail of the file so a huge
 * log can't stall the alert.
 */
function recent_hits(string $ip, ?string $logPath, int $maxLines = 4000, int $keep = 25): array
{
    if (!$logPath || !is_readable($logPath) || $ip === 'unknown') {
        return [];
    }
    $fh = @fopen($logPath, 'r');
    if (!$fh) {
        return [];
    }
    // Read roughly the last 2 MB rather than the whole file.
    $size = (int) (@filesize($logPath) ?: 0);
    if ($size > 2_000_000) {
        @fseek($fh, -2_000_000, SEEK_END);
        @fgets($fh); // discard the partial first line
    }
    // Anchor the match so 10.1.2.3 stops matching 110.1.2.30 — a false hit here is
    // misleading evidence about where someone was when they pressed the button.
    $pattern = '/(?<![0-9a-fA-F.:])' . preg_quote($ip, '/') . '(?![0-9a-fA-F.:])/';

    $hits = [];
    $lines = 0;
    while (($line = fgets($fh)) !== false && $lines++ < $maxLines) {
        if (preg_match($pattern, $line) === 1) {
            $hits[] = rtrim($line);
            if (count($hits) > $keep) {
                array_shift($hits);
            }
        }
    }
    fclose($fh);
    return $hits;
}

$report = [
    'event'        => '911 BUTTON PRESSED on connect.chrislacey.com',
    'button'       => clip($client['button'] ?? 'unknown', 40) ?? 'unknown',
    'server_time'  => gmdate('c'),
    'ip'           => $ip,
    'reverse_dns'  => $ip !== 'unknown' ? @gethostbyaddr($ip) : null,
    'edge_geo'     => $edge,
    'device_time'  => clip($client['page_time'] ?? null, 40),
    'device_tz'    => clip($client['tz'] ?? null, 64),
    'languages'    => is_array($client['languages'] ?? null) ? array_slice($client['languages'], 0, 10) : null,
    'user_agent'   => clip($client['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null)),
    'platform'     => clip($client['platform'] ?? null, 80),
    'mobile'       => $client['mobile'] ?? null,
    'screen'       => clip($client['screen'] ?? null, 40),
    'network'      => is_array($client['network'] ?? null) ? $client['network'] : null,
    'gps'          => valid_gps($client['coords'] ?? null),  // null unless they allowed location
    'came_from'    => clip($client['referrer'] ?? ($_SERVER['HTTP_REFERER'] ?? null)),
    'page_url'     => clip($client['href'] ?? null),
    'headers'      => $headers,
    'recent_hits'  => recent_hits($ip, $cfg['access_log'] ?? null),
];

// ---------------------------------------------------------------------------
// 2. Local record first — this one cannot fail over the network.
// ---------------------------------------------------------------------------

// This log holds IP addresses and sometimes the GPS position of someone in an
// emergency, so the fallback must never be the web root — a stray directory
// listing there would publish it. Fall back to the system temp dir instead.
$logFile = (string) ($cfg['alert_log'] ?? (rtrim(sys_get_temp_dir(), '/') . '/911-alerts.log'));

/**
 * Append to the log and say whether it worked. "Local record first" is only a
 * guarantee if a failed write is visible, so failures fall back to the temp dir
 * and are reported to the server error log rather than being swallowed.
 */
function log_line(string $path, string $line): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        $fallback = rtrim(sys_get_temp_dir(), '/') . '/911-alerts.log';
        if ($path !== $fallback) {
            error_log("alert.php: cannot write {$path}, falling back to {$fallback}");
            $path = $fallback;
        }
    }
    $ok = file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    if ($ok === false) {
        // Last resort: the server error log still captures the event.
        error_log('alert.php: LOCAL LOG WRITE FAILED. ' . rtrim($line));
        return false;
    }
    return true;
}

$logged = log_line(
    $logFile,
    gmdate('c') . ' ' . json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
);

// ---------------------------------------------------------------------------
// 3. Build the message.
// ---------------------------------------------------------------------------

$gps = $report['gps'];   // already validated: either a clean lat/lon pair or null
$where = $gps !== null
    ? sprintf(
        'GPS %.5f,%.5f%s  https://maps.google.com/?q=%.5f,%.5f',
        $gps['lat'], $gps['lon'],
        $gps['accuracy_m'] !== null ? sprintf(' (±%dm)', $gps['accuracy_m']) : '',
        $gps['lat'], $gps['lon']
    )
    : (trim(($edge['city'] ?? '') . ' ' . ($edge['region'] ?? '') . ' ' . ($edge['country'] ?? '')) ?: 'location unknown');

$short = "911 PRESSED on your connect page\n"
    . 'When: ' . $report['server_time'] . " UTC\n"
    . 'Where: ' . $where . "\n"
    . 'IP: ' . $report['ip'] . "\n"
    . 'Device: ' . substr((string) $report['user_agent'], 0, 120) . "\n"
    . 'Button: ' . $report['button'];

$full = $short . "\n\n--- everything known ---\n"
    . json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

// ---------------------------------------------------------------------------
// 4. Send on every configured personal channel. One failure never stops the rest.
// ---------------------------------------------------------------------------

/** Small POST helper. Short timeouts: several channels must all get their turn. */
function post(string $url, $body, array $headers = [], ?string $userpass = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => is_array($body) ? http_build_query($body) : $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    if ($userpass !== null) {
        curl_setopt($ch, CURLOPT_USERPWD, $userpass);
    }
    $out  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'error' => $err, 'body' => is_string($out) ? substr($out, 0, 300) : ''];
}

$results = [];

// -- Chris's phone: SMS (works with no data) --------------------------------
/** True only when every key this channel dereferences is actually present. */
function ready(array $cfg, string $section, array $keys): bool
{
    foreach ($keys as $k) {
        if (empty($cfg[$section][$k])) {
            return false;
        }
    }
    return true;
}

if (ready($cfg, 'twilio', ['sid', 'token', 'sms_from', 'sms_to'])) {
    $t = $cfg['twilio'];
    $results['sms'] = post(
        "https://api.twilio.com/2010-04-01/Accounts/{$t['sid']}/Messages.json",
        ['From' => $t['sms_from'], 'To' => $t['sms_to'], 'Body' => $short],
        [],
        "{$t['sid']}:{$t['token']}"
    );
}

// -- Chris's phone: WhatsApp -------------------------------------------------
if (ready($cfg, 'twilio', ['sid', 'token', 'whatsapp_from', 'whatsapp_to'])) {
    $t = $cfg['twilio'];
    $results['whatsapp'] = post(
        "https://api.twilio.com/2010-04-01/Accounts/{$t['sid']}/Messages.json",
        ['From' => $t['whatsapp_from'], 'To' => $t['whatsapp_to'], 'Body' => $short],
        [],
        "{$t['sid']}:{$t['token']}"
    );
}

// -- Chris's phone: an actual ringing call, so it wakes him --------------------
if (ready($cfg, 'twilio', ['sid', 'token', 'sms_from', 'call_to'])) {
    $t = $cfg['twilio'];
    $say = '<Response><Say voice="alice">Someone pressed the 911 button on your connect page. '
         . 'Check your phone for the details.</Say></Response>';
    $results['voice_call'] = post(
        "https://api.twilio.com/2010-04-01/Accounts/{$t['sid']}/Calls.json",
        ['From' => $t['sms_from'], 'To' => $t['call_to'], 'Twiml' => $say],
        [],
        "{$t['sid']}:{$t['token']}"
    );
}

// -- Chris's phone AND computer: push (Pushover delivers to both) --------------
if (!empty($cfg['pushover']['token']) && !empty($cfg['pushover']['user'])) {
    $p = $cfg['pushover'];
    $results['pushover'] = post('https://api.pushover.net/1/messages.json', [
        'token'    => $p['token'],
        'user'     => $p['user'],       // a personal user key, not a group key
        'title'    => '911 pressed on your connect page',
        'message'  => $short,
        'priority' => 2,                // emergency: repeats until acknowledged
        'retry'    => 60,
        'expire'   => 1800,
        'sound'    => 'persistent',
    ]);
}

// -- Chris's phone AND computer: ntfy fallback --------------------------------
if (!empty($cfg['ntfy']['topic_url'])) {
    $results['ntfy'] = post($cfg['ntfy']['topic_url'], $short, [
        'Title: 911 pressed on your connect page',
        'Priority: urgent',
        'Tags: rotating_light',
    ]);
}

// -- Chris's computer: the full dossier by email -------------------------------
if (!empty($cfg['email_to'])) {
    $headersMail = "From: " . ($cfg['email_from'] ?? 'alerts@chrislacey.com') . "\r\n"
        . "Content-Type: text/plain; charset=utf-8\r\n"
        . "X-Priority: 1\r\n";
    $results['email'] = ['sent' => @mail($cfg['email_to'], '911 PRESSED on connect.chrislacey.com', $full, $headersMail)];
}

log_line(
    $logFile,
    gmdate('c') . ' delivery ' . json_encode(
        ['local_log_ok' => $logged, 'channels' => $results],
        JSON_UNESCAPED_SLASHES
    ) . PHP_EOL
);
