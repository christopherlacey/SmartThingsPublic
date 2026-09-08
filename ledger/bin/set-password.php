<?php
/**
 * Set or change the sign-in password.
 *
 *   php bin/set-password.php chris
 *
 * The password is read from the terminal with echo turned off and is never
 * passed as an argument, so it does not end up in shell history or in the
 * process list. Only the hash is stored.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../src/bootstrap.php';

$config = ledger_config();
date_default_timezone_set($config['timezone']);

$username = $argv[1] ?? '';
if ($username === '') {
    exit("Usage: php bin/set-password.php <username>\n");
}

/** Read a line from the terminal without echoing it back. */
function prompt_secret(string $label): string
{
    echo $label;

    $usedStty = false;
    if (function_exists('shell_exec') && stripos(PHP_OS_FAMILY, 'win') === false) {
        $stty = shell_exec('stty -g 2>/dev/null');
        if ($stty) {
            shell_exec('stty -echo');
            $usedStty = true;
        }
    }

    $value = rtrim((string) fgets(STDIN), "\r\n");

    if ($usedStty) {
        shell_exec('stty ' . trim((string) $stty));
    }

    echo "\n";
    return $value;
}

$password = prompt_secret('New password: ');
$confirm  = prompt_secret('Again: ');

if ($password === '') {
    exit("Empty password. Nothing changed.\n");
}
if ($password !== $confirm) {
    exit("They don't match. Nothing changed.\n");
}
if (strlen($password) < 12) {
    // This one password is the only thing between the open internet and a file
    // of medical and financial records, so the floor is higher than usual.
    exit("Use at least 12 characters. Nothing changed.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

if (q1('SELECT id FROM account WHERE id = 1') === null) {
    qx('INSERT INTO account (id, username, password_hash) VALUES (1, ?, ?)', [$username, $hash]);
    echo "Account created for '$username'.\n";
} else {
    qx('UPDATE account SET username = ?, password_hash = ?, password_set_at = datetime("now") WHERE id = 1',
        [$username, $hash]);
    echo "Password updated for '$username'.\n";
}

// Any locked-out state from before the change is no longer meaningful.
qx('DELETE FROM login_attempts');
