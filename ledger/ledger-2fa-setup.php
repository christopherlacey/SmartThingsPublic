<?php
/**
 * The Lacey Ledger — enrol the second factor.
 *
 * Sits behind the gate, so you must already be signed in with Google to reach
 * it. That is the bootstrap: with no secret in the config yet the second step
 * is skipped, you get in on Google alone, enrol here, and from then on both
 * steps are required.
 *
 * The finished secret is NOT written to disk from the browser — it is shown
 * once for you to paste into ledger-auth-config.php. A web page that can
 * rewrite the file holding its own authentication secret is a worse trade than
 * one copy-and-paste.
 */

declare(strict_types=1);

require_once __DIR__ . '/ledger-auth.php';
require_once __DIR__ . '/ledger-totp.php';

ledger_session_start();
$config  = ledger_auth_config();
$account = (string) ($_SESSION['ledger_email'] ?? 'chris@chrislacey.com');
$live    = ($config['totp_secret'] ?? '') !== '';

if (empty($_SESSION['ledger_setup_csrf'])) {
    $_SESSION['ledger_setup_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['ledger_setup_csrf'];

$error = null;
$proved = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad CSRF token. Reload and try again.');
    }

    if (($_POST['action'] ?? '') === 'new') {
        $_SESSION['ledger_candidate_secret'] = totp_new_secret();
    } elseif (($_POST['action'] ?? '') === 'prove') {
        $candidate = (string) ($_SESSION['ledger_candidate_secret'] ?? '');
        if ($candidate === '') {
            $error = 'Start again — there is no secret in progress.';
        } elseif (totp_verify($candidate, (string) ($_POST['code'] ?? '')) === null) {
            $error = 'That code isn’t right. Check the clock on your phone and try the next one.';
        } else {
            $proved = true;
        }
    }
}

$candidate = (string) ($_SESSION['ledger_candidate_secret'] ?? '');

header('Cache-Control: no-store, no-cache, must-revalidate');
$PAGE_TITLE = 'Two-factor';
require __DIR__ . '/header.php';
?>

<h1>Two-factor</h1>
<p class="kicker">
  A code from your authenticator, on top of Google. Google already carries
  whatever 2-step verification your Workspace account enforces; this is a
  second factor the Ledger owns itself.
</p>

<div class="card notice">
  <p class="who">
    Status:
    <?= $live ? 'a second factor is configured and required.' : 'not set up yet — Google alone gets you in.' ?>
  </p>
</div>

<?php if ($error !== null): ?>
  <div class="card notice signin-error"><p class="line"><?= e($error) ?></p></div>
<?php endif; ?>

<?php if ($proved): ?>

  <h2>Last step</h2>
  <div class="card">
    <p class="line">
      That code checked out, so the secret below is enrolled on your phone
      correctly. Paste it into <code>ledger-auth-config.php</code> and it takes
      effect on your next sign-in:
    </p>
    <pre class="secret-block">'totp_secret'  =&gt; '<?= e($candidate) ?>',</pre>
    <p class="line">
      Then <a href="/logout.php">sign out</a> and back in to check the second
      step appears. Keep a copy of the secret somewhere safe — losing both it
      and the phone means editing the config on the server to get back in.
    </p>
  </div>

<?php elseif ($candidate !== ''): ?>

  <h2>Add it to your authenticator</h2>
  <div class="card">
    <p class="line">
      In Google Authenticator: <strong>+</strong> → <strong>Enter a setup
      key</strong>. Account <code><?= e($account) ?></code>, key:
    </p>
    <pre class="secret-block"><?= e(totp_grouped($candidate)) ?></pre>
    <p class="where">
      Type of key: time-based. The full URI, if your app takes one:<br>
      <span class="mono secret-uri"><?= e(totp_uri($candidate, $account)) ?></span>
    </p>
    <p class="where">
      There is no QR code on purpose — drawing one would mean either shipping a
      QR encoder or sending this secret to an image service, and the second of
      those hands your second factor to a stranger.
    </p>
  </div>

  <h2>Prove it took</h2>
  <form method="post" class="card">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="prove">
    <div class="field">
      <label for="code">The code showing right now</label>
      <input id="code" name="code" class="code-input" inputmode="numeric"
             autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus>
    </div>
    <button class="btn btn-primary" type="submit" style="margin-top:12px">Check it</button>
  </form>

<?php else: ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="new">
    <button class="btn btn-primary" type="submit">
      <?= $live ? 'Replace the current second factor' : 'Set up a second factor' ?>
    </button>
  </form>

<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
