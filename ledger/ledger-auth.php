<?php
/**
 * The Lacey Ledger — the front door.
 *
 * This file is loaded by PHP's auto_prepend_file, so it runs before every PHP
 * request under the docroot — including index.php, people.php, medical.php,
 * the blog, and anything added later. That is the point: the gate does not
 * depend on each page remembering to ask for it, and pages whose source is not
 * in this repo are covered too.
 *
 * Signed in means: Google says you hold a verified address on the allow list,
 * and (when enabled) you have entered a current code from the authenticator.
 *
 * Wiring — one of these, in the docroot:
 *   .htaccess   php_value auto_prepend_file /var/www/ledger/ledger-auth.php
 *   .user.ini   auto_prepend_file = /var/www/ledger/ledger-auth.php
 *
 * NOTE: auto_prepend_file governs PHP only. Static files are still served by
 * Apache without passing through here, which is why .htaccess also denies the
 * config, schema, log and backup files outright.
 */

declare(strict_types=1);

// The CLI importer and any cron job must not be gated.
if (PHP_SAPI === 'cli') {
    return;
}

/* -------------------------------------------------------------- config -- */

function ledger_auth_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $file = __DIR__ . '/ledger-auth-config.php';
    if (!is_readable($file)) {
        // Fail closed. A missing config must never mean "let everyone in".
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit("The Ledger is not configured for sign-in yet.\n");
    }

    $config = require $file;
    return $config;
}

/* ------------------------------------------------------------- session -- */

function ledger_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Keep the default session name so anything already using PHP's session
    // on this host keeps working; only the cookie's protections change.
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => true,      // the site is HTTPS-only
        'httponly' => true,      // no script can read it
        'samesite' => 'Lax',     // Lax, not Strict: the OAuth return is a redirect
    ]);
    session_start();
}

/** Everything that marks a session as signed in, cleared in one place. */
function ledger_auth_clear(): void
{
    unset(
        $_SESSION['ledger_email'],
        $_SESSION['ledger_login_at'],
        $_SESSION['ledger_seen_at'],
        $_SESSION['ledger_2fa_ok']
    );
}

/**
 * Is this session signed in and still fresh?
 * Any doubt clears the session rather than letting it through.
 */
function ledger_is_authenticated(): bool
{
    $config = ledger_auth_config();

    $email   = $_SESSION['ledger_email']    ?? null;
    $loginAt = $_SESSION['ledger_login_at'] ?? null;
    $seenAt  = $_SESSION['ledger_seen_at']  ?? null;

    if (!is_string($email) || !is_int($loginAt) || !is_int($seenAt)) {
        return false;
    }

    // The allow list is authority at every request, not just at sign-in, so
    // removing an address takes effect immediately.
    if (!ledger_email_allowed($email)) {
        ledger_auth_clear();
        return false;
    }

    $now = time();
    if ($now - $seenAt > (int) $config['idle_timeout']
        || $now - $loginAt > (int) $config['absolute_timeout']) {
        ledger_auth_clear();
        return false;
    }

    if (!empty($config['totp_enabled']) && ($config['totp_secret'] ?? '') !== '') {
        if (($_SESSION['ledger_2fa_ok'] ?? false) !== true) {
            return false;   // signed in with Google, still owes a code
        }
    }

    $_SESSION['ledger_seen_at'] = $now;
    return true;
}

/** Case-insensitive membership of the allow list. */
function ledger_email_allowed(string $email): bool
{
    $email = strtolower(trim($email));
    foreach (ledger_auth_config()['allowed_emails'] ?? [] as $allowed) {
        if (hash_equals(strtolower(trim((string) $allowed)), $email)) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------- state on disk -- */

/** Small JSON blob beside the config: TOTP replay counter, failed attempts. */
function ledger_state_path(string $name): string
{
    $dir = ledger_auth_config()['state_dir'] ?? sys_get_temp_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return rtrim($dir, '/') . '/' . $name;
}

function ledger_state_read(string $name): array
{
    $path = ledger_state_path($name);
    if (!is_readable($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    $data = json_decode((string) $raw, true);
    return is_array($data) ? $data : [];
}

function ledger_state_write(string $name, array $data): void
{
    $path = ledger_state_path($name);
    file_put_contents($path, json_encode($data), LOCK_EX);
    @chmod($path, 0600);
}

/* --------------------------------------------------------- the gate ----- */

/**
 * Endpoints that must stay reachable while signed out.
 *
 * Matched on the resolved absolute path, never on the file name. Comparing
 * basenames would make ANY file called login.php anywhere under the docroot
 * public — /blog/login.php, say — which is a gate with a hole in it.
 */
const LEDGER_PUBLIC_FILES = [
    'login.php',
    'oauth-callback.php',
    'logout.php',
];

/** Is the script being served one of the sign-in endpoints in THIS directory? */
function ledger_request_is_public(): bool
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if (!is_string($script) || $script === '') {
        return false;   // unknown script: gate it
    }

    $real = realpath($script);
    if ($real === false) {
        return false;
    }

    foreach (LEDGER_PUBLIC_FILES as $name) {
        $allowed = realpath(__DIR__ . '/' . $name);
        if ($allowed !== false && $allowed === $real) {
            return true;
        }
    }
    return false;
}

/** Where to send the visitor back to once they are through the door. */
function ledger_return_target(): string
{
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    // Only ever a path on this host — never an absolute URL, which would make
    // this an open redirect.
    if (!is_string($uri) || $uri === '' || $uri[0] !== '/' || str_starts_with($uri, '//')) {
        return '/';
    }
    return $uri;
}

ledger_session_start();

if (!ledger_request_is_public()) {
    if (!ledger_is_authenticated()) {
        $_SESSION['ledger_return_to'] = ledger_return_target();

        // Never let a signed-out response be cached.
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Location: /login.php', true, 302);
        exit;
    }
}

// Hand the session back so the page's own session_start() behaves normally.
// (Without this, PHP 8 warns that a session is already active.)
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
