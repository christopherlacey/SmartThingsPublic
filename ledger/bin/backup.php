<?php
/**
 * Take an encrypted backup of the ledger.
 *
 *   php bin/backup.php --to /backups
 *   php bin/backup.php --to /backups --recipient chris@chrislacey.com
 *   php bin/backup.php --to /backups --age-recipient age1ql3z...
 *   php bin/backup.php --to /backups --plain          (refuses unless forced)
 *
 * Two things this gets right that a `cp` of the database does not:
 *
 *   1. It uses SQLite's VACUUM INTO, so the copy is consistent even if a write
 *      lands mid-backup. Copying a live SQLite file with the shell can capture a
 *      torn page and produce a backup that only fails when you need it.
 *
 *   2. It encrypts to a public key, so the backup is safe to put somewhere you
 *      do not fully control — which is the whole point of having one. The
 *      private key is never on the server, so a compromised host cannot read its
 *      own backups.
 *
 * Pair this with full-disk encryption on the host. This protects the copies;
 * disk encryption protects the original.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../src/bootstrap.php';

$config = ledger_config();
date_default_timezone_set($config['timezone']);

function arg(array $argv, string $flag): ?string
{
    $i = array_search($flag, $argv, true);
    return ($i !== false && isset($argv[$i + 1])) ? (string) $argv[$i + 1] : null;
}

function have(string $binary): bool
{
    exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null', $out, $code);
    return $code === 0;
}

$dest = arg($argv, '--to');
if ($dest === null) {
    exit("Usage: php bin/backup.php --to /path/to/backups [--recipient KEY | --age-recipient KEY]\n");
}

if (!is_dir($dest) && !@mkdir($dest, 0700, true)) {
    exit("Cannot create $dest\n");
}

$gpgRecipient = arg($argv, '--recipient');
$ageRecipient = arg($argv, '--age-recipient');
$plain        = in_array('--plain', $argv, true);
$force        = in_array('--force', $argv, true);

if (!$gpgRecipient && !$ageRecipient && !$plain) {
    exit("No recipient given, so the backup would be unencrypted.\n"
       . "Pass --recipient <gpg-key> or --age-recipient <age-key>.\n"
       . "If you really want a plaintext copy (onto an encrypted volume, say), pass --plain --force.\n");
}

if ($plain && !$force) {
    exit("--plain writes your medical and financial records to disk in the clear.\n"
       . "Add --force if that is genuinely what you want.\n");
}

/* ---------------------------------------------------- check the key first - */

// Verify the encryption tool and recipient before a plaintext snapshot exists.
// Checking afterwards would mean every failed run briefly writes the whole
// database to disk in the clear, which is the thing this script exists to avoid.
if ($ageRecipient !== null) {
    if (!have('age')) {
        exit("age is not installed on this host.\n");
    }
    if (!preg_match('/^age1[0-9a-z]+$/', $ageRecipient)) {
        exit("That does not look like an age recipient key (expected age1...).\n");
    }
} elseif ($gpgRecipient !== null) {
    if (!have('gpg')) {
        exit("gpg is not installed on this host.\n");
    }
    exec('gpg --list-keys ' . escapeshellarg($gpgRecipient) . ' 2>&1', $keyOut, $keyCode);
    if ($keyCode !== 0) {
        exit("gpg has no public key for $gpgRecipient on this host.\n"
           . "Import it first: gpg --import <keyfile>\n");
    }
}

/* ------------------------------------------------- consistent snapshot --- */

$stamp    = date('Y-m-d-His');
$snapshot = rtrim($dest, '/') . "/ledger-$stamp.sqlite";

if (file_exists($snapshot)) {
    exit("$snapshot already exists.\n");
}

try {
    // VACUUM INTO takes a read lock and writes a clean, defragmented copy.
    // Requires SQLite 3.27 (2019) or newer.
    db()->exec("VACUUM INTO " . db()->quote($snapshot));
} catch (PDOException $e) {
    exit("Could not snapshot the database: {$e->getMessage()}\n"
       . "If SQLite here is older than 3.27, stop the site and copy the file instead.\n");
}

@chmod($snapshot, 0600);
$bytes = filesize($snapshot) ?: 0;
echo "snapshot   $snapshot (", number_format($bytes), " bytes)\n";

/* -------------------------------------------------------------- encrypt -- */

$final = $snapshot;

if ($ageRecipient !== null) {
    $final = "$snapshot.age";
    $cmd = sprintf('age -r %s -o %s %s',
        escapeshellarg($ageRecipient), escapeshellarg($final), escapeshellarg($snapshot));
    exec($cmd . ' 2>&1', $out, $code);

    if ($code !== 0) {
        unlink($snapshot);
        exit("age failed: " . implode("\n", $out) . "\n");
    }

    unlink($snapshot);

} elseif ($gpgRecipient !== null) {
    $final = "$snapshot.gpg";
    $cmd = sprintf('gpg --batch --yes --trust-model always --encrypt --recipient %s --output %s %s',
        escapeshellarg($gpgRecipient), escapeshellarg($final), escapeshellarg($snapshot));
    exec($cmd . ' 2>&1', $out, $code);

    if ($code !== 0) {
        unlink($snapshot);
        exit("gpg failed: " . implode("\n", $out) . "\n");
    }

    // Remove the plaintext snapshot only once the encrypted one exists.
    unlink($snapshot);
}

@chmod($final, 0600);
echo "backup     $final\n";

/* ---------------------------------------------------------------- prune -- */

// Keep the last 30. Old backups of medical records are a liability, not an
// asset, and an unbounded backup directory is its own kind of leak.
$keep = (int) (arg($argv, '--keep') ?? 30);
$existing = glob(rtrim($dest, '/') . '/ledger-*.sqlite*') ?: [];
sort($existing);

$excess = count($existing) - $keep;
for ($i = 0; $i < $excess; $i++) {
    unlink($existing[$i]);
    echo "pruned     {$existing[$i]}\n";
}

echo "\nKept ", min(count($existing), $keep), " of the last backups.\n";

if ($plain) {
    echo "This one is NOT encrypted. It belongs only on an encrypted volume.\n";
}
