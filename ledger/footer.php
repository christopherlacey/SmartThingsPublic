<?php
/**
 * The Lacey Ledger — shared page chrome (bottom half).
 * Closes the .wrap opened by header.php.
 */

// Who is signed in, if the auth layer is deployed. Guarded so the footer still
// renders on a host where sign-in has not been switched on yet.
$LEDGER_WHO = null;
if (function_exists('ledger_is_authenticated')) {
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }
    $LEDGER_WHO = $_SESSION['ledger_email'] ?? null;
}
?>
<footer class="cl-footer">
  <div class="cl-note">
    The Lacey Ledger · private · <?= e(date('Y-m-d H:i')) ?>
    <?php if ($LEDGER_WHO !== null): ?>
      <br>Signed in as <?= e($LEDGER_WHO) ?> ·
      <a href="/ledger-2fa-setup.php">Two-factor</a> ·
      <?php /* A POST, so no other site can sign you out by linking here. */ ?>
      <form method="post" action="/logout.php" class="signout-form">
        <input type="hidden" name="csrf" value="<?= e(ledger_form_token()) ?>">
        <button class="linkish" type="submit">Sign out</button>
      </form>
    <?php endif; ?>
  </div>
  <div class="cl-photo-row">
    <img class="cl-photo" src="https://chrislacey.com/chris-lacey-headshot-2026.png" alt="Chris Lacey" width="88" height="88" loading="lazy">
  </div>
  <div class="cl-links">
    <div class="cl-links-l">
      <a class="cl-blue" href="https://book.chrislacey.com">Book Time</a>
      <a class="cl-blue" href="https://account.chrislacey.com">My Dashboard</a>
      <a class="cl-blue" href="https://c.lacey.me/blog">Blog</a>
    </div>
    <div class="cl-links-r">
      <a class="cl-blue" href="https://privacy.chrislacey.com">Privacy Policy &amp; Terms</a>
      <a class="cl-red" href="https://emergency.chrislacey.com">Emergency</a>
    </div>
  </div>
</footer>
</div>
</body>
</html>
