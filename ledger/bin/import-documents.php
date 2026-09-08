<?php
/**
 * Take in a pile of medical documents, whatever shape they are in.
 *
 *   php bin/import-documents.php --dir /root/records --dry-run
 *   php bin/import-documents.php --dir /root/records
 *   php bin/import-documents.php --dir /root/records --person "Addy Lacey" --kind lab
 *
 * Records arrive as PDFs from one surgery, scans from another, a photo of a
 * letter, a spreadsheet of results. There is no common format and no parser
 * that handles all of them, so this does not try: it files them, indexes them,
 * and makes them retrievable from the Health tab. Structuring the few facts
 * that need to be queryable — medications, allergies — is import-health.php's
 * job, and can happen later.
 *
 * Files are copied to the documents directory from config.php, which sits
 * outside the web root. The stored filename is generated here; nothing derived
 * from the original name is ever used to build a path.
 *
 * Re-running over the same directory is safe: content is hashed, and a file
 * already on record is reported and skipped rather than duplicated.
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

$dir    = arg($argv, '--dir');
$dryRun = in_array('--dry-run', $argv, true);
$kind   = arg($argv, '--kind') ?? 'record';

if ($dir === null) {
    exit("Usage: php bin/import-documents.php --dir /path/to/records [--dry-run]\n"
       . "       [--person 'Full Name'] [--kind record|lab|imaging|letter|prescription|insurance|note|other]\n");
}

if (!is_dir($dir)) {
    exit("Not a directory: $dir\n");
}

$allowedKinds = ['record', 'lab', 'imaging', 'letter', 'prescription', 'insurance', 'note', 'other'];
if (!in_array($kind, $allowedKinds, true)) {
    exit("--kind must be one of: " . implode(', ', $allowedKinds) . "\n");
}

/* ----------------------------------------------------------------- who --- */

$personName = arg($argv, '--person');
$person = $personName !== null
    ? q1('SELECT * FROM people WHERE name = ? COLLATE NOCASE', [$personName])
    : self_person();

if ($person === null) {
    exit($personName !== null
        ? "Nobody on file is named '$personName'.\n"
        : "No row is flagged is_self. Pass --person 'Full Name'.\n");
}

$personId = (int) $person['id'];

/* ---------------------------------------------------------------- where --- */

$store = rtrim((string) cfg('documents_dir', dirname((string) cfg('db')) . '/documents'), '/');

// The store holds medical documents. It must not be somewhere a web server will
// hand out on request.
if (str_contains($store, '/public/')) {
    exit("documents_dir is inside the web root:\n    $store\nMove it outside public/ first.\n");
}

if (!is_dir($store) && !$dryRun && !@mkdir($store, 0700, true)) {
    exit("Cannot create $store\n");
}
if (is_dir($store)) {
    @chmod($store, 0700);
}

/* ------------------------------------------------------------- guessing --- */

/** Pull a date out of a filename if one is plainly there. */
function guess_date(string $name): ?string
{
    // 2026-09-08, 2026_09_08, 20260908
    if (preg_match('/(20\d{2})[-_]?(\d{2})[-_]?(\d{2})/', $name, $m)) {
        $candidate = "$m[1]-$m[2]-$m[3]";
        $t = DateTimeImmutable::createFromFormat('Y-m-d', $candidate);
        if ($t !== false && $t->format('Y-m-d') === $candidate) {
            return $candidate;
        }
    }
    return null;
}

/** A readable title from a filename, without inventing anything. */
function guess_title(string $name): string
{
    $title = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $name) ?? $name;
    $title = str_replace(['_', '-'], ' ', $title);
    $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);
    return $title === '' ? $name : mb_substr($title, 0, 200);
}

function mime_of(string $path): string
{
    if (function_exists('finfo_open')) {
        $f = finfo_open(FILEINFO_MIME_TYPE);
        if ($f !== false) {
            $m = finfo_file($f, $path);
            finfo_close($f);
            if (is_string($m) && $m !== '') {
                return $m;
            }
        }
    }
    return 'application/octet-stream';
}

/* -------------------------------------------------------------- collect --- */

$found = [];
$iter = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
);

foreach ($iter as $file) {
    /** @var SplFileInfo $file */
    if (!$file->isFile() || $file->getSize() === 0) {
        continue;
    }

    $name = $file->getFilename();
    if (str_starts_with($name, '.')) {
        continue;   // .DS_Store and friends
    }

    $found[] = $file->getPathname();
}

sort($found);

if (!$found) {
    exit("No files under $dir\n");
}

echo "Filing into: {$person['name']}\n";
echo "Store:       $store\n\n";

$new = 0;
$dupe = 0;
$bytes = 0;

foreach ($found as $path) {
    $hash = hash_file('sha256', $path);
    if ($hash === false) {
        echo "  ! unreadable: $path\n";
        continue;
    }

    $existing = q1('SELECT id, title FROM documents WHERE sha256 = ?', [$hash]);

    if ($existing !== null) {
        $dupe++;
        echo "  = already on file: " . basename($path) . "\n";
        continue;
    }

    $original = basename($path);
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    // Only characters that are safe in a filename, and the name is generated
    // rather than taken from input.
    $ext = preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'bin';
    $stored = substr($hash, 0, 32) . '.' . $ext;

    $size = (int) filesize($path);
    $new++;
    $bytes += $size;

    printf("  + %-52s %8s KB\n", mb_substr($original, 0, 52), number_format($size / 1024, 0));

    if ($dryRun) {
        continue;
    }

    if (!@copy($path, "$store/$stored")) {
        echo "  ! could not copy into the store: $original\n";
        $new--;
        continue;
    }
    @chmod("$store/$stored", 0600);

    qx('INSERT INTO documents (person_id, title, doc_date, kind, original_name,
                               stored_name, mime, bytes, sha256, provider, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $personId,
            guess_title($original),
            guess_date($original),
            $kind,
            mb_substr($original, 0, 255),
            $stored,
            mime_of($path),
            $size,
            $hash,
            arg($argv, '--provider'),
            null,
        ]);

    log_change('documents', (int) db()->lastInsertId(), 'title', null,
        guess_title($original), 'import', $personId, 'document-import');
}

echo "\n";
printf("%d new, %d already on file, %s MB\n", $new, $dupe, number_format($bytes / 1048576, 1));

if ($dryRun) {
    echo "\nDRY RUN — nothing copied or recorded. Drop --dry-run to file them.\n";
    exit(0);
}

echo "\nFiled. They are listed on the Health tab, and downloadable only after sign-in.\n";
echo "Titles and dates are guessed from filenames — correct anything wrong in the app.\n";
echo "Once you have checked them, the originals can go:\n";
echo "    shred -u " . escapeshellarg($dir) . "/*\n";
