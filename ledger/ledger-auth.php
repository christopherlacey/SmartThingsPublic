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

/* ----------------------------------------------------------- constants -- */
/* Declared before the CLI early-return below: PHP hoists function
   declarations but evaluates `const` in order, so anything defined after that
   return would simply not exist on the CLI side — functions present,
   constants missing, which is a confusing way to fail. */

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

/** Wrong codes allowed inside the window before the second step locks. */
const LEDGER_MAX_ATTEMPTS = 5;
const LEDGER_LOCKOUT      = 900;   // 15 minutes

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

/**
 * Small JSON blob beside the config: TOTP replay counter, failed attempts.
 *
 * This store is not a cache. The replay counter IS the replay protection, and
 * the attempt counter IS the lockout, so a store that cannot be written means
 * neither control is in force. Everything here therefore reports failure
 * loudly instead of quietly carrying on — see ledger_state_usable().
 */
function ledger_state_dir(): string
{
    return rtrim((string) (ledger_auth_config()['state_dir'] ?? sys_get_temp_dir()), '/');
}

function ledger_state_path(string $name): string
{
    $dir = ledger_state_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . $name;
}

/**
 * Can this request actually persist state?
 *
 * A deploy that leaves state_dir owned by the wrong user is the realistic way
 * this breaks, and it breaks silently: the directory is simply not writable by
 * the web server. Callers use this to refuse a sign-in rather than accept one
 * they cannot record.
 */
function ledger_state_usable(): bool
{
    $dir = ledger_state_dir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return false;
    }

    // A file that exists but cannot be read is just as broken as a directory
    // that cannot be written, and fails the same way.
    try {
        ledger_state_read('totp.json');
        ledger_state_read('login-attempts.json');
    } catch (RuntimeException) {
        return false;
    }
    return true;
}

function ledger_state_read(string $name): array
{
    $path = ledger_state_path($name);

    // Absent is legitimate — nothing has happened yet. Anything else that
    // cannot be read back is not: returning [] there would read as "no
    // failures recorded" and disarm the replay counter for an attempt.
    if (!file_exists($path)) {
        return [];
    }

    // Not a plain file (a directory of the same name, a dangling link) is
    // broken, and file_get_contents on a directory returns '' rather than
    // false, so this has to be checked rather than inferred from the read.
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Cannot read state file ' . $path);
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new RuntimeException('Could not read ' . $path);
    }
    if ($raw === '') {
        return [];   // written but empty: nothing recorded yet
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        // Corrupt. Do not let that read as "nothing has happened".
        throw new RuntimeException('State file is not valid JSON: ' . $path);
    }
    return $data;
}

/** Write, or throw. Never return as though it worked. */
function ledger_state_write(string $name, array $data): void
{
    $path = ledger_state_path($name);
    $written = @file_put_contents($path, json_encode($data), LOCK_EX);
    if ($written === false) {
        throw new RuntimeException('Could not write ' . $path);
    }
    @chmod($path, 0600);
}

/* ------------------------------------------------------- rate limiting -- */


/**
 * A sliding window of recent failures.
 *
 * Timestamps rather than a counter: the old version anchored the window to the
 * FIRST failure, so five wrong codes and a fifteen-minute wait reset it no
 * matter how many attempts came in between. Keeping the times and pruning them
 * means each new failure keeps the window alive, so someone hammering the form
 * stays locked out instead of getting five fresh tries every quarter hour.
 *
 * @return int[] failure timestamps inside the window, oldest first
 */
function ledger_recent_failures(): array
{
    $state = ledger_state_read('login-attempts.json');
    $times = array_map('intval', (array) ($state['failures'] ?? []));
    $cutoff = time() - LEDGER_LOCKOUT;

    $recent = array_values(array_filter($times, static fn(int $t): bool => $t > $cutoff));
    sort($recent);
    return $recent;
}

function ledger_attempt_failed(): void
{
    $recent = ledger_recent_failures();
    $recent[] = time();
    // Never let the file grow without bound.
    ledger_state_write('login-attempts.json', [
        'failures' => array_slice($recent, -(LEDGER_MAX_ATTEMPTS * 4)),
    ]);
}

function ledger_attempts_cleared(): void
{
    ledger_state_write('login-attempts.json', ['failures' => []]);
}

/** Seconds still to wait, or 0 when not locked. */
function ledger_locked_for(): int
{
    try {
        $recent = ledger_recent_failures();
    } catch (RuntimeException) {
        // Cannot tell how many failures there have been. Assume the worst.
        return LEDGER_LOCKOUT;
    }
    if (count($recent) < LEDGER_MAX_ATTEMPTS) {
        return 0;
    }
    // Locked until the newest failure ages out of the window.
    return max(0, (int) end($recent) + LEDGER_LOCKOUT - time());
}

$lockedFor = ledger_locked_for();   // read-only; an unreadable store reads as clear

/* ---------------------------------------------------------- form token -- */

/**
 * Per-session token for the sign-in, sign-out and enrolment forms.
 *
 * Separate from ledger_csrf_token() in ledger-db.php, which guards the
 * Shopping list: this one must work with no database configured at all, since
 * signing in has to be possible while the database is down.
 */
function ledger_form_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        ledger_session_start();
    }
    if (empty($_SESSION['ledger_form_csrf'])) {
        $_SESSION['ledger_form_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['ledger_form_csrf'];
}

/** Constant-time check of a posted form token. */
function ledger_form_token_ok(): bool
{
    $sent = $_POST['csrf'] ?? '';
    return is_string($sent) && hash_equals(ledger_form_token(), $sent);
}

/* --------------------------------------------------------- the gate ----- */


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
