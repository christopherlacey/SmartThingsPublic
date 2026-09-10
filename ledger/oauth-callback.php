<?php
/**
 * The Lacey Ledger — where Google sends the browser back.
 *
 * Exchanges the one-time code for an ID token over a direct server-to-server
 * TLS call to Google's token endpoint, then checks every claim that matters
 * before anyone is let in.
 *
 * The ID token's signature is not re-verified here, and that is deliberate:
 * the token is not taken from the browser, it is fetched by this server from
 * https://oauth2.googleapis.com over TLS using the client secret. Google
 * documents that as sufficient for the authorization-code flow. What the token
 * SAYS is still checked in full below — issuer, audience, expiry, nonce,
 * verified email, allow list, and the Workspace domain.
 */

declare(strict_types=1);

require_once __DIR__ . '/ledger-auth.php';

ledger_session_start();
$config = ledger_auth_config();

/** Bounce back to the sign-in page with a reason, never with detail. */
function ledger_login_failed(string $why): never
{
    ledger_auth_clear();
    unset($_SESSION['oauth_state'], $_SESSION['oauth_nonce'], $_SESSION['oauth_verifier']);
    header('Location: /login.php?error=' . urlencode($why), true, 302);
    exit;
}

/* Google reports its own problems (a cancelled consent screen, say). */
if (isset($_GET['error'])) {
    ledger_login_failed('denied');
}

/* The state must match the one minted when the flow started. */
$state    = (string) ($_GET['state'] ?? '');
$expected = (string) ($_SESSION['oauth_state'] ?? '');
if ($state === '' || $expected === '' || !hash_equals($expected, $state)) {
    ledger_login_failed('state');
}

$code = (string) ($_GET['code'] ?? '');
if ($code === '') {
    ledger_login_failed('exchange');
}

/* ---- swap the code for tokens ------------------------------------------ */

$post = http_build_query([
    'code'          => $code,
    'client_id'     => $config['google_client_id'],
    'client_secret' => $config['google_client_secret'],
    'redirect_uri'  => $config['redirect_uri'],
    'grant_type'    => 'authorization_code',
    'code_verifier' => (string) ($_SESSION['oauth_verifier'] ?? ''),
]);

$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

if ($body === false || $status !== 200) {
    ledger_login_failed('exchange');
}

$token = json_decode((string) $body, true);
if (!is_array($token) || empty($token['id_token'])) {
    ledger_login_failed('exchange');
}

/* ---- read the ID token ------------------------------------------------- */

$parts = explode('.', (string) $token['id_token']);
if (count($parts) !== 3) {
    ledger_login_failed('exchange');
}

$claims = json_decode(
    (string) base64_decode(strtr($parts[1], '-_', '+/'), false),
    true
);
if (!is_array($claims)) {
    ledger_login_failed('exchange');
}

/* ---- check every claim that matters ------------------------------------ */

$issuer = (string) ($claims['iss'] ?? '');
if ($issuer !== 'accounts.google.com' && $issuer !== 'https://accounts.google.com') {
    ledger_login_failed('denied');
}

// Audience must be this application, or the token was minted for someone else.
if (!hash_equals((string) $config['google_client_id'], (string) ($claims['aud'] ?? ''))) {
    ledger_login_failed('denied');
}

if ((int) ($claims['exp'] ?? 0) <= time()) {
    ledger_login_failed('denied');
}

// The nonce ties this token to the sign-in this browser actually started.
$nonce = (string) ($_SESSION['oauth_nonce'] ?? '');
if ($nonce === '' || !hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
    ledger_login_failed('state');
}

// An unverified address proves nothing about who is holding it.
if (($claims['email_verified'] ?? false) !== true
    && ($claims['email_verified'] ?? '') !== 'true') {
    ledger_login_failed('unverified');
}

$email = strtolower(trim((string) ($claims['email'] ?? '')));
if ($email === '' || !ledger_email_allowed($email)) {
    ledger_login_failed('denied');
}

// Workspace domain, when one is configured. A personal account cannot carry
// this claim for a domain it does not belong to.
$hd = (string) ($config['google_hd'] ?? '');
if ($hd !== '' && !hash_equals(strtolower($hd), strtolower((string) ($claims['hd'] ?? '')))) {
    ledger_login_failed('denied');
}

/* ---- in ---------------------------------------------------------------- */

// New session id, so nothing that existed before sign-in carries any weight.
session_regenerate_id(true);

unset($_SESSION['oauth_state'], $_SESSION['oauth_nonce'], $_SESSION['oauth_verifier']);

$_SESSION['ledger_email']    = $email;
$_SESSION['ledger_login_at'] = time();
$_SESSION['ledger_seen_at']  = time();

// Second factor still owed unless it is switched off or not yet enrolled.
$totpRequired = !empty($config['totp_enabled']) && ($config['totp_secret'] ?? '') !== '';
$_SESSION['ledger_2fa_ok'] = !$totpRequired;

header('Cache-Control: no-store, no-cache, must-revalidate');

if ($totpRequired) {
    header('Location: /login.php', true, 302);
    exit;
}

$to = $_SESSION['ledger_return_to'] ?? '/';
unset($_SESSION['ledger_return_to']);
header('Location: ' . $to, true, 302);
exit;
