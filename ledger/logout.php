<?php
/**
 * The Lacey Ledger — sign out. Drops the session here; the Google session
 * itself is untouched, which is why the sign-in asks which account to use.
 */

declare(strict_types=1);

require_once __DIR__ . '/ledger-auth.php';

ledger_session_start();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Location: /login.php', true, 302);
exit;
