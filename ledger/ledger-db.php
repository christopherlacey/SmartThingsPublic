<?php
/**
 * The Lacey Ledger — database access for the Finance and Shopping tabs.
 *
 * The rest of the site has its own db.php. This file does NOT replace it; it
 * only makes sure the two new tabs get a PDO handle, whichever way you prefer:
 *
 *   1. Reuse the site's existing connection. If db.php already leaves a PDO in
 *      a global ($pdo, $db or $dbh), set LEDGER_REUSE_GLOBAL_PDO to true in
 *      ledger-config.php and that handle is used as-is.
 *   2. Connect on its own using the DSN in ledger-config.php.
 *
 * Either way the handle comes back in the same shape: exceptions on error,
 * associative fetches, real prepared statements.
 */

declare(strict_types=1);

function ledger_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $configFile = __DIR__ . '/ledger-config.php';
    if (!is_readable($configFile)) {
        throw new RuntimeException(
            'ledger-config.php is missing. Copy ledger-config.php.example to '
            . 'ledger-config.php and fill it in.'
        );
    }
    $config = require $configFile;

    // Option 1 — reuse whatever db.php already opened.
    if (!empty($config['reuse_global_pdo'])) {
        foreach (['pdo', 'db', 'dbh'] as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                $pdo = $GLOBALS[$name];
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                return $pdo;
            }
        }
        throw new RuntimeException(
            'reuse_global_pdo is on, but no PDO was found in $pdo/$db/$dbh. '
            . 'Either include db.php before these pages, or turn it off and set a dsn.'
        );
    }

    // Option 2 — connect on our own.
    if (empty($config['dsn'])) {
        throw new RuntimeException('ledger-config.php needs either a dsn or reuse_global_pdo.');
    }

    $pdo = new PDO(
        $config['dsn'],
        $config['user'] ?? null,
        $config['pass'] ?? null,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    if (str_starts_with($config['dsn'], 'sqlite:')) {
        $pdo->exec('PRAGMA foreign_keys = ON');
    }

    return $pdo;
}

/**
 * True when a table exists. The Finance tab uses this to show a helpful
 * "run schema.sql" message instead of a fatal error on a fresh install.
 */
function ledger_has_table(PDO $pdo, string $table): bool
{
    try {
        $pdo->query('SELECT 1 FROM ' . $table . ' LIMIT 1');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** Per-session CSRF token for the Shopping tab's forms. */
function ledger_csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['ledger_csrf'])) {
        $_SESSION['ledger_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['ledger_csrf'];
}

/** Verify a posted CSRF token, or send 400 and stop. */
function ledger_csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals(ledger_csrf_token(), $sent)) {
        http_response_code(400);
        exit('Bad CSRF token. Reload the page and try again.');
    }
}
