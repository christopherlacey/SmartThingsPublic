<?php
/**
 * Time-based one-time passwords (RFC 6238) and single-use recovery codes.
 *
 * Written out rather than pulled from a package: it is about a hundred lines of
 * standard library calls, and a dashboard holding medical and financial records
 * is a poor place to take on a dependency chain for the sake of them.
 *
 * The secret is stored in the `account` row. A null secret means the second
 * factor is off, which is how the site behaves before bin/setup-totp.php is run.
 */

declare(strict_types=1);

const TOTP_PERIOD  = 30;   // seconds per code
const TOTP_DIGITS  = 6;
const TOTP_WINDOW  = 1;    // accept one step either side, for clock drift

/* ---------------------------------------------------------------- base32 -- */

/** RFC 4648 base32, which is what authenticator apps expect. */
function base32_encode_secret(string $binary): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';

    for ($i = 0, $n = strlen($binary); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
    }

    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }

    return $out;
}

function base32_decode_secret(string $base32): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // Authenticator apps display the secret in spaced groups and users paste it
    // back that way, so normalise before decoding rather than rejecting it.
    $base32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $base32) ?? '');

    $bits = '';
    for ($i = 0, $n = strlen($base32); $i < $n; $i++) {
        $index = strpos($alphabet, $base32[$i]);
        if ($index === false) {
            continue;
        }
        $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
    }

    $binary = '';
    foreach (str_split($bits, 8) as $chunk) {
        if (strlen($chunk) === 8) {
            $binary .= chr(bindec($chunk));
        }
    }

    return $binary;
}

/* ------------------------------------------------------------------ totp -- */

/** A fresh 160-bit secret, base32 encoded. */
function totp_new_secret(): string
{
    return base32_encode_secret(random_bytes(20));
}

/** The code for one time step. */
function totp_code(string $base32Secret, int $counter): string
{
    $key  = base32_decode_secret($base32Secret);
    $hash = hash_hmac('sha1', pack('N*', 0, $counter), $key, true);

    // Dynamic truncation, RFC 4226 §5.4.
    $offset = ord($hash[19]) & 0x0f;
    $value  = (
        ((ord($hash[$offset])     & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8)  |
         (ord($hash[$offset + 3]) & 0xff)
    ) % (10 ** TOTP_DIGITS);

    return str_pad((string) $value, TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Check a code against the current time step and one either side.
 *
 * Compared with hash_equals so a wrong code takes the same time as a right one.
 */
function totp_verify(string $base32Secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== TOTP_DIGITS) {
        return false;
    }

    $now = (int) floor(time() / TOTP_PERIOD);

    for ($drift = -TOTP_WINDOW; $drift <= TOTP_WINDOW; $drift++) {
        if (hash_equals(totp_code($base32Secret, $now + $drift), $code)) {
            return true;
        }
    }

    return false;
}

/**
 * Verify a code and burn it.
 *
 * totp_verify() alone accepts any code inside the drift window, which means a
 * code read over someone's shoulder — or typed into a convincing copy of this
 * sign-in page — stays good for another minute and a half. Recording the time
 * step each accepted code came from, and refusing anything at or below it,
 * makes every code single-use.
 *
 * The window is still walked oldest-first so a code typed slowly, at the step
 * before the current one, is accepted the once.
 */
function totp_verify_once(string $base32Secret, string $code, int $accountId): bool
{
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== TOTP_DIGITS) {
        return false;
    }

    $now  = (int) floor(time() / TOTP_PERIOD);
    $last = (int) qv('SELECT totp_last_counter FROM account WHERE id = ?', [$accountId]);

    for ($drift = -TOTP_WINDOW; $drift <= TOTP_WINDOW; $drift++) {
        $counter = $now + $drift;

        // Already spent, or older than one that was.
        if ($counter <= $last) {
            continue;
        }

        if (hash_equals(totp_code($base32Secret, $counter), $code)) {
            qx('UPDATE account SET totp_last_counter = ? WHERE id = ?', [$counter, $accountId]);
            return true;
        }
    }

    return false;
}

/** The otpauth:// URI an authenticator app enrols from. */
function totp_uri(string $base32Secret, string $account, string $issuer): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
         . '?secret=' . $base32Secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1'
         . '&digits=' . TOTP_DIGITS
         . '&period=' . TOTP_PERIOD;
}

/* -------------------------------------------------------- recovery codes -- */

/**
 * Generate recovery codes and store only their hashes.
 *
 * These are the way back in when the phone is lost or wiped. Without them,
 * turning on a second factor is one broken screen away from being locked out of
 * your own medical records.
 *
 * Returns the plaintext codes — the only time they exist in readable form.
 */
function recovery_codes_generate(int $count = 10): array
{
    qx('DELETE FROM recovery_codes');

    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        // Grouped for legibility when they're written down, which they will be.
        $raw  = strtoupper(bin2hex(random_bytes(5)));
        $code = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);

        qx('INSERT INTO recovery_codes (code_hash) VALUES (?)',
            [password_hash($code, PASSWORD_DEFAULT)]);

        $codes[] = $code;
    }

    return $codes;
}

/**
 * Spend a recovery code. Each one works exactly once.
 */
function recovery_code_consume(string $entered): bool
{
    $entered = strtoupper(trim($entered));
    if ($entered === '') {
        return false;
    }

    foreach (q('SELECT id, code_hash FROM recovery_codes WHERE used_at IS NULL') as $row) {
        if (password_verify($entered, $row['code_hash'])) {
            qx('UPDATE recovery_codes SET used_at = datetime("now") WHERE id = ?', [$row['id']]);
            log_change('recovery_codes', (int) $row['id'], 'used_at', null, date('c'), 'update', null, 'login');
            return true;
        }
    }

    return false;
}

function recovery_codes_remaining(): int
{
    return (int) qv('SELECT COUNT(*) FROM recovery_codes WHERE used_at IS NULL');
}

/** True when the account has a second factor switched on. */
function totp_enabled(): bool
{
    $secret = qv('SELECT totp_secret FROM account WHERE id = 1');
    return is_string($secret) && $secret !== '';
}
