<?php
/**
 * The Lacey Ledger — shared page chrome (top half).
 *
 * Drop-in replacement for the existing header.php. It is deliberately
 * defensive about how the calling page talks to it, so no existing tab has to
 * be edited:
 *
 *   - Title: uses $PAGE_TITLE, $page_title or $title, whichever is set.
 *   - Active tab: derived from the running script's filename, so pages do not
 *     have to declare which tab they are.
 *
 * Every page ends with:  <?php require __DIR__ . '/footer.php'; ?>
 */

if (!isset($LEDGER_NAV)) {
    $LEDGER_NAV = [
        'index.php'     => 'Overview',
        'people.php'    => 'People',
        'finance.php'   => 'Finance',
        'shopping.php'  => 'Shopping',
        'medical.php'   => 'Medical',
        'trip.php'      => 'Trip',
        'changelog.php' => 'Change log',
    ];
}

/** Current script, e.g. "finance.php". */
$LEDGER_CURRENT = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

/** Page title, however the calling page chose to declare it. */
$LEDGER_TITLE = $PAGE_TITLE
    ?? $page_title
    ?? $title
    ?? ($LEDGER_NAV[$LEDGER_CURRENT] ?? 'The Lacey Ledger');

if (!function_exists('e')) {
    /** HTML-escape. Every value interpolated into markup goes through this. */
    function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= e($LEDGER_TITLE) ?> — The Lacey Ledger</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,440;9..144,520;9..144,600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="theme.css?v=<?= @filemtime(__DIR__ . '/theme.css') ?: '1' ?>">
</head>
<body>
<div class="wrap">
<nav>
<?php foreach ($LEDGER_NAV as $file => $label): ?>
  <a href="<?= e($file) ?>"<?= $file === $LEDGER_CURRENT ? ' class="on" aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach; ?>
</nav>
