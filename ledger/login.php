<?php
/**
 * The Lacey Ledger — sign in.
 *
 * Two steps. Google proves the identity; the authenticator proves the phone.
 * Neither step ever sees a password belonging to this site, because there
 * isn't one.
 */

declare(strict_types=1);

require_once __DIR__ . '/ledger-auth.php';
require_once __DIR__ . '/ledger-totp.php';

ledger_session_start();
$config = ledger_auth_config();

/* Already all the way in? Go where you were headed. */
if (ledger_is_authenticated()) {
    $to = $_SESSION['ledger_return_to'] ?? '/';
    unset($_SESSION['ledger_return_to']);
    header('Location: ' . $to, true, 302);
    exit;
}

$totpRequired = !empty($config['totp_enabled']) && ($config['totp_secret'] ?? '') !== '';
$googleDone   = is_string($_SESSION['ledger_email'] ?? null);
$error        = null;

/** Per-session CSRF token for the forms on this page. */
if (empty($_SESSION['ledger_login_csrf'])) {
    $_SESSION['ledger_login_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['ledger_login_csrf'];

/* ------------------------------------------------------- rate limiting -- */

const LEDGER_MAX_ATTEMPTS = 5;
const LEDGER_LOCKOUT      = 900;   // 15 minutes

function ledger_attempts(): array
{
    $s = ledger_state_read('login-attempts.json');
    $since = (int) ($s['since'] ?? 0);
    if (time() - $since > LEDGER_LOCKOUT) {
        return ['count' => 0, 'since' => time()];
    }
    return ['count' => (int) ($s['count'] ?? 0), 'since' => $since];
}

function ledger_attempt_failed(): void
{
    $a = ledger_attempts();
    ledger_state_write('login-attempts.json', [
        'count' => $a['count'] + 1,
        'since' => $a['since'] ?: time(),
    ]);
}

function ledger_attempts_cleared(): void
{
    ledger_state_write('login-attempts.json', ['count' => 0, 'since' => time()]);
}

$attempts  = ledger_attempts();   // read-only; an unreadable store reads as zero
$lockedFor = $attempts['count'] >= LEDGER_MAX_ATTEMPTS
    ? LEDGER_LOCKOUT - (time() - $attempts['since'])
    : 0;

/* ------------------------------------------------------------- actions -- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Bad CSRF token. Reload and try again.');
    }

    $action = (string) ($_POST['action'] ?? '');

    /* Step 1 — hand off to Google. */
    if ($action === 'google') {
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');

        $_SESSION['oauth_state']    = bin2hex(random_bytes(32));
        $_SESSION['oauth_nonce']    = bin2hex(random_bytes(32));
        $_SESSION['oauth_verifier'] = $verifier;

        $params = [
            'client_id'             => $config['google_client_id'],
            'redirect_uri'          => $config['redirect_uri'],
            'response_type'         => 'code',
            'scope'                 => 'openid email profile',
            'state'                 => $_SESSION['oauth_state'],
            'nonce'                 => $_SESSION['oauth_nonce'],
            'code_challenge'        => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt'                => 'select_account',
            'access_type'           => 'online',
        ];
        // Nudge Google's own account chooser toward the right domain.
        if (!empty($config['google_hd'])) {
            $params['hd'] = $config['google_hd'];
        }

        header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params), true, 302);
        exit;
    }

    /* Step 2 — the code from the authenticator. */
    if ($action === 'totp' && $googleDone && $totpRequired) {
        if (!ledger_state_usable()) {
            // Without a writable store there is no replay counter and no
            // lockout, so the second factor would only look like one. Refuse
            // rather than accept a code we cannot spend.
            error_log('ledger: state_dir is not writable (' . ledger_state_dir()
                . ') — refusing the second factor rather than accepting it unprotected');
            $error = 'The second factor can’t be checked safely right now, so sign-in is '
                   . 'blocked. The server’s state directory is not writable.';
        } elseif ($lockedFor > 0) {
            $error = 'Too many attempts. Try again in ' . ceil($lockedFor / 60) . ' minutes.';
        } else {
            $state = ledger_state_read('totp.json');
            $last  = isset($state['last_counter']) ? (int) $state['last_counter'] : null;

            $counter = totp_verify(
                (string) $config['totp_secret'],
                (string) ($_POST['code'] ?? ''),
                $last
            );

            if ($counter === null) {
                try {
                    ledger_attempt_failed();
                } catch (RuntimeException) {
                    // Counted or not, the code was wrong.
                }
                $error = 'That code isn’t right.';
            } else {
                // Burn the step BEFORE letting anyone in, so a code can never be
                // accepted twice. If it cannot be burned, it is not accepted.
                $spent = true;
                try {
                    ledger_state_write('totp.json', ['last_counter' => $counter]);
                } catch (RuntimeException $ex) {
                    $spent = false;
                    error_log('ledger: could not record the used TOTP step: ' . $ex->getMessage());
                    $error = 'The second factor can’t be checked safely right now, so sign-in '
                           . 'is blocked. The server’s state directory is not writable.';
                }

                if ($spent) {
                    try {
                        ledger_attempts_cleared();
                    } catch (RuntimeException) {
                        // Not fatal: the code was right and has been spent.
                    }

                    session_regenerate_id(true);
                    $_SESSION['ledger_2fa_ok']  = true;
                    $_SESSION['ledger_seen_at'] = time();

                    $to = $_SESSION['ledger_return_to'] ?? '/';
                    unset($_SESSION['ledger_return_to']);
                    header('Location: ' . $to, true, 302);
                    exit;
                }
            }
        }
    }
}

if (isset($_GET['error'])) {
    $error = match ((string) $_GET['error']) {
        'denied'    => 'That account isn’t allowed in.',
        'state'     => 'The sign-in didn’t come back cleanly. Please try again.',
        'exchange'  => 'Google wouldn’t complete the sign-in. Please try again.',
        'unverified'=> 'That Google account has no verified email address.',
        default     => 'Sign-in failed. Please try again.',
    };
}

header('Cache-Control: no-store, no-cache, must-revalidate');
$PAGE_TITLE = 'Sign in';
?>
<!doctype html>
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
<link rel="stylesheet" href="/theme.css">
</head>
<body>
<div class="wrap signin-wrap">
  <div class="signin">
    <p class="eyebrow">The Lacey Ledger</p>
    <h1><?= $googleDone && $totpRequired ? 'One more step' : 'Sign in' ?></h1>

    <?php if ($googleDone && $totpRequired): ?>
      <p class="kicker">
        Signed in as <strong><?= htmlspecialchars($_SESSION['ledger_email']) ?></strong>.
        Enter the current code from your authenticator.
      </p>

      <?php if ($error !== null): ?>
        <div class="card notice signin-error"><p class="line"><?= htmlspecialchars($error) ?></p></div>
      <?php endif; ?>

      <form method="post" class="signin-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="totp">
        <div class="field">
          <label for="code">Six-digit code</label>
          <input id="code" name="code" class="code-input" inputmode="numeric"
                 autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"
                 required autofocus <?= $lockedFor > 0 ? 'disabled' : '' ?>>
        </div>
        <button class="btn btn-primary" type="submit" <?= $lockedFor > 0 ? 'disabled' : '' ?>>
          Verify
        </button>
      </form>

      <p class="signin-alt"><a href="/logout.php">Use a different account</a></p>

    <?php else: ?>
      <p class="kicker">This dashboard is private. Sign in with your Google account to continue.</p>

      <?php if ($error !== null): ?>
        <div class="card notice signin-error"><p class="line"><?= htmlspecialchars($error) ?></p></div>
      <?php endif; ?>

      <form method="post" class="signin-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
        <input type="hidden" name="action" value="google">
        <button class="btn btn-google" type="submit">
          <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true">
            <path fill="#4285F4" d="M17.64 9.2c0-.64-.06-1.25-.16-1.84H9v3.48h4.84a4.14 4.14 0 0 1-1.8 2.72v2.26h2.92c1.7-1.57 2.68-3.88 2.68-6.62z"/>
            <path fill="#34A853" d="M9 18c2.43 0 4.47-.8 5.96-2.18l-2.92-2.26c-.8.54-1.84.86-3.04.86-2.34 0-4.32-1.58-5.03-3.7H.96v2.33A9 9 0 0 0 9 18z"/>
            <path fill="#FBBC05" d="M3.97 10.72a5.4 5.4 0 0 1 0-3.44V4.95H.96a9 9 0 0 0 0 8.1l3.01-2.33z"/>
            <path fill="#EA4335" d="M9 3.58c1.32 0 2.5.45 3.44 1.35l2.58-2.58C13.46.89 11.43 0 9 0A9 9 0 0 0 .96 4.95l3.01 2.33C4.68 5.16 6.66 3.58 9 3.58z"/>
          </svg>
          Continue with Google
        </button>
      </form>

      <p class="signin-note">
        Only <?= count($config['allowed_emails'] ?? []) === 1 ? 'one address is' : 'a few addresses are' ?>
        allowed in. Everything else is turned away.
      </p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
