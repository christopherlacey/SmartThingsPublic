<?php
/**
 * Hand back one stored medical document, to a signed-in session only.
 *
 *   document.php?id=12            download
 *   document.php?id=12&inline=1   open in the browser, for PDFs and images
 *
 * The document store lives outside the web root, so this script is the only way
 * to reach a file in it. Two rules make that safe:
 *
 *   1. The path is never built from anything the caller sent. The caller sends
 *      an integer id; the filename comes out of the database, and is a name this
 *      application generated when the file was filed.
 *   2. The resolved path is checked to be inside the store before anything is
 *      read, so even a corrupted row cannot walk out of the directory.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();
require_login();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$doc = $id > 0 ? q1('SELECT * FROM documents WHERE id = ?', [$id]) : null;

if ($doc === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("No such document.\n");
}

$store = rtrim((string) cfg('documents_dir', dirname((string) cfg('db')) . '/documents'), '/');
$path  = $store . '/' . $doc['stored_name'];

// Belt and braces against a bad row: resolve both sides and require the file to
// sit inside the store.
$realStore = realpath($store);
$realPath  = realpath($path);

if ($realStore === false || $realPath === false || !str_starts_with($realPath, $realStore . '/')) {
    error_log('ledger: document ' . $id . ' missing or outside the store');
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("That document is recorded but its file is missing.\n");
}

/*
 * Serve it as a download by default.
 *
 * The inline path is limited to PDFs and images: letting the browser render an
 * arbitrary stored file in this origin would turn the document store into a
 * place to host active content. Everything else is sent as an attachment, and
 * nosniff (set globally) stops the type being second-guessed.
 */
$mime      = (string) ($doc['mime'] ?? 'application/octet-stream');
$inlineOk  = in_array($mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'], true);
$wantsInline = isset($_GET['inline']) && $inlineOk;

// Give the file back under its original name, with anything path-shaped or
// control-like taken out of the header value.
$filename = preg_replace('/[^\w.\- ]+/u', '_', (string) $doc['original_name']) ?: 'document';

header('Content-Type: ' . ($inlineOk ? $mime : 'application/octet-stream'));
header('Content-Length: ' . (string) filesize($realPath));
header('Content-Disposition: ' . ($wantsInline ? 'inline' : 'attachment')
     . '; filename="' . $filename . '"');

// Already sent by ledger_send_headers(), but a medical document is the one
// response where a stale cache would matter most.
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

// Frame-ancestors 'none' and a default-src of 'self' already apply; sandbox the
// response as well so an inline PDF or image cannot run anything.
header("Content-Security-Policy: default-src 'none'; img-src 'self'; object-src 'self'; sandbox");

log_change('documents', $id, 'viewed', null, $doc['original_name'], 'update',
    $doc['person_id'] !== null ? (int) $doc['person_id'] : null, 'download');

readfile($realPath);
