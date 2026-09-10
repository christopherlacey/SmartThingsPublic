<?php
/**
 * Load medical records into the dashboard from a file on this server.
 *
 *   php bin/import-health.php --file /root/records.json --dry-run
 *   php bin/import-health.php --file /root/records.json
 *   php bin/import-health.php --vitals-csv /root/weights.csv --metric weight --unit lb
 *
 * The design point: the records never leave the machine they belong on. This
 * reads a file you put on your own server over scp. Nothing is fetched over the
 * network, nothing goes near the git repository — which is a public fork — and
 * nothing has to be pasted into a chat window to get here.
 *
 * JSON shape (every section optional, every field but the first optional):
 *
 * {
 *   "person": "Chris Lacey",
 *   "medications":  [{"name":"…","dose":"…","schedule":"…","purpose":"…",
 *                     "prescriber":"…","pharmacy":"…","started_on":"YYYY-MM-DD",
 *                     "ended_on":null,"refill_due":"YYYY-MM-DD","note":"…"}],
 *   "conditions":   [{"name":"…","kind":"condition|allergy|surgery|immunization",
 *                     "detail":"…","severity":"…","noted_on":"YYYY-MM-DD"}],
 *   "appointments": [{"what":"…","provider":"…","place":"…","on_day":"YYYY-MM-DD",
 *                     "at_time":"HH:MM","status":"scheduled","note":"…"}],
 *   "vitals":       [{"metric":"weight","value":182.4,"unit":"lb",
 *                     "measured_on":"YYYY-MM-DD","source":"…","note":"…"}]
 * }
 *
 * HDA's own medication shape is accepted too: `dosage` is read as `dose`, and
 * `source_file` as `note`.
 *
 * Everything is validated and counted first, then written in one transaction.
 * A partial load of a medication list is worse than none, because it looks
 * complete.
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

$dryRun = in_array('--dry-run', $argv, true);
$file   = arg($argv, '--file');
$csv    = arg($argv, '--vitals-csv');

if ($file === null && $csv === null) {
    exit("Usage:\n"
       . "  php bin/import-health.php --file /path/records.json [--dry-run]\n"
       . "  php bin/import-health.php --vitals-csv /path/vitals.csv --metric weight --unit lb\n\n"
       . "The file must already be on this server. Do not paste records into a chat,\n"
       . "and do not put them in the git repository — it is a public fork.\n");
}

/* ------------------------------------------------------------ who it's for */

$personName = arg($argv, '--person');

if ($personName !== null) {
    $person = q1('SELECT * FROM people WHERE name = ? COLLATE NOCASE', [$personName]);
} else {
    $person = self_person();
}

if ($person === null) {
    exit("No matching person on file.\n"
       . ($personName !== null
            ? "Nobody is named '$personName'. Check the People tab for the exact spelling.\n"
            : "No row is flagged is_self. Pass --person 'Full Name' to say whose records these are.\n"));
}

$personId = (int) $person['id'];
echo "Loading into: {$person['name']}\n";

/* ------------------------------------------------------------- validation */

$errors = [];
$counts = ['medications' => 0, 'conditions' => 0, 'appointments' => 0, 'vitals' => 0];
$rows   = ['medications' => [], 'conditions' => [], 'appointments' => [], 'vitals' => []];

/** A date is either absent or a real YYYY-MM-DD. Silently wrong dates in a
 *  medical record are worse than a refused import. */
function good_date(?string $d, string $where, array &$errors): ?string
{
    if ($d === null || $d === '') {
        return null;
    }
    $t = DateTimeImmutable::createFromFormat('Y-m-d', $d);
    if ($t === false || $t->format('Y-m-d') !== $d) {
        $errors[] = "$where: '$d' is not a YYYY-MM-DD date";
        return null;
    }
    return $d;
}

function str_or_null(mixed $v, int $max = 200): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    return mb_substr(trim((string) $v), 0, $max);
}

if ($file !== null) {
    if (!is_readable($file)) {
        exit("Cannot read $file\n");
    }

    $data = json_decode((string) file_get_contents($file), true);

    if (!is_array($data)) {
        exit("$file is not valid JSON.\n");
    }

    foreach (($data['medications'] ?? []) as $i => $m) {
        $name = str_or_null($m['name'] ?? null, 120);
        if ($name === null) {
            $errors[] = "medications[$i]: no name";
            continue;
        }
        $rows['medications'][] = [
            'name'       => $name,
            // HDA calls it dosage.
            'dose'       => str_or_null($m['dose'] ?? ($m['dosage'] ?? null), 80),
            'schedule'   => str_or_null($m['schedule'] ?? null, 120),
            'purpose'    => str_or_null($m['purpose'] ?? null, 200),
            'prescriber' => str_or_null($m['prescriber'] ?? null, 120),
            'pharmacy'   => str_or_null($m['pharmacy'] ?? null, 120),
            'started_on' => good_date(str_or_null($m['started_on'] ?? null), "medications[$i].started_on", $errors),
            'ended_on'   => good_date(str_or_null($m['ended_on'] ?? null), "medications[$i].ended_on", $errors),
            'refill_due' => good_date(str_or_null($m['refill_due'] ?? null), "medications[$i].refill_due", $errors),
            'note'       => str_or_null($m['note'] ?? ($m['source_file'] ?? null), 400),
        ];
    }

    foreach (($data['conditions'] ?? []) as $i => $c) {
        $name = str_or_null($c['name'] ?? null, 120);
        if ($name === null) {
            $errors[] = "conditions[$i]: no name";
            continue;
        }
        $kind = strtolower((string) ($c['kind'] ?? 'condition'));
        if (!in_array($kind, ['condition', 'allergy', 'surgery', 'immunization'], true)) {
            $kind = 'condition';
        }
        $rows['conditions'][] = [
            'name'        => $name,
            'kind'        => $kind,
            'detail'      => str_or_null($c['detail'] ?? null, 400),
            'severity'    => str_or_null($c['severity'] ?? null, 60),
            'noted_on'    => good_date(str_or_null($c['noted_on'] ?? null), "conditions[$i].noted_on", $errors),
            'resolved_on' => good_date(str_or_null($c['resolved_on'] ?? null), "conditions[$i].resolved_on", $errors),
        ];
    }

    foreach (($data['appointments'] ?? []) as $i => $a) {
        $what = str_or_null($a['what'] ?? null, 200);
        $day  = good_date(str_or_null($a['on_day'] ?? null), "appointments[$i].on_day", $errors);
        if ($what === null || $day === null) {
            $errors[] = "appointments[$i]: needs both 'what' and a valid 'on_day'";
            continue;
        }
        $status = strtolower((string) ($a['status'] ?? 'scheduled'));
        if (!in_array($status, ['scheduled', 'done', 'cancelled', 'missed'], true)) {
            $status = 'scheduled';
        }
        $rows['appointments'][] = [
            'what'     => $what,
            'provider' => str_or_null($a['provider'] ?? null, 120),
            'place'    => str_or_null($a['place'] ?? null, 200),
            'on_day'   => $day,
            'at_time'  => str_or_null($a['at_time'] ?? null, 5),
            'status'   => $status,
            'note'     => str_or_null($a['note'] ?? null, 400),
        ];
    }

    foreach (($data['vitals'] ?? []) as $i => $v) {
        $metric = str_or_null($v['metric'] ?? null, 60);
        $day    = good_date(str_or_null($v['measured_on'] ?? null), "vitals[$i].measured_on", $errors);
        if ($metric === null || $day === null || !isset($v['value']) || !is_numeric($v['value'])) {
            $errors[] = "vitals[$i]: needs 'metric', a numeric 'value' and a valid 'measured_on'";
            continue;
        }
        $rows['vitals'][] = [
            'metric'      => $metric,
            'value'       => (float) $v['value'],
            'unit'        => str_or_null($v['unit'] ?? null, 20),
            'measured_on' => $day,
            'source'      => str_or_null($v['source'] ?? null, 60) ?? 'import',
            'note'        => str_or_null($v['note'] ?? null, 200),
        ];
    }
}

/* ---------------------------------------------------------------- the CSV */

if ($csv !== null) {
    $metric = arg($argv, '--metric');
    $unit   = arg($argv, '--unit');

    if ($metric === null) {
        exit("--vitals-csv needs --metric (e.g. --metric weight --unit lb)\n");
    }
    if (!is_readable($csv)) {
        exit("Cannot read $csv\n");
    }

    $handle = fopen($csv, 'r');
    $line   = 0;

    while (($cells = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $line++;
        if ($cells === [null] || count($cells) < 2) {
            continue;
        }

        // Tolerate a header row rather than making its presence a rule.
        if ($line === 1 && !is_numeric(trim((string) $cells[1]))) {
            continue;
        }

        $day = good_date(trim((string) $cells[0]), "$csv line $line", $errors);
        if ($day === null || !is_numeric(trim((string) $cells[1]))) {
            $errors[] = "$csv line $line: expected 'YYYY-MM-DD,value'";
            continue;
        }

        $rows['vitals'][] = [
            'metric'      => $metric,
            'value'       => (float) trim((string) $cells[1]),
            'unit'        => $unit,
            'measured_on' => $day,
            'source'      => 'csv',
            'note'        => null,
        ];
    }

    fclose($handle);
}

foreach ($rows as $k => $v) {
    $counts[$k] = count($v);
}

/* ------------------------------------------------------------------ report */

echo "\n";
foreach ($counts as $what => $n) {
    printf("%-14s %d\n", $what, $n);
}

if ($errors) {
    echo "\n", count($errors), " problem(s):\n";
    foreach (array_slice($errors, 0, 20) as $e) {
        echo "  - $e\n";
    }
    if (count($errors) > 20) {
        echo "  … and ", count($errors) - 20, " more\n";
    }
    echo "\nNothing was written. Fix the file and run again.\n";
    exit(1);
}

if (array_sum($counts) === 0) {
    exit("\nNothing to import.\n");
}

if ($dryRun) {
    exit("\nDRY RUN — nothing written. Drop --dry-run to load it.\n");
}

/* ------------------------------------------------------------------- write */

db()->beginTransaction();

try {
    foreach ($rows['medications'] as $m) {
        qx('INSERT INTO medications (person_id, name, dose, schedule, purpose, prescriber,
                                     pharmacy, started_on, ended_on, refill_due, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$personId, $m['name'], $m['dose'], $m['schedule'], $m['purpose'], $m['prescriber'],
             $m['pharmacy'], $m['started_on'], $m['ended_on'], $m['refill_due'], $m['note']]);
        log_change('medications', (int) db()->lastInsertId(), 'name', null, $m['name'], 'import', $personId, 'health-import');
    }

    foreach ($rows['conditions'] as $c) {
        qx('INSERT INTO conditions (person_id, name, kind, detail, severity, noted_on, resolved_on)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$personId, $c['name'], $c['kind'], $c['detail'], $c['severity'], $c['noted_on'], $c['resolved_on']]);
        log_change('conditions', (int) db()->lastInsertId(), 'name', null, $c['name'], 'import', $personId, 'health-import');
    }

    foreach ($rows['appointments'] as $a) {
        qx('INSERT INTO appointments (person_id, what, provider, place, on_day, at_time, status, note)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$personId, $a['what'], $a['provider'], $a['place'], $a['on_day'], $a['at_time'], $a['status'], $a['note']]);
        log_change('appointments', (int) db()->lastInsertId(), 'what', null, $a['what'], 'import', $personId, 'health-import');
    }

    foreach ($rows['vitals'] as $v) {
        qx('INSERT INTO vitals (person_id, metric, value, unit, measured_on, source, note)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$personId, $v['metric'], $v['value'], $v['unit'], $v['measured_on'], $v['source'], $v['note']]);
    }

    // One row for the whole batch, rather than thousands for a long series.
    if ($rows['vitals']) {
        log_change('vitals', null, 'batch', null,
            count($rows['vitals']) . ' readings', 'import', $personId, 'health-import');
    }

    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    exit("Import failed and nothing was written: {$e->getMessage()}\n");
}

echo "\nLoaded. Check the Health tab.\n";
echo "If this file came off another machine, shred it now — it has served its purpose:\n";
echo "    shred -u " . escapeshellarg($file ?? $csv) . "\n";
