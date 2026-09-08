<?php
/**
 * Create or upgrade the ledger database.
 *
 *   php bin/init-db.php
 *
 * Safe to re-run: every statement in schema.sql is CREATE ... IF NOT EXISTS, so
 * this adds what is missing and leaves existing data alone.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../src/bootstrap.php';

$config = ledger_config();
date_default_timezone_set($config['timezone']);

$path = $config['db'];
$fresh = !file_exists($path);

db()->exec(file_get_contents(__DIR__ . '/../schema.sql'));
@chmod($path, 0600);

echo ($fresh ? "Created" : "Updated"), " $path\n";

$tables = q("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
echo count($tables), " tables: ", implode(', ', array_column($tables, 'name')), "\n";

if (q1('SELECT id FROM account WHERE id = 1') === null) {
    echo "\nNo account yet. Set one up before the site will let anyone in:\n";
    echo "    php bin/set-password.php <username>\n";
}
