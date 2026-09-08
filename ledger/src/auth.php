<?php
/**
 * Authentication.
 *
 * Single account, password + session. The previous version of this site had no
 * auth layer at all — every page answered anonymous requests with the full
 * record — so the rule here is that data pages fail closed: require_login()
 * redirects before any page emits content.
 */

declare(strict_types=1);

function current_user(): ?array
{
    if (empty($_SESSION['uid'])) {
        return null;
    }
    return q1('SELECT id, username FROM account WHERE id = ?', [$_SESSION['uid']]);
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

/**
 * Gate a page. Call immediately after ledger_boot(), before any output.
 */
function require_login(): array
{
    $user = current_user();

    if ($user === null) {
        $to = $_SERVER['REQUEST_URI'] ?? '/';
        header('Location: login.php?next=' . urlencode($to));
        exit;
    }

    // Idle timeout — a dashboard of medical and financial records should not sit
    // signed in on a screen someone walked away from.
    $timeout = (int) cfg('idle_timeout', 1800);
    $last    = (int) ($_SESSION['seen'] ?? 0);

    if ($timeout > 0 && $last > 0 && (time() - $last) > $timeout) {
        logout();
        header('Location: login.php?timeout=1');
        exit;
    }

    $_SESSION['seen'] = time();
    return $user;
}

function client_ip(): string
{
    // Only the direct peer is trustworthy here. X-Forwarded-For is caller-supplied
    // and would let anyone dodge the login throttle by rotating a header.
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * True when this IP has burned through its allowance of failed logins.
 */
function login_locked_out(): bool
{
    $window = (int) cfg('login_window', 900);
    $max    = (int) cfg('max_login_attempts', 8);

    $failures = (int) qv(
        "SELECT COUNT(*) FROM login_attempts
          WHERE ip = ? AND ok = 0 AND at > datetime('now', ?)",
        [client_ip(), '-' . $window . ' seconds']
    );

    return $failures >= $max;
}

function record_login_attempt(bool $ok): void
{
    qx('INSERT INTO login_attempts (ip, ok) VALUES (?, ?)', [client_ip(), $ok ? 1 : 0]);
    // Keep the table from growing without bound.
    qx("DELETE FROM login_attempts WHERE at < datetime('now', '-7 days')");
}

/**
 * Verify a password.
 *
 * Returns one of:
 *   ['status' => 'ok']                 signed in
 *   ['status' => 'totp']               password was right, now needs a code
 *   ['status' => 'error', 'error' => …] refused
 *
 * The caller shows the same message for a wrong username and a wrong password
 * so the form never confirms which half was right.
 */
function attempt_login(string $username, string $password): array
{
    if (login_locked_out()) {
        return ['status' => 'error', 'error' => 'Too many attempts. Try again in a few minutes.'];
    }

    $account = q1('SELECT id, username, password_hash, totp_secret FROM account WHERE id = 1');

    if ($account === null) {
        return ['status' => 'error', 'error' => 'No account has been set up yet. Run bin/set-password.php.'];
    }

    $userOk = hash_equals($account['username'], $username);
    $passOk = password_verify($password, $account['password_hash']);

    if (!$userOk || !$passOk) {
        record_login_attempt(false);
        return ['status' => 'error', 'error' => 'That username and password do not match.'];
    }

    // Re-hash if PHP's default cost has moved on since the password was set.
    if (password_needs_rehash($account['password_hash'], PASSWORD_DEFAULT)) {
        qx('UPDATE account SET password_hash = ? WHERE id = 1',
            [password_hash($password, PASSWORD_DEFAULT)]);
    }

    // Second factor: the password alone is not a session yet. Hold the account
    // id somewhere the code step can find it, and regenerate now so the id the
    // pending state lives under is not one an attacker could have planted.
    if (!empty($account['totp_secret'])) {
        session_regenerate_id(true);
        $_SESSION['pending_uid'] = (int) $account['id'];
        $_SESSION['pending_at']  = time();
        return ['status' => 'totp'];
    }

    complete_login((int) $account['id']);
    return ['status' => 'ok'];
}

/**
 * Turn a verified identity into a session. The only place $_SESSION['uid'] is
 * ever set.
 */
function complete_login(int $accountId): void
{
    record_login_attempt(true);
    qx('DELETE FROM login_attempts WHERE ip = ? AND ok = 0', [client_ip()]);

    // New session id on privilege change, so a fixated cookie is worthless.
    session_regenerate_id(true);
    unset($_SESSION['pending_uid'], $_SESSION['pending_at']);

    $_SESSION['uid']  = $accountId;
    $_SESSION['seen'] = time();

    log_change('account', $accountId, null, null, null, 'login', null, 'web');
}

/** True when a password has been accepted and only the code step remains. */
function awaiting_totp(): bool
{
    if (empty($_SESSION['pending_uid'])) {
        return false;
    }

    // A half-finished sign-in should not stay open indefinitely.
    if (time() - (int) ($_SESSION['pending_at'] ?? 0) > 300) {
        unset($_SESSION['pending_uid'], $_SESSION['pending_at']);
        return false;
    }

    return true;
}

/**
 * Second step: a TOTP code, or one of the single-use recovery codes.
 *
 * Throttled on the same counter as the password, because six digits is a small
 * space to guess through if nothing is counting.
 */
function attempt_totp(string $entered): ?string
{
    if (!awaiting_totp()) {
        return 'That sign-in expired. Start again.';
    }

    if (login_locked_out()) {
        return 'Too many attempts. Try again in a few minutes.';
    }

    $accountId = (int) $_SESSION['pending_uid'];
    $secret    = (string) qv('SELECT totp_secret FROM account WHERE id = ?', [$accountId]);

    if (totp_verify($secret, $entered)) {
        complete_login($accountId);
        return null;
    }

    // A recovery code is the way in when the authenticator is gone.
    if (recovery_code_consume($entered)) {
        complete_login($accountId);
        return null;
    }

    record_login_attempt(false);
    return 'That code is not right. Check the clock on your phone, or use a recovery code.';
}

function logout(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => $p['samesite'],
        ]);
    }

    session_destroy();
}

/**
 * Where to send someone after login.
 *
 * Only a local, relative path is honoured. Without this check `?next=` would be
 * an open redirect that bounces a signed-in session to someone else's site.
 */
function safe_next(?string $next): string
{
    if ($next === null || $next === '') {
        return 'index.php';
    }
    if (str_starts_with($next, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $next)) {
        return 'index.php';
    }
    if (!str_starts_with($next, '/')) {
        return 'index.php';
    }
    return $next;
}
