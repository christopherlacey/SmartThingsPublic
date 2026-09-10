#!/usr/bin/env bash
#
# deploy.sh — push the Ledger's new theme and the Finance/Shopping tabs to the
# host serving c.lacey.me.
#
#   ./deploy.sh --check     verify the live site only, change nothing
#   ./deploy.sh --dry-run   show exactly what would be copied
#   ./deploy.sh             back up, deploy, then verify
#
# Configure once (or export in your shell):
#   SSH_HOST     user@host for the web server
#   LEDGER_DIR   docroot serving c.lacey.me   (default /var/www/ledger)
#
# header.php and footer.php are REPLACED. Every run takes a timestamped backup
# of the current ones first and prints how to roll back, because the versions
# on the server were written before this theme existed.
#
# ledger-config.php is never overwritten. On a first deploy the template is
# copied into place and the script stops so you can fill it in.

set -euo pipefail

SSH_HOST="${SSH_HOST:-}"
LEDGER_DIR="${LEDGER_DIR:-/var/www/ledger}"
BASE_URL="${BASE_URL:-https://c.lacey.me}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

green() { printf '\033[32m%s\033[0m\n' "$1"; }
red()   { printf '\033[31m%s\033[0m\n' "$1"; }
info()  { printf '\033[36m%s\033[0m\n' "$1"; }

# Files that make up this change. header/footer replace what is there; the
# rest are new.
REPLACES=(header.php footer.php)
NEW=(theme.css charts.php map.php finance.php shopping.php ledger-db.php
     import-transactions.php schema.sqlite.sql schema.mysql.sql)

verify() {
  local fail=0
  info "Verifying $BASE_URL"

  # Both new tabs must answer, and must not be showing a PHP error.
  for page in finance.php shopping.php; do
    local body code
    body=$(curl -sS -L --max-time 20 "$BASE_URL/$page" || true)
    code=$(curl -sS -o /dev/null -L --max-time 20 -w '%{http_code}' "$BASE_URL/$page" || echo 000)

    if [ "$code" != "200" ]; then
      red "  FAIL  $page returned $code"; fail=1; continue
    fi
    if grep -qiE 'Fatal error|Parse error|Warning</b>|Uncaught' <<<"$body"; then
      red "  FAIL  $page rendered a PHP error"; fail=1; continue
    fi
    green "  ok    $page renders"
  done

  # The new nav must be live on the pages we did NOT edit — that is the whole
  # point of the shared header.
  local nav
  nav=$(curl -sS -L --max-time 20 "$BASE_URL/index.php" || true)
  if grep -q 'finance.php' <<<"$nav" && grep -q 'shopping.php' <<<"$nav"; then
    green "  ok    Overview picked up the new tabs"
  else
    red   "  FAIL  Overview is not using the shared header — its nav has no Finance/Shopping"
    red   "        (that page probably prints its own <nav> instead of including header.php)"
    fail=1
  fi

  # The stylesheet has to actually be reachable, or every tab renders unstyled.
  local css
  css=$(curl -sS -o /dev/null --max-time 20 -w '%{http_code}' "$BASE_URL/theme.css" || echo 000)
  if [ "$css" = "200" ]; then green "  ok    theme.css is served"; else red "  FAIL  theme.css returned $css"; fail=1; fi

  # Config and schema must never be downloadable — config holds the DB password.
  for secret in ledger-config.php.example schema.sqlite.sql schema.mysql.sql; do
    local s
    s=$(curl -sS -o /dev/null --max-time 15 -w '%{http_code}' "$BASE_URL/$secret" || echo 000)
    if [ "$s" = "200" ]; then
      red "  WARN  $secret is downloadable over HTTP — block it in Apache"
    fi
  done

  echo
  [ "$fail" -eq 0 ] && green "All checks passed." || red "Some checks failed (see above)."
  return "$fail"
}

if [ "${1:-}" = "--check" ]; then verify; exit $?; fi

if [ "${1:-}" = "--dry-run" ]; then
  info "Would replace in $LEDGER_DIR:"; printf '    %s\n' "${REPLACES[@]}"
  info "Would add to $LEDGER_DIR:";     printf '    %s\n' "${NEW[@]}"
  info "Would leave alone: index.php people.php medical.php trip.php changelog.php person.php db.php"
  exit 0
fi

[ -n "$SSH_HOST" ] || { red "Set SSH_HOST first, e.g. SSH_HOST=chris@web01 ./deploy.sh"; exit 2; }

# ---- back up what we are about to overwrite -------------------------------

STAMP="$(date +%Y%m%d-%H%M%S)"
info "Backing up the current header/footer to $LEDGER_DIR/.backup-$STAMP"
ssh "$SSH_HOST" bash -s -- "$LEDGER_DIR" "$STAMP" <<'REMOTE'
set -eu
dir="$1"; stamp="$2"
mkdir -p "$dir/.backup-$stamp"
for f in header.php footer.php theme.css; do
  [ -f "$dir/$f" ] && cp -p "$dir/$f" "$dir/.backup-$stamp/$f"
done
exit 0
REMOTE
green "  backed up"
echo "  roll back with: ssh $SSH_HOST 'cp $LEDGER_DIR/.backup-$STAMP/* $LEDGER_DIR/'"

# ---- copy -----------------------------------------------------------------

info "Deploying to $SSH_HOST:$LEDGER_DIR"
for f in "${REPLACES[@]}" "${NEW[@]}"; do
  scp -q "$SRC/$f" "$SSH_HOST:$LEDGER_DIR/$f"
done
scp -q "$SRC/ledger-config.php.example" "$SSH_HOST:$LEDGER_DIR/"
green "  code copied"

# ---- seed config on first run ---------------------------------------------

seeded=$(ssh "$SSH_HOST" bash -s -- "$LEDGER_DIR" <<'REMOTE'
set -eu
dir="$1"; seeded=0
if [ ! -f "$dir/ledger-config.php" ]; then
  cp "$dir/ledger-config.php.example" "$dir/ledger-config.php" 2>/dev/null || true
  seeded=1
fi
chmod 600 "$dir/ledger-config.php" 2>/dev/null || true
echo "$seeded"
REMOTE
)

if [ "$seeded" = "1" ]; then
  echo
  red "ledger-config.php was missing and has been seeded from the template."
  red "Fill in the database details before Finance and Shopping will work:"
  echo "    ssh $SSH_HOST \$EDITOR $LEDGER_DIR/ledger-config.php"
  echo "  then load the schema and re-run: ./deploy.sh --check"
  exit 1
fi

echo
verify
