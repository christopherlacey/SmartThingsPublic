<?php
/**
 * Database access. One SQLite file, one connection per request.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = cfg('db');
    $dir  = dirname($path);

    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    try {
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        // The message can name filesystem paths, so it goes to the log, not the page.
        error_log('ledger: cannot open database: ' . $e->getMessage());
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        exit("Ledger database unavailable.\n");
    }

    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');

    // The file holds medical and financial records; keep it owner-only even if
    // SQLite created it with a looser umask.
    if (is_file($path)) {
        @chmod($path, 0600);
    }

    return $pdo;
}

/** Run a query and return all rows. */
function q(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Run a query and return the first row, or null. */
function q1(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Run a query and return a single scalar, or null. */
function qv(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_NUM);
    return $row === false ? null : $row[0];
}

/** Execute a write and return the number of affected rows. */
function qx(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Record a write in the audit trail.
 *
 * Medical and financial fields are the reason this exists: months later the
 * Change log should still be able to say what a value used to be and where the
 * new one came from.
 */
function log_change(
    string $entity,
    ?int $entityId,
    ?string $field,
    ?string $before,
    ?string $after,
    string $action = 'update',
    ?int $personId = null,
    ?string $source = null
): void {
    qx(
        'INSERT INTO changes (entity, entity_id, person_id, field, before_val, after_val, action, source)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$entity, $entityId, $personId, $field, $before, $after, $action, $source ?? 'web']
    );
}
