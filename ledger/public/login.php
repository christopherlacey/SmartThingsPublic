<?php
/**
 * Sign in.
 *
 * Same shape as before — one username, one password, one button — with the
 * password masked by default and a control to show it when wanted.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();

// Already signed in: skip the form.
if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

$error  = null;
$notice = null;

if (isset($_GET['timeout'])) {
    $notice = 'You were signed out after a while of inactivity.';
}
if (isset($_GET['bye'])) {
    $notice = 'Signed out.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $error = attempt_login(
        trim((string) ($_POST['username'] ?? '')),
        (string) ($_POST['password'] ?? '')
    );

    if ($error === null) {
        header('Location: ' . safe_next($_POST['next'] ?? null));
        exit;
    }
}

$next     = (string) ($_GET['next'] ?? ($_POST['next'] ?? ''));
$portrait = cfg('portrait', 'assets/chris-lacey-headshot-2026.png');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>Sign in — The Lacey Ledger</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,440;9..144,520;9..144,600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/ledger.css">
<link rel="icon" href="<?= h($portrait) ?>">
</head>
<body>
<div class="signin-wrap">

  <header class="header">
    <div class="portrait-ring">
      <img class="portrait" src="<?= h($portrait) ?>" alt="Photo of <?= h(cfg('owner_name', 'Chris')) ?>">
    </div>
    <h1>The Lacey <span>Ledger</span></h1>
    <p class="tagline">Private. Sign in to continue.</p>
  </header>

  <?php if ($error !== null): ?>
    <div class="notice error" role="alert"><p><?= h($error) ?></p></div>
  <?php elseif ($notice !== null): ?>
    <div class="notice info"><p><?= h($notice) ?></p></div>
  <?php endif; ?>

  <form class="stack" method="post" action="login.php" autocomplete="on">
    <?= csrf_field() ?>
    <input type="hidden" name="next" value="<?= h($next) ?>">

    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username" autocomplete="username"
             autocapitalize="none" autocorrect="off" spellcheck="false" required
             value="<?= h((string) ($_POST['username'] ?? '')) ?>">
    </div>

    <div class="field">
      <label for="password">Password</label>
      <!-- data-reveal wires up the show/hide control; the field itself stays
           type="password", so it is masked before any script runs and stays
           masked if scripting is off. -->
      <input type="password" id="password" name="password"
             autocomplete="current-password" required data-reveal>
      <p class="hint">Hidden by default — use <strong>Show</strong> to check it before signing in.</p>
    </div>

    <button type="submit" class="btn btn-primary">Sign in</button>
  </form>

  <footer>
    <img src="<?= h($portrait) ?>" alt="<?= h(cfg('owner_name', 'Chris')) ?>">
    <div class="footer-links">
      <a class="footer-emergency" href="<?= h(cfg('emergency_url', 'https://emergency.chrislacey.com')) ?>">Emergency</a>
      <a class="footer-privacy" href="<?= h(cfg('privacy_url', 'https://privacy.chrislacey.com')) ?>">Privacy</a>
    </div>
  </footer>

</div>
<script src="assets/ledger.js"></script>
</body>
</html>
