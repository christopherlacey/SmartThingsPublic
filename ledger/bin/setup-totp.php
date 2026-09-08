<?php
/**
 * Turn the second factor on, off, or re-issue recovery codes.
 *
 *   php bin/setup-totp.php            enrol an authenticator
 *   php bin/setup-totp.php --codes    new recovery codes, keeping the same secret
 *   php bin/setup-totp.php --off      turn the second factor off
 *
 * Enrolment will not save a secret until a live code from it has been checked.
 * Writing an unverified secret is how people lock themselves out of their own
 * records, and this database is a bad one to be locked out of.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../src/bootstrap.php';

$config = ledger_config();
date_default_timezone_set($config['timezone']);

$account = q1('SELECT id, username, totp_secret FROM account WHERE id = 1');

if ($account === null) {
    exit("No account yet. Run bin/set-password.php first.\n");
}

function ask(string $label): string
{
    echo $label;
    return trim((string) fgets(STDIN));
}

function print_codes(array $codes): void
{
    echo "\nRecovery codes — each works once:\n\n";
    foreach ($codes as $code) {
        echo "    $code\n";
    }
    echo "\nWrite these down somewhere that is not your phone. They are shown once;\n";
    echo "only their hashes are stored, so they cannot be printed again.\n";
}

/* -------------------------------------------------------------------- off -- */

if (in_array('--off', $argv, true)) {
    if (empty($account['totp_secret'])) {
        exit("The second factor is already off.\n");
    }

    if (strtolower(ask("Turn the second factor OFF? The password alone will guard everything. [y/N] ")) !== 'y') {
        exit("Nothing changed.\n");
    }

    qx('UPDATE account SET totp_secret = NULL WHERE id = 1');
    qx('DELETE FROM recovery_codes');
    log_change('account', 1, 'totp_secret', 'set', null, 'update', null, 'cli');

    exit("Second factor is off, and the recovery codes are gone with it.\n");
}

/* ---------------------------------------------------------------- codes --- */

if (in_array('--codes', $argv, true)) {
    if (empty($account['totp_secret'])) {
        exit("The second factor is off, so there is nothing to recover into.\n");
    }

    print_codes(recovery_codes_generate());
    log_change('account', 1, 'recovery_codes', null, 'reissued', 'update', null, 'cli');
    exit(0);
}

/* ----------------------------------------------------------------- enrol -- */

if (!empty($account['totp_secret'])) {
    if (strtolower(ask("A second factor is already set up. Replace it? [y/N] ")) !== 'y') {
        exit("Nothing changed.\n");
    }
}

$secret = totp_new_secret();
$issuer = "Chris Lacey's Dashboard";
$uri    = totp_uri($secret, (string) $account['username'], $issuer);

echo "\nAdd this to your authenticator app.\n\n";
echo "  Account:  {$account['username']}\n";
echo "  Issuer:   {$issuer}\n";
echo "  Secret:   {$secret}\n\n";
echo "Or paste this URI in, if your app takes one:\n\n";
echo "  {$uri}\n\n";
echo "(No QR code on purpose — rendering one would mean pulling in a dependency\n";
echo " to save one paste. Every authenticator app accepts manual entry.)\n\n";

$code = ask("Now type the six digits it is showing: ");

if (!totp_verify($secret, $code)) {
    exit("\nThat code doesn't match, so nothing has been saved and you are not locked out.\n"
       . "Check your phone's clock is set automatically, then run this again.\n");
}

qx('UPDATE account SET totp_secret = ? WHERE id = 1', [$secret]);
log_change('account', 1, 'totp_secret', null, 'set', 'update', null, 'cli');

echo "\nVerified. The second factor is on.\n";
print_codes(recovery_codes_generate());
