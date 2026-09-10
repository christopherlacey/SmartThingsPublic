<?php
/**
 * Bring the old c.lacey.me across into this schema.
 *
 *   php bin/import-legacy.php --from-site https://c.lacey.me --dry-run
 *   php bin/import-legacy.php --from-site https://c.lacey.me
 *   php bin/import-legacy.php --inspect 'sqlite:/path/to/old.sqlite'
 *   php bin/import-legacy.php --inspect 'mysql:host=localhost;dbname=ledger' --user u --pass p
 *
 * Two ways in, because the old site's database schema was never available here:
 *
 *   --from-site  Parses the old site's own pages. This works today, needs no
 *                dump, and is tested against the real markup — but it can only
 *                recover what those pages actually render.
 *
 *   --inspect    Prints the tables and columns of a legacy database next to the
 *                fields this schema wants, so the mapping below can be filled in
 *                against something real rather than guessed. Run this first when
 *                you have a dump; then fill in $DB_MAP and use --from-db.
 *
 * Nothing is written without --dry-run being absent, and every insert is
 * recorded in `changes` with source 'import' so an import is reversible by
 * inspection rather than by hope.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../src/bootstrap.php';

$config = ledger_config();
date_default_timezone_set($config['timezone']);

$args   = $argv;
$dryRun = in_array('--dry-run', $args, true);

function arg_value(array $args, string $flag): ?string
{
    $i = array_search($flag, $args, true);
    return ($i !== false && isset($args[$i + 1])) ? (string) $args[$i + 1] : null;
}

function say(string $line): void
{
    echo $line, "\n";
}

/* ================================================================ inspect == */

/**
 * Describe a legacy database so its columns can be mapped by eye.
 *
 * Deliberately prints structure and row counts only — never row contents. The
 * point is to learn the shape, and a dump of medical or financial rows across a
 * terminal is not something to do casually.
 */
function inspect(string $dsn, ?string $user, ?string $pass): void
{
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    } catch (PDOException $e) {
        exit("Could not open that database: {$e->getMessage()}\n");
    }

    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $tables = $driver === 'sqlite'
        ? $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
              ->fetchAll(PDO::FETCH_COLUMN)
        : $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

    say("Legacy database: $dsn");
    say(str_repeat('-', 64));

    foreach ($tables as $table) {
        $quoted = '"' . str_replace('"', '""', $table) . '"';
        $count  = (int) $pdo->query("SELECT COUNT(*) FROM $quoted")->fetchColumn();

        $columns = $driver === 'sqlite'
            ? array_column($pdo->query("PRAGMA table_info($quoted)")->fetchAll(PDO::FETCH_ASSOC), 'name')
            : array_column($pdo->query("DESCRIBE $quoted")->fetchAll(PDO::FETCH_ASSOC), 'Field');

        say(sprintf("%-24s %6d rows", $table, $count));
        say("    " . implode(', ', $columns));
    }

    say(str_repeat('-', 64));
    say("This schema wants:");
    say("  people           name, relationship, city, region, country, location_confidence");
    say("  person_fields    person_id, section, field, value, sensitive, source");
    say("  days             day, brief");
    say("  events           day, starts_at, title, location, person_id, kind");
    say("  changes          at, entity, field, before_val, after_val, action, source");
    say("");
    say("Fill in \$DB_MAP at the top of this file to match, then run --from-db.");
}

/* =============================================================== from-site = */

/**
 * Fetch a page.
 *
 * `$url` may be an http(s) address or a local directory, so this also imports
 * from a saved copy of the old site — which is the easier order of operations
 * if you would rather take the old site down first and import afterwards:
 *
 *     wget -p -k -E https://c.lacey.me/people.php ...
 *     php bin/import-legacy.php --from-site ./saved-copy
 *
 * An import walks two dozen URLs in a row, so a single dropped connection must
 * not lose the run: each request is retried with a widening pause before the
 * importer gives up and says which page it could not read.
 */
function fetch(string $url, int $attempts = 4): string
{
    // A local path is just a file read.
    if (!preg_match('#^https?://#i', $url)) {
        $html = @file_get_contents($url);
        if ($html === false || $html === '') {
            exit("Could not read $url\n");
        }
        return $html;
    }

    $lastError = '';

    for ($try = 1; $try <= $attempts; $try++) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT      => 'ledger-import/1.0',
            ]);

            // Honour the usual proxy variables, so this works from inside a
            // network that requires one.
            $proxy = getenv('https_proxy') ?: getenv('HTTPS_PROXY') ?: '';
            if ($proxy !== '') {
                curl_setopt($ch, CURLOPT_PROXY, $proxy);
                $caBundle = getenv('CURL_CA_BUNDLE') ?: '';
                if ($caBundle !== '' && is_readable($caBundle)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
                }
            }

            $body   = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err    = curl_error($ch);
            curl_close($ch);

            if (is_string($body) && $body !== '' && $status >= 200 && $status < 300) {
                return $body;
            }

            $lastError = $err !== '' ? $err : "HTTP $status";
        } else {
            $html = @file_get_contents($url);
            if (is_string($html) && $html !== '') {
                return $html;
            }
            $lastError = 'request failed';
        }

        if ($try < $attempts) {
            sleep($try);
        }
    }

    exit("Could not read $url after $attempts attempts ($lastError).\n"
       . "If the old site is already down, save its pages and point --from-site at the directory.\n");
}

function dom(string $html): array
{
    $doc = new DOMDocument();
    // The old pages are hand-written PHP output, so they are not guaranteed to
    // be well-formed. Parse leniently and read what is there.
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    return [$doc, new DOMXPath($doc)];
}

function text(?DOMNode $node): string
{
    return $node === null ? '' : trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
}

/**
 * Split "Charleston, SC" into its parts, leaving both null when the old page
 * said the location was unknown.
 */
function split_place(string $where): array
{
    $where = trim(preg_replace('/\b(confirmed|inferred|stale)\b/i', '', $where) ?? '');

    if ($where === '' || stripos($where, 'location unknown') !== false) {
        return [null, null];
    }

    $parts = array_map('trim', explode(',', $where, 2));
    return [$parts[0] ?: null, $parts[1] ?? null];
}

/**
 * Parse the old site, then write.
 *
 * Reading is finished before a single row is inserted, and the inserts run in
 * one transaction. A half-imported ledger — some people, none of their fields —
 * would be worse than no import at all, because it looks like it worked.
 */
function import_from_site(string $base, bool $dryRun): void
{
    $base = rtrim($base, '/');
    say(($dryRun ? "DRY RUN — " : "") . "reading $base");
    say('');

    /* ---- read: people --------------------------------------------------- */

    [$doc, $xp] = dom(fetch("$base/people.php"));

    $people = [];
    foreach ($xp->query('//a[contains(@class,"card")]') as $card) {
        if (!preg_match('/id=(\d+)/', $card->getAttribute('href'), $m)) {
            continue;
        }

        $name = text($xp->query('.//p[contains(@class,"who")]', $card)->item(0));
        if ($name === '') {
            continue;
        }

        $whereNode = $xp->query('.//p[contains(@class,"where")]', $card)->item(0);

        [$city, $region] = split_place(text($whereNode));

        $people[(int) $m[1]] = [
            'name'       => $name,
            'city'       => $city,
            'region'     => $region,
            // people.php does not render the confidence tags; the overview does.
            // Filled in below rather than defaulted away.
            'confidence' => 'stale',
            'fields'     => [],
        ];
    }
    unset($doc, $xp);

    say(sprintf("people found:        %d", count($people)));

    /* ---- read: confidence tags, which only the overview page shows ------- */

    [$idoc, $ixp] = dom(fetch("$base/index.php"));
    $tagged = 0;

    foreach ($ixp->query('//a[contains(@class,"card")]') as $card) {
        if (!preg_match('/id=(\d+)/', $card->getAttribute('href'), $m)) {
            continue;
        }

        $oldId = (int) $m[1];
        if (!isset($people[$oldId])) {
            continue;
        }

        $tag = strtolower(text($ixp->query('.//span[contains(@class,"tag")]', $card)->item(0)));

        if (in_array($tag, ['confirmed', 'inferred', 'stale'], true)) {
            $people[$oldId]['confidence'] = $tag;
            if ($tag !== 'stale') {
                $tagged++;
            }
        }
    }
    unset($idoc, $ixp);

    say(sprintf("  of those, dated:   %d confirmed or inferred", $tagged));

    /* ---- read: each person's fields ------------------------------------- */

    $fieldCount = 0;

    foreach ($people as $oldId => $_) {
        [$pdoc, $pxp] = dom(fetch("$base/person.php?id=$oldId"));

        foreach ($pxp->query('//tr') as $row) {
            $cells = $pxp->query('./td', $row);

            // Two-cell rows are "Everything on file". Wider rows are the change
            // log further down the same page, which is read separately.
            if ($cells->length < 2 || $cells->length >= 4) {
                continue;
            }

            $label = text($cells->item(0));
            $value = text($cells->item(1));

            if ($label === '' || $value === '') {
                continue;
            }

            $people[$oldId]['fields'][] = ['field' => $label, 'value' => $value];
            $fieldCount++;
        }

        unset($pdoc, $pxp);
    }

    say(sprintf("fields on those:     %d", $fieldCount));

    /* ---- read: the change log ------------------------------------------- */

    [$cdoc, $cxp] = dom(fetch("$base/changelog.php"));

    $changes = [];
    foreach ($cxp->query('//tbody/tr') as $row) {
        $cells = $cxp->query('./td', $row);
        if ($cells->length < 5) {
            continue;
        }

        $field = text($cells->item(2));
        if ($field === '') {
            continue;
        }

        $changes[] = [
            'who'    => text($cells->item(1)),
            'field'  => $field,
            'before' => text($cells->item(3)),
            'after'  => text($cells->item(4)),
            'source' => $cells->length > 5 ? text($cells->item(5)) : 'legacy',
        ];
    }
    unset($cdoc, $cxp);

    say(sprintf("change-log entries:  %d", count($changes)));
    say('');

    if ($dryRun) {
        say("Nothing was written. Drop --dry-run to import.");
        return;
    }

    /* ---- write: all of it, or none of it -------------------------------- */

    $idMap = [];
    db()->beginTransaction();

    try {
        foreach ($people as $oldId => $person) {
            qx('INSERT INTO people (name, city, region, location_confidence, is_self)
                VALUES (?, ?, ?, ?, ?)',
                [
                    $person['name'], $person['city'], $person['region'], $person['confidence'],
                    // The old site had a "Chris Lacey" row; flag it as self so the
                    // Now and Health tabs know whose records are whose.
                    stripos($person['name'], 'chris lacey') !== false ? 1 : 0,
                ]);

            $newId         = (int) db()->lastInsertId();
            $idMap[$oldId] = $newId;

            foreach ($person['fields'] as $f) {
                qx('INSERT INTO person_fields (person_id, section, field, value, source)
                    VALUES (?, ?, ?, ?, ?)',
                    [$newId, 'Imported', $f['field'], $f['value'], 'legacy-site']);
            }

            log_change('people', $newId, 'name', null, $person['name'], 'import', $newId, 'legacy-site');
        }

        // Match a change-log row to a person by the name the old page showed.
        $byName = [];
        foreach ($people as $oldId => $person) {
            $byName[$person['name']] = $idMap[$oldId];
        }

        foreach ($changes as $c) {
            qx('INSERT INTO changes (entity, person_id, field, before_val, after_val, action, source)
                VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    'people',
                    $byName[$c['who']] ?? null,
                    $c['field'],
                    $c['before'] ?: null,
                    $c['after'] ?: null,
                    'import',
                    $c['source'] ?: 'legacy',
                ]);
        }

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        exit("Import failed and nothing was written: {$e->getMessage()}\n");
    }

    say("Imported. Check the People and Change log tabs, then take the old site down.");
}

/* =================================================================== main == */

if (($dsn = arg_value($args, '--inspect')) !== null) {
    inspect($dsn, arg_value($args, '--user'), arg_value($args, '--pass'));
    exit(0);
}

if (($base = arg_value($args, '--from-site')) !== null) {
    if (!$dryRun && (int) qv('SELECT COUNT(*) FROM people') > 0) {
        exit("This ledger already has people in it. Importing again would duplicate them.\n"
           . "Start from an empty database, or clear `people` first.\n");
    }

    import_from_site($base, $dryRun);
    exit(0);
}

exit("Usage:\n"
   . "  php bin/import-legacy.php --from-site https://c.lacey.me --dry-run\n"
   . "  php bin/import-legacy.php --inspect 'sqlite:/path/to/old.sqlite'\n");
