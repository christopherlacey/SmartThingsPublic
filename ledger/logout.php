<?php
/**
 * The Lacey Ledger — sign out.
 *
 * Only ever on POST with a matching token. A plain link would let any other
 * site sign you out by pointing an image or a redirect at this URL: not a way
 * in, but a way to be a nuisance, and cheap to close.
 *
 * A GET here is treated as someone arriving at the URL directly, so it asks
 * rather than acting.
 *
 * The Google session itself is untouched, which is why signing back in offers
 * the account chooser.
 */

declare(strict_types=1);

require_once __DIR__ . '/ledger-auth.php';

ledger_session_start();

$who = $_SESSION['ledger_email'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!ledger_form_token_ok()) {
        http_response_code(400);
        exit('Bad CSRF token. Reload the page and try again.');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();

    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Location: /login.php', true, 302);
    exit;
}

/* A GET: confirm, don't act. */
header('Cache-Control: no-store, no-cache, must-revalidate');
$token = ledger_form_token();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>Sign out — The Lacey Ledger</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,440;9..144,520;9..144,600&family=IBM+Plex+Sans:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/theme.css">
</head>
<body>
<div class="wrap signin-wrap">
  <div class="signin">
    <p class="eyebrow">The Lacey Ledger</p>
    <h1>Sign out?</h1>
    <p class="kicker">
      <?php if ($who !== null): ?>
        You’re signed in as <strong><?= htmlspecialchars($who) ?></strong>.
      <?php else: ?>
        You’re not signed in.
      <?php endif; ?>
    </p>
    <form method="post" class="signin-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($token) ?>">
      <button class="btn btn-primary" type="submit">Sign out</button>
    </form>
    <p class="signin-alt"><a href="/">Stay signed in</a></p>
  </div>
</div>
</body>
</html>
