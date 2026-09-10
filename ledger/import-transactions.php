#!/usr/bin/env php
<?php
/**
 * The Lacey Ledger — import a bank/card CSV export into `transactions`.
 *
 *   php import-transactions.php statement.csv
 *   php import-transactions.php statement.csv --account="Joint checking" --dry-run
 *
 * Banks all export different column names, so the importer sniffs the header
 * row and maps what it recognises. Recognised, case-insensitively:
 *
 *   date          date, posted, posted date, transaction date, post date
 *   description   description, name, payee, memo, details, merchant name
 *   amount        amount, value          (signed: negative = money out)
 *   or a pair     debit + credit         (two columns, both positive)
 *   merchant      merchant, payee
 *   category      category, type
 *   account       account, account name
 *   id            id, transaction id, reference, fitid
 *
 * Re-running the same file is safe: rows carrying a source id are matched on
 * it, and rows without one are matched on date + amount + description, so an
 * overlapping export tops up rather than duplicates.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require __DIR__ . '/ledger-db.php';

/* ------------------------------------------------------------------ args -- */

$argvRest = array_slice($argv, 1);
$file     = null;
$account  = null;
$source   = 'csv';
$dryRun   = false;

foreach ($argvRest as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif (str_starts_with($arg, '--account=')) {
        $account = substr($arg, 10);
    } elseif (str_starts_with($arg, '--source=')) {
        $source = substr($arg, 9);
    } elseif ($file === null) {
        $file = $arg;
    }
}

if ($file === null || !is_readable($file)) {
    fwrite(STDERR, "Usage: php import-transactions.php <file.csv> [--account=NAME] [--source=NAME] [--dry-run]\n");
    exit(2);
}

/* --------------------------------------------------------------- mapping -- */

const COLUMN_ALIASES = [
    'date'        => ['date', 'posted', 'posted date', 'post date', 'transaction date', 'posting date'],
    'description' => ['description', 'name', 'payee', 'memo', 'details', 'transaction', 'merchant name'],
    'amount'      => ['amount', 'value', 'transaction amount'],
    'debit'       => ['debit', 'withdrawal', 'withdrawals', 'money out'],
    'credit'      => ['credit', 'deposit', 'deposits', 'money in'],
    'merchant'    => ['merchant', 'payee'],
    'category'    => ['category', 'type', 'classification'],
    'account'     => ['account', 'account name', 'account number'],
    'external_id' => ['id', 'transaction id', 'reference', 'ref', 'fitid'],
];

/** Header label -> canonical field. */
function map_headers(array $header): array
{
    $map = [];
    foreach ($header as $i => $raw) {
        $key = strtolower(trim((string) $raw));
        $key = trim($key, "\xEF\xBB\xBF \t\"'");
        foreach (COLUMN_ALIASES as $field => $aliases) {
            if (in_array($key, $aliases, true) && !isset($map[$field])) {
                $map[$field] = $i;
                break;
            }
        }
    }
    return $map;
}

/** "$1,234.56", "(45.00)", "-45.00" -> cents. Parentheses mean negative. */
function to_cents(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $neg = str_contains($raw, '(') || str_starts_with($raw, '-');
    $num = preg_replace('/[^0-9.]/', '', $raw);
    if ($num === '' || !is_numeric($num)) {
        return null;
    }
    $cents = (int) round(((float) $num) * 100);
    return $neg ? -$cents : $cents;
}

/** Accept the date formats banks actually emit. */
function to_date(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    foreach (['Y-m-d', 'm/d/Y', 'd/m/Y', 'm/d/y', 'd-M-Y', 'Y/m/d', 'M j, Y', 'd M Y'] as $fmt) {
        $d = DateTimeImmutable::createFromFormat($fmt, $raw);
        if ($d instanceof DateTimeImmutable) {
            return $d->format('Y-m-d');
        }
    }
    $ts = strtotime($raw);
    return $ts === false ? null : date('Y-m-d', $ts);
}

/* ------------------------------------------------------------------ run --- */

$pdo = ledger_db();

$fh = fopen($file, 'r');
if ($fh === false) {
    fwrite(STDERR, "Could not open {$file}\n");
    exit(1);
}

$header = fgetcsv($fh);
if ($header === false) {
    fwrite(STDERR, "Empty file.\n");
    exit(1);
}

$map = map_headers($header);
foreach (['date', 'description'] as $required) {
    if (!isset($map[$required])) {
        fwrite(STDERR, "Could not find a '{$required}' column in: " . implode(', ', $header) . "\n");
        exit(1);
    }
}
if (!isset($map['amount']) && !isset($map['debit']) && !isset($map['credit'])) {
    fwrite(STDERR, "Need an 'amount' column, or a 'debit'/'credit' pair.\n");
    exit(1);
}

$byId = $pdo->prepare('SELECT id FROM transactions WHERE external_id = :x');
$byRow = $pdo->prepare(
    'SELECT id FROM transactions
      WHERE posted_on = :d AND amount_cents = :a AND description = :s
      LIMIT 1'
);
$insert = $pdo->prepare(
    'INSERT INTO transactions
        (posted_on, amount_cents, description, merchant, category, account, source, external_id)
     VALUES (:d, :a, :s, :m, :c, :acct, :src, :x)'
);

$added = $skipped = $bad = 0;
$line = 1;

if (!$dryRun) {
    $pdo->beginTransaction();
}

while (($row = fgetcsv($fh)) !== false) {
    $line++;
    $get = static fn(string $f): string => isset($map[$f], $row[$map[$f]]) ? trim((string) $row[$map[$f]]) : '';

    $date = to_date($get('date'));
    $desc = $get('description');

    if ($date === null || $desc === '') {
        $bad++;
        continue;
    }

    if (isset($map['amount'])) {
        $cents = to_cents($get('amount'));
    } else {
        $debit  = to_cents($get('debit'));
        $credit = to_cents($get('credit'));
        // Debit columns are written positive; money out is negative here.
        $cents = $debit !== null && $debit !== 0 ? -abs($debit)
               : ($credit !== null ? abs($credit) : null);
    }

    if ($cents === null) {
        $bad++;
        continue;
    }

    $extId = $get('external_id') ?: null;

    if ($extId !== null) {
        $byId->execute([':x' => $extId]);
        if ($byId->fetchColumn() !== false) {
            $skipped++;
            continue;
        }
    } else {
        $byRow->execute([':d' => $date, ':a' => $cents, ':s' => $desc]);
        if ($byRow->fetchColumn() !== false) {
            $skipped++;
            continue;
        }
    }

    if (!$dryRun) {
        $insert->execute([
            ':d'    => $date,
            ':a'    => $cents,
            ':s'    => mb_substr($desc, 0, 255),
            ':m'    => mb_substr($get('merchant'), 0, 160) ?: null,
            ':c'    => mb_substr($get('category'), 0, 60) ?: null,
            ':acct' => mb_substr($account ?? $get('account'), 0, 80) ?: null,
            ':src'  => $source,
            ':x'    => $extId,
        ]);
    }
    $added++;
}

if (!$dryRun) {
    $pdo->commit();
}
fclose($fh);

printf(
    "%s  %d added, %d already present, %d unreadable\n",
    $dryRun ? 'Dry run —' : 'Imported —',
    $added, $skipped, $bad
);
