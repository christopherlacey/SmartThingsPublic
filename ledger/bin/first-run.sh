#!/usr/bin/env bash
#
# first-run.sh — bring the dashboard up on this host, in one pass.
#
# Run this ON THE WEB SERVER, from the application directory, after deploy.sh
# has copied the code and you have filled in config.php:
#
#     cd /var/www/ledger && ./bin/first-run.sh
#
# It is safe to re-run. Each step checks whether it has already been done and
# skips it, so an interrupted run can simply be started again.
#
# What it does not do: point your web server at ledger/public. That is the one
# step that has to happen in the vhost — see deploy/apache-vhost.conf — and it
# is the step that actually takes the old pages out of service.

set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
APP="$(pwd)"

green() { printf '\033[32m%s\033[0m\n' "$1"; }
red()   { printf '\033[31m%s\033[0m\n' "$1"; }
info()  { printf '\033[36m\n== %s\033[0m\n' "$1"; }
skip()  { printf '   \033[90m%s\033[0m\n' "$1"; }

php_bin="${PHP:-php}"
command -v "$php_bin" >/dev/null || { red "php not found. Set PHP=/path/to/php and re-run."; exit 1; }

# ---------------------------------------------------------------- config ---

info "Configuration"

if [ ! -f config.php ]; then
  if [ -f config.php.example ]; then
    cp config.php.example config.php
    chmod 600 config.php
    red "config.php was missing. A template has been copied into place."
    echo "   Fill it in, then run this again:"
    echo "       \$EDITOR $APP/config.php"
    echo
    echo "   At minimum set 'db' to a path OUTSIDE the web root, and generate a"
    echo "   location token with:"
    echo "       php -r 'echo bin2hex(random_bytes(32)), \"\\n\";'"
    exit 1
  fi
  red "No config.php and no template to copy. Re-run deploy.sh first."
  exit 1
fi

chmod 600 config.php
green "config.php present and owner-only"

db_path=$("$php_bin" -r '$c = require "config.php"; echo $c["db"] ?? "";')
[ -n "$db_path" ] || { red "config.php has no 'db' path set."; exit 1; }

# The single most important property of this deployment: the records must not
# be reachable over HTTP. Catch the mistake here rather than in the verify.
case "$db_path" in
  "$APP"/public/*)
    red "The database path is inside the web root:"
    red "    $db_path"
    red "Move it somewhere outside public/ before going any further."
    exit 1
    ;;
esac
green "database path is outside the web root"

# ------------------------------------------------------------------ schema -

info "Database"
"$php_bin" bin/init-db.php
chmod 600 "$db_path" 2>/dev/null || true

# ---------------------------------------------------------------- account --

info "Sign-in"

has_account=$("$php_bin" -r 'require "src/bootstrap.php"; echo q1("SELECT id FROM account WHERE id = 1") ? "yes" : "no";')

if [ "$has_account" = "yes" ]; then
  skip "an account already exists — skipping (use bin/set-password.php to change it)"
else
  read -rp "   Username: " username
  [ -n "$username" ] || { red "A username is required."; exit 1; }
  "$php_bin" bin/set-password.php "$username"
fi

# ------------------------------------------------------------ second factor -

info "Second factor"

has_totp=$("$php_bin" -r 'require "src/bootstrap.php"; echo totp_enabled() ? "yes" : "no";')

if [ "$has_totp" = "yes" ]; then
  skip "already enrolled — skipping (bin/setup-totp.php --codes for new recovery codes)"
else
  echo "   One password is the only thing in front of medical and financial records."
  read -rp "   Set up an authenticator app now? [Y/n] " answer
  if [ "${answer:-y}" != "n" ] && [ "${answer:-y}" != "N" ]; then
    "$php_bin" bin/setup-totp.php
  else
    skip "skipped — run bin/setup-totp.php when you are ready"
  fi
fi

# ----------------------------------------------------------------- import --

info "Existing records"

people=$("$php_bin" -r 'require "src/bootstrap.php"; echo (int) qv("SELECT COUNT(*) FROM people");')

if [ "$people" != "0" ]; then
  skip "$people people already on file — skipping the import"
else
  read -rp "   Import from the old site before it is replaced? [Y/n] " answer
  if [ "${answer:-y}" != "n" ] && [ "${answer:-y}" != "N" ]; then
    read -rp "   Old site URL [https://c.lacey.me]: " old
    old="${old:-https://c.lacey.me}"

    echo
    "$php_bin" bin/import-legacy.php --from-site "$old" --dry-run
    echo
    read -rp "   Load that for real? [y/N] " confirm
    if [ "${confirm:-n}" = "y" ] || [ "${confirm:-n}" = "Y" ]; then
      "$php_bin" bin/import-legacy.php --from-site "$old"
    else
      skip "import skipped"
    fi
  fi
fi

# ------------------------------------------------------------------- done --

info "Next"
cat <<NEXT
   1. Point the web server at:
          $APP/public
      A vhost is ready in deploy/apache-vhost.conf (or deploy/nginx.conf),
      then reload the web server.

   2. From your own machine, prove it is actually closed:
          ./deploy.sh --check

   3. Medical records, once the files are on this server:
          php bin/import-health.php --file /path/records.json --dry-run

   4. Nightly encrypted backups:
          php bin/backup.php --to /backups --age-recipient age1...
NEXT

green "Setup complete."
