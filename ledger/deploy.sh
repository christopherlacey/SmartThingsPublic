#!/usr/bin/env bash
#
# deploy.sh — push The Lacey Ledger to the web host and verify it.
#
#   ./deploy.sh --check     verify the live site only, change nothing
#   ./deploy.sh             deploy, then verify
#
# Configure once (or export in your shell):
#   SSH_HOST     user@host for the web server
#   WEB_DIR      docroot serving c.lacey.me          (gets public/)
#   APP_DIR      private dir above the docroot       (gets src/, bin/, schema.sql)
#   SITE         https://c.lacey.me
#
# The application code lives OUTSIDE the docroot on purpose. Only public/ is
# reachable over HTTP; config.php and the database never are.

set -euo pipefail

SSH_HOST="${SSH_HOST:-}"
WEB_DIR="${WEB_DIR:-/var/www/ledger/public}"
APP_DIR="${APP_DIR:-/var/www/ledger}"
SITE="${SITE:-https://c.lacey.me}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

green() { printf '\033[32m%s\033[0m\n' "$1"; }
red()   { printf '\033[31m%s\033[0m\n' "$1"; }
info()  { printf '\033[36m%s\033[0m\n' "$1"; }

verify() {
  local fail=0
  info "Verifying $SITE"

  # 1. The thing that was actually broken before: every page holding data must
  #    refuse an anonymous request. A 200 here is the whole bug coming back.
  local page code
  for page in index.php health.php money.php errands.php people.php changelog.php "person.php?id=1"; do
    code=$(curl -sS -o /dev/null --max-time 15 -w '%{http_code}' "$SITE/$page" || echo 000)
    if [ "$code" = "302" ] || [ "$code" = "303" ]; then
      green "  ok    /$page redirects anonymous visitors to sign-in"
    else
      red   "  FAIL  /$page returned $code to an anonymous request (want 302)"; fail=1
    fi
  done

  # 2. The sign-in page itself should be up.
  code=$(curl -sS -o /dev/null --max-time 15 -w '%{http_code}' "$SITE/login.php" || echo 000)
  if [ "$code" = "200" ]; then green "  ok    sign-in page is up"; else red "  FAIL  login.php returned $code"; fail=1; fi

  # 3. Nothing anonymous may reach the config, the database or the app code.
  local path
  for path in config.php ledger.sqlite ledger.sqlite-wal src/auth.php src/models.php \
              schema.sql bin/set-password.php ../config.php ../ledger.sqlite; do
    code=$(curl -sS -o /dev/null --max-time 15 -w '%{http_code}' "$SITE/$path" || echo 000)
    if [ "$code" = "404" ] || [ "$code" = "403" ]; then
      green "  ok    /$path is not served ($code)"
    else
      red   "  FAIL  /$path returned $code — it must not be reachable"; fail=1
    fi
  done

  # 4. The location endpoint must reject an unauthenticated write.
  code=$(curl -sS -o /dev/null --max-time 15 -w '%{http_code}' -X POST \
         -H 'Content-Type: application/json' -d '{"lat":0,"lon":0}' \
         "$SITE/api/location.php" || echo 000)
  if [ "$code" = "401" ] || [ "$code" = "503" ]; then
    green "  ok    location endpoint rejects an untokened write ($code)"
  else
    red   "  FAIL  location endpoint returned $code to an untokened write"; fail=1
  fi

  # 5. Security headers a page of medical and financial records should carry.
  local headers
  headers=$(curl -sS -D - -o /dev/null --max-time 15 "$SITE/login.php" || true)
  local want
  for want in "content-security-policy" "x-content-type-options" "x-frame-options" "cache-control"; do
    if grep -qi "^$want:" <<<"$headers"; then
      green "  ok    $want is set"
    else
      red   "  FAIL  $want header missing"; fail=1
    fi
  done

  # 6. HTTPS should not be optional for this site.
  local scheme_code
  scheme_code=$(curl -sS -o /dev/null --max-time 15 -w '%{http_code}' \
                "http://${SITE#https://}/login.php" || echo 000)
  if [ "$scheme_code" = "301" ] || [ "$scheme_code" = "302" ] || [ "$scheme_code" = "308" ]; then
    green "  ok    plain HTTP redirects to HTTPS"
  else
    red   "  WARN  plain HTTP returned $scheme_code — it should redirect to HTTPS"
  fi

  echo
  [ "$fail" -eq 0 ] && green "All checks passed." || red "Some checks failed (see above)."
  return "$fail"
}

if [ "${1:-}" = "--check" ]; then verify; exit $?; fi

[ -n "$SSH_HOST" ] || { red "Set SSH_HOST first, e.g. SSH_HOST=chris@web01 ./deploy.sh"; exit 2; }

info "Deploying to $SSH_HOST"

ssh "$SSH_HOST" "mkdir -p '$APP_DIR/src' '$APP_DIR/bin' '$WEB_DIR/assets' '$WEB_DIR/api'"

scp -q "$SRC"/src/*.php              "$SSH_HOST:$APP_DIR/src/"
scp -q "$SRC"/bin/*.php              "$SSH_HOST:$APP_DIR/bin/"
scp -q "$SRC"/schema.sql             "$SSH_HOST:$APP_DIR/"
scp -q "$SRC"/config.php.example     "$SSH_HOST:$APP_DIR/"
scp -q "$SRC"/public/*.php           "$SSH_HOST:$WEB_DIR/"
scp -q "$SRC"/public/api/*.php       "$SSH_HOST:$WEB_DIR/api/"
scp -q "$SRC"/public/assets/*        "$SSH_HOST:$WEB_DIR/assets/"
green "  code copied"

# Seed the config if it is missing, then stop — a ledger with no password set is
# not something to bring up and walk away from.
seeded=$(ssh "$SSH_HOST" bash -s -- "$APP_DIR" <<'REMOTE'
set -eu
dir="$1"
if [ ! -f "$dir/config.php" ]; then
  cp "$dir/config.php.example" "$dir/config.php"
  chmod 600 "$dir/config.php"
  echo 1
else
  chmod 600 "$dir/config.php"
  echo 0
fi
REMOTE
)

if [ "$seeded" = "1" ]; then
  echo
  red "config.php was missing and has been seeded from the template."
  red "Fill it in, then create the database and the password:"
  echo "    ssh $SSH_HOST \$EDITOR $APP_DIR/config.php"
  echo "    ssh $SSH_HOST php $APP_DIR/bin/init-db.php"
  echo "    ssh $SSH_HOST php $APP_DIR/bin/set-password.php chris"
  echo "  then re-run: ./deploy.sh --check"
  exit 1
fi

# Apply any new tables to the existing database. Every statement is
# CREATE ... IF NOT EXISTS, so this never touches data that is already there.
ssh "$SSH_HOST" "php '$APP_DIR/bin/init-db.php'"
green "  schema applied"

echo
verify
