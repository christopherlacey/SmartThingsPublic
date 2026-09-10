<?php
/**
 * The page shell.
 *
 * Type, colour, spacing and the footer are lifted from chrislacey.com so the
 * ledger reads as the same property: Fraunces over IBM Plex Sans, IBM Plex Mono
 * for anything numeric, the portrait ring, and the footer carrying the headshot
 * with the Emergency and Privacy links.
 */

declare(strict_types=1);

const LEDGER_TABS = [
    ['index.php',     'Now',        'index'],
    ['health.php',    'Health',     'health'],
    ['money.php',     'Money',      'money'],
    ['errands.php',   'Errands',    'errands'],
    ['people.php',    'People',     'people'],
    ['changelog.php', 'Change log', 'changelog'],
];

/**
 * The wordmark.
 *
 * "Chris Lacey" wears the brand blue and "Dashboard" wears the primary text
 * colour — black on the light theme, and the light ink on the dark one, since a
 * literal black would disappear against a dark background. Both are tokens, so
 * the mark follows the theme instead of being repainted by hand.
 */
function brand_mark(): string
{
    return '<span class="mark-name">Chris Lacey&rsquo;s</span> <span class="mark-word">Dashboard</span>';
}

/** The same name as plain text, for <title> and anywhere markup won't do. */
function brand_text(): string
{
    return "Chris Lacey's Dashboard";
}

function page_head(string $title, string $tab): void
{
    $portrait = cfg('portrait', 'assets/chris-lacey-headshot-2026.png');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title><?= h($title) ?> — <?= h(brand_text()) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,440;9..144,520;9..144,600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/ledger.css">
<link rel="icon" href="<?= h($portrait) ?>">
</head>
<body>
<div class="wrap">
<?php
}

/**
 * Header: portrait, greeting, tab bar.
 */
function page_header(string $tab, string $heading, string $kicker = ''): void
{
    $portrait = cfg('portrait', 'assets/chris-lacey-headshot-2026.png');
    ?>
  <header class="header">
    <div class="portrait-ring">
      <img class="portrait" src="<?= h($portrait) ?>" alt="Photo of <?= h(cfg('owner_name', 'Chris')) ?>">
    </div>
    <h1><?= $heading ?></h1>
    <?php if ($kicker !== ''): ?><p class="tagline"><?= $kicker ?></p><?php endif; ?>
  </header>

  <nav class="tabs" aria-label="Sections">
    <?php foreach (LEDGER_TABS as [$href, $label, $key]): ?>
      <a href="<?= h($href) ?>"<?= $key === $tab ? ' class="on" aria-current="page"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
    <a class="signout" href="logout.php">Sign out</a>
  </nav>
    <?php
}

/**
 * Footer — the same one every Chris Lacey site carries.
 */
function page_footer(): void
{
    $portrait = cfg('portrait', 'assets/chris-lacey-headshot-2026.png');
    ?>
  <footer>
    <img src="<?= h($portrait) ?>" alt="<?= h(cfg('owner_name', 'Chris')) ?>">
    <p class="footer-mark"><?= brand_mark() ?></p>
    <div class="footer-links">
      <a class="footer-emergency" href="<?= h(cfg('emergency_url', 'https://emergency.chrislacey.com')) ?>">Emergency</a>
      <a class="footer-privacy" href="<?= h(cfg('privacy_url', 'https://privacy.chrislacey.com')) ?>">Privacy</a>
    </div>
  </footer>
</div>
<script src="assets/charts.js"></script>
<script src="assets/ledger.js"></script>
</body>
</html>
    <?php
}

/* --------------------------------------------------------------- pieces --- */

/** Small uppercase label above a section heading. */
function eyebrow(string $text): void
{
    echo '<p class="eyebrow">' . h($text) . '</p>';
}

/** A confidence pill: confirmed / inferred / stale. */
function tag(string $kind, ?string $label = null): string
{
    $label ??= $kind;
    return '<span class="tag ' . h($kind) . '">' . h($label) . '</span>';
}

/**
 * Stat tile: one number, an optional delta, an optional sparkline.
 *
 * A single current value is a stat tile, never a one-bar chart.
 */
function stat_tile(string $label, string $value, ?string $sub = null, ?array $spark = null, string $tone = ''): void
{
    ?>
    <div class="stat<?= $tone ? ' ' . h($tone) : '' ?>">
      <p class="stat-label"><?= h($label) ?></p>
      <p class="stat-value"><?= h($value) ?></p>
      <?php if ($sub !== null): ?><p class="stat-sub"><?= $sub ?></p><?php endif; ?>
      <?php if ($spark): ?>
        <div class="spark" data-spark="<?= h(implode(',', $spark)) ?>"></div>
      <?php endif; ?>
    </div>
    <?php
}

/** Open a card. */
function card_open(string $classes = ''): void
{
    echo '<div class="card' . ($classes ? ' ' . h($classes) : '') . '">';
}

function card_close(): void
{
    echo '</div>';
}

/** The line shown when a section has nothing in it yet. */
function empty_line(string $text): void
{
    echo '<p class="line empty">' . h($text) . '</p>';
}
