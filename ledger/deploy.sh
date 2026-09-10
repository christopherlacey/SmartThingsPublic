#!/usr/bin/env bash
#
# deploy.sh — push the Ledger to the host serving c.lacey.me.
#
#   ./deploy.sh --check     verify the live site only, change nothing
#   ./deploy.sh --dry-run   show exactly what would be copied
#   ./deploy.sh             back up, deploy, then verify
#
# Configure once (or export in your shell):
#   SSH_HOST     user@host for the web server
#   LEDGER_DIR   docroot serving c.lacey.me   (default /var/www/ledger)
#   STATE_DIR    writable dir outside the docroot (default /var/lib/ledger)
#   STATE_OWNER  user the web server runs as; guessed if unset (www-data, …)
#
# header.php and footer.php are REPLACED. Every run takes a timestamped backup
# of the current ones first and prints how to roll back, because the versions
# on the server were written before this theme existed.
#
# ledger-config.php and ledger-auth-config.php are never overwritten. On a
# first deploy the templates are copied into place and the script stops so you
# can fill them in.

set -euo pipefail

SSH_HOST="${SSH_HOST:-}"
LEDGER_DIR="${LEDGER_DIR:-/var/www/ledger}"
STATE_DIR="${STATE_DIR:-/var/lib/ledger}"
STATE_OWNER="${STATE_OWNER:-}"
BASE_URL="${BASE_URL:-https://c.lacey.me}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

green() { printf '\033[32m%s\033[0m\n' "$1"; }
red()   { printf '\033[31m%s\033[0m\n' "$1"; }
warn()  { printf '\033[33m%s\033[0m\n' "$1"; }
info()  { printf '\033[36m%s\033[0m\n' "$1"; }

REPLACES=(header.php footer.php)
NEW=(theme.css charts.php map.php finance.php shopping.php ledger-db.php
     import-transactions.php schema.sqlite.sql schema.mysql.sql
     ledger-auth.php ledger-totp.php login.php logout.php
     oauth-callback.php ledger-2fa-setup.php)

verify() {
  local fail=0
  info "Verifying $BASE_URL"

  # ---- the gate ----------------------------------------------------------
  # Every page must turn a signed-out visitor away. This is the check that
  # matters most; if it regresses, the whole dashboard is public again.
  local gated=0 leaked=0
  for page in index.php people.php medical.php trip.php changelog.php \
              finance.php shopping.php blog/index.php; do
    local code
    code=$(curl -sS -o /tmp/.ledger-probe -L --max-time 20 -w '%{http_code}' \
           --max-redirs 0 "$BASE_URL/$page" 2>/dev/null || echo 000)
    if [ "$code" = "302" ] || [ "$code" = "301" ]; then
      gated=$((gated+1))
    elif [ "$code" = "404" ]; then
      :   # page simply isn't there
    else
      red "  FAIL  $page answered $code while signed out (want a redirect to /login.php)"
      leaked=$((leaked+1)); fail=1
    fi
  done
  [ "$leaked" -eq 0 ] && green "  ok    $gated pages turn a signed-out visitor away"

  # The redirect must actually point at the sign-in page.
  local loc
  loc=$(curl -sS -o /dev/null -D- --max-time 20 --max-redirs 0 "$BASE_URL/index.php" 2>/dev/null \
        | grep -i '^location:' | tr -d '\r' | awk '{print $2}' || true)
  case "$loc" in
    */login.php*) green "  ok    signed-out visitors land on the sign-in page" ;;
    *)            red "  FAIL  index.php redirects to '$loc', not /login.php"; fail=1 ;;
  esac

  # ---- the sign-in page itself -------------------------------------------
  local body
  body=$(curl -sS -L --max-time 20 "$BASE_URL/login.php" || true)
  if grep -q 'Continue with Google' <<<"$body"; then
    green "  ok    sign-in page offers Google"
  else
    red "  FAIL  sign-in page is not rendering"; fail=1
  fi
  if grep -qiE 'Fatal error|Parse error|Uncaught' <<<"$body"; then
    red "  FAIL  sign-in page rendered a PHP error"; fail=1
  fi

  # theme.css is static, so it is served without passing the gate. That is
  # fine — it holds nothing — but it must be there or sign-in looks broken.
  local css
  css=$(curl -sS -o /dev/null --max-time 20 -w '%{http_code}' "$BASE_URL/theme.css" || echo 000)
  [ "$css" = "200" ] && green "  ok    theme.css is served" \
                     || { red "  FAIL  theme.css returned $css"; fail=1; }

  # ---- nothing sensitive downloadable ------------------------------------
  # auto_prepend_file only governs PHP, so these rely on .htaccess.
  local exposed=0
  for secret in ledger-auth-config.php ledger-config.php \
                ledger-auth-config.php.example ledger-config.php.example \
                schema.sqlite.sql schema.mysql.sql README.md; do
    local s
    s=$(curl -sS -o /dev/null --max-time 15 -w '%{http_code}' "$BASE_URL/$secret" || echo 000)
    if [ "$s" = "200" ]; then
      red "  FAIL  $secret is downloadable over HTTP"; exposed=$((exposed+1)); fail=1
    fi
  done
  [ "$exposed" -eq 0 ] && green "  ok    config, schema and docs are not downloadable"

  # ---- transport ---------------------------------------------------------
  if curl -sSI --max-time 15 "$BASE_URL/login.php" | grep -qi '^strict-transport-security'; then
    green "  ok    HSTS is set"
  else
    warn "  warn  no Strict-Transport-Security header (is mod_headers on?)"
  fi

  echo
  [ "$fail" -eq 0 ] && green "All checks passed." || red "Some checks failed (see above)."
  return "$fail"
}

if [ "${1:-}" = "--check" ]; then verify; exit $?; fi

if [ "${1:-}" = "--dry-run" ]; then
  info "In order:"
  echo "  1. back up header.php, footer.php, theme.css, .htaccess, .user.ini"
  echo "  2. create $STATE_DIR (mode 700, owned by the web server)"
  echo "  3. seed and CHECK the config — stop here on a first run, having"
  echo "     changed nothing else, so the site is never left gated without one"
  info "Then, only once the config is filled in:"
  info "Would replace in $LEDGER_DIR:"; printf '    %s\n' "${REPLACES[@]}"
  info "Would add to $LEDGER_DIR:";     printf '    %s\n' "${NEW[@]}"
  info "Would wire the gate LAST:";     printf '    %s\n' ".htaccess (from htaccess.example)" ".user.ini (from user.ini.example)"
  info "Would leave alone: index.php people.php medical.php trip.php changelog.php person.php db.php auth.php blog/"
  exit 0
fi

[ -n "$SSH_HOST" ] || { red "Set SSH_HOST first, e.g. SSH_HOST=chris@web01 ./deploy.sh"; exit 2; }

# ---- back up what we are about to overwrite -------------------------------

STAMP="$(date +%Y%m%d-%H%M%S)"
info "Backing up the current header/footer/.htaccess to $LEDGER_DIR/.backup-$STAMP"
ssh "$SSH_HOST" bash -s -- "$LEDGER_DIR" "$STAMP" <<'REMOTE'
set -eu
dir="$1"; stamp="$2"
mkdir -p "$dir/.backup-$stamp"
for f in header.php footer.php theme.css .htaccess .user.ini; do
  [ -f "$dir/$f" ] && cp -p "$dir/$f" "$dir/.backup-$stamp/$f"
done
exit 0
REMOTE
green "  backed up"
echo "  roll back with: ssh $SSH_HOST 'cp -a $LEDGER_DIR/.backup-$STAMP/. $LEDGER_DIR/'"

# ---- config first, before any code lands -----------------------------------
#
# Order matters more than it looks. header.php requires the gate, and the gate
# refuses to serve anything without a config — so copying the code before the
# config is real would take the site down until someone SSHes in to fix it.
# Nothing is copied until the config is present AND filled in.

info "Checking config on $SSH_HOST"
scp -q "$SRC/ledger-config.php.example" "$SRC/ledger-auth-config.php.example" "$SSH_HOST:$LEDGER_DIR/"

rc=0
status=$(ssh "$SSH_HOST" bash -s -- "$LEDGER_DIR" "$STATE_DIR" "$STATE_OWNER" <<'REMOTE'
set -eu
dir="$1"; state="$2"; owner="$3"

# The web server must be able to write the TOTP replay counter and the attempt
# log. Without that the second factor has no replay protection and no lockout,
# so this is set up explicitly and checked, never attempted with `|| true`.
mkdir -p "$state"
if [ -z "$owner" ]; then
  for u in www-data apache httpd nginx; do
    if id "$u" >/dev/null 2>&1; then owner="$u"; break; fi
  done
fi
if [ -z "$owner" ]; then echo "STATE_OWNER_UNKNOWN" >&2; exit 4; fi
if ! chown "$owner" "$state"; then echo "STATE_CHOWN_FAILED" >&2; exit 3; fi
chmod 700 "$state"

seeded=0
for f in ledger-config ledger-auth-config; do
  if [ ! -f "$dir/$f.php" ]; then
    cp "$dir/$f.php.example" "$dir/$f.php" 2>/dev/null || true
    seeded=1
  fi
  chmod 600 "$dir/$f.php" 2>/dev/null || true
done

# A config still carrying template placeholders is not a config.
placeholders=0
for f in ledger-config ledger-auth-config; do
  if grep -q 'REPLACE' "$dir/$f.php" 2>/dev/null; then placeholders=1; fi
done

if [ "$seeded" = "1" ]; then echo "SEEDED"
elif [ "$placeholders" = "1" ]; then echo "PLACEHOLDERS"
else echo "READY"; fi
REMOTE
) || rc=$?

if [ "$rc" = "3" ] || [ "$rc" = "4" ]; then
  echo
  red "Could not give the web server ownership of $STATE_DIR."
  red "Without a writable state directory the second factor has no replay"
  red "protection and no lockout, and sign-in refuses codes rather than"
  red "accepting them unprotected. Nothing has been deployed. Fix and re-run:"
  echo "    ssh $SSH_HOST sudo chown <web-server-user> $STATE_DIR"
  echo "  or name the user: STATE_OWNER=www-data ./deploy.sh"
  exit 1
fi

if [ "$status" != "READY" ]; then
  echo
  if [ "$status" = "SEEDED" ]; then
    red "Config was missing and has been seeded from the templates."
  else
    red "Config still contains REPLACE placeholders."
  fi
  red "NOTHING has been deployed — the site is exactly as it was."
  red "Fill these in, then re-run to deploy:"
  echo "    ssh $SSH_HOST \$EDITOR $LEDGER_DIR/ledger-auth-config.php   # Google client id/secret"
  echo "    ssh $SSH_HOST \$EDITOR $LEDGER_DIR/ledger-config.php        # database"
  echo
  echo "  The Google client: console.cloud.google.com → APIs & Services →"
  echo "  Credentials → Create OAuth client ID → Web application, with"
  echo "  https://c.lacey.me/oauth-callback.php as an authorised redirect URI."
  exit 1
fi
green "  config is present and filled in"

# ---- copy -----------------------------------------------------------------

info "Deploying to $SSH_HOST:$LEDGER_DIR"
for f in "${REPLACES[@]}" "${NEW[@]}"; do
  scp -q "$SRC/$f" "$SSH_HOST:$LEDGER_DIR/$f"
done
green "  code copied"

# The gate wiring goes last: it is the switch that makes the site private, and
# it should only flip once everything it depends on is already in place.
TMP="$(mktemp -d)"
sed "s|__LEDGER_DIR__|$LEDGER_DIR|g" "$SRC/htaccess.example"  > "$TMP/.htaccess"
sed "s|__LEDGER_DIR__|$LEDGER_DIR|g" "$SRC/user.ini.example"  > "$TMP/.user.ini"
scp -q "$TMP/.htaccess" "$TMP/.user.ini" "$SSH_HOST:$LEDGER_DIR/"
rm -rf "$TMP"
green "  gate wired up (.htaccess and .user.ini rendered for $LEDGER_DIR)"

echo
verify
