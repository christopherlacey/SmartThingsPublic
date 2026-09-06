<?php
/**
 * go.php — link redirector for connect.chrislacey.com
 *
 * Every button on the page routes through here, so no phone number, handle or
 * address ever appears in the page source. The page ships a channel code
 * (?c=whatsapp-call) and this file turns it into a real destination.
 *
 * Reference implementation. The live site already has a go.php; if you keep
 * yours, the part that matters is contacts.php and the channel codes in it —
 * the missing config is what currently makes every link return
 * "Contact configuration missing."
 */

declare(strict_types=1);

$configPath = __DIR__ . '/contacts.php';

if (!is_readable($configPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Contact configuration missing.";
    exit;
}

/** @var array<string,string> $CONTACTS */
$CONTACTS = require $configPath;

if (!is_array($CONTACTS) || $CONTACTS === []) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Contact configuration missing.";
    exit;
}

$code = isset($_GET['c']) ? (string) $_GET['c'] : '';

// Channel codes are a fixed vocabulary: lowercase, digits and hyphens only.
if ($code === '' || !preg_match('/^[a-z0-9-]{1,40}$/', $code) || !isset($CONTACTS[$code])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unknown link. Go back to https://connect.chrislacey.com and pick a button.";
    exit;
}

$target = (string) $CONTACTS[$code];

// Only hand off to schemes we intend to support. Anything else is a config typo,
// and blindly redirecting to an arbitrary scheme is how open redirects happen.
$allowedSchemes = ['https', 'http', 'tel', 'sms', 'mailto', 'facetime-audio', 'facetime'];
$scheme = strtolower((string) parse_url($target, PHP_URL_SCHEME));

if ($scheme === '' || !in_array($scheme, $allowedSchemes, true)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "That link is misconfigured.";
    exit;
}

// Never let a redirect target sit in a cache or an analytics referrer trail.
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('Location: ' . $target, true, 302);
exit;
