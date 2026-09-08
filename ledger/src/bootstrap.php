<?php
/**
 * Boot the ledger: config, database, session, security headers.
 *
 * Every entry point in public/ requires this file first, before it emits a
 * single byte. Pages that hold data call require_login() straight after.
 */

declare(strict_types=1);

const LEDGER_ROOT = __DIR__ . '/..';

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/format.php';
require_once __DIR__ . '/models.php';
require_once __DIR__ . '/layout.php';

/**
 * Load config.php, or die loudly. There is deliberately no fallback: a ledger
 * that boots without configuration would boot without a password.
 */
function ledger_config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $path = LEDGER_ROOT . '/config.php';
    if (!is_readable($path)) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Ledger configuration missing. Copy config.php.example to config.php.\n");
    }

    $config = require $path;

    foreach (['db', 'timezone'] as $required) {
        if (empty($config[$required])) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit("Ledger configuration incomplete: '$required' is not set.\n");
        }
    }

    return $config;
}

function cfg(string $key, mixed $default = null): mixed
{
    return ledger_config()[$key] ?? $default;
}

/**
 * Headers that apply to every response.
 *
 * The CSP is strict and the page is built to live within it: all CSS and JS are
 * external files under assets/, and chart data reaches the page as
 * <script type="application/json"> blocks, which are data and never execute.
 * The two Google Fonts origins are the only third parties allowed, because the
 * brand's typefaces come from there on every other Chris Lacey site.
 */
function ledger_send_headers(): void
{
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' https://fonts.googleapis.com; "
        . "font-src https://fonts.gstatic.com; "
        . "img-src 'self' data: https://chrislacey.com; "
        . "connect-src 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'none'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), interest-cohort=()');

    // This page renders medical and financial records. Nothing about it should
    // survive in a shared cache or in the back/forward cache after sign-out.
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');

    // A private ledger has no business in a search index.
    header('X-Robots-Tag: noindex, nofollow, noarchive');
}

/**
 * Start the session with cookie flags that suit a single-user private site.
 */
function ledger_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $https = (bool) cfg('require_https', true);

    session_name('ledger');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Called once per request by every entry point.
 */
function ledger_boot(): void
{
    $config = ledger_config();
    date_default_timezone_set($config['timezone']);
    ledger_send_headers();
    ledger_start_session();
}
