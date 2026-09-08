#!/usr/bin/env bash
#
# deploy.sh — push the connect page and its backend to the Apache host.
#
#   ./deploy.sh --check                 verify the live site only, change nothing
#   ./deploy.sh                         deploy, then verify
#
# Configure once (or export these in your shell):
#   SSH_HOST      user@host for the web server
#   CONNECT_DIR   docroot serving connect.chrislacey.com
#   EMERGENCY_DIR docroot serving emergency.chrislacey.com
#
# Config files are never overwritten. On first deploy the .example templates are
# copied into place and the script stops so you can fill them in — deploying a
# blank contacts.php would leave every button 404ing.

set -euo pipefail

SSH_HOST="${SSH_HOST:-}"
CONNECT_DIR="${CONNECT_DIR:-/var/www/connect}"
EMERGENCY_DIR="${EMERGENCY_DIR:-/var/www/emergency}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

green() { printf '\033[32m%s\033[0m\n' "$1"; }
red()   { printf '\033[31m%s\033[0m\n' "$1"; }
info()  { printf '\033[36m%s\033[0m\n' "$1"; }

verify() {
  local fail=0
  info "Verifying live site"

  # The page itself should be the new one.
  if curl -sS -L --max-time 15 https://connect.chrislacey.com | grep -q 'Student or Friend of Addy'; then
    green "  ok    page is the current version"
  else
    red   "  FAIL  page is still the old version"; fail=1
  fi

  # Every channel code must resolve. This is the check that would have caught
  # the missing contacts.php that took the whole site down.
  local codes bad=0
  codes=$(grep -oE 'go\.php\?c=[a-z0-9-]+' "$SRC/index.html" | sed 's/.*c=//' | sort -u)
  for c in $codes; do
    local code
    code=$(curl -sS -o /dev/null --max-time 10 -w '%{http_code}' \
           "https://emergency.chrislacey.com/go.php?c=$c" || echo 000)
    if [ "$code" != "302" ]; then
      red "  FAIL  c=$c returned $code (want 302)"; bad=$((bad+1)); fail=1
    fi
  done
  [ "$bad" -eq 0 ] && green "  ok    all $(echo "$codes" | wc -w | tr -d ' ') channel codes redirect"

  # An unknown code must 404, not redirect somewhere arbitrary.
  local nf
  nf=$(curl -sS -o /dev/null --max-time 10 -w '%{http_code}' \
       "https://emergency.chrislacey.com/go.php?c=definitely-not-real" || echo 000)
  if [ "$nf" = "404" ]; then green "  ok    unknown code 404s"; else red "  FAIL  unknown code returned $nf"; fail=1; fi

  # The alert endpoint must accept a beacon.
  local al
  al=$(curl -sS -o /dev/null --max-time 10 -w '%{http_code}' -X POST \
       -H 'Content-Type: text/plain;charset=UTF-8' \
       --data-binary '{"button":"deploy-check"}' \
       https://emergency.chrislacey.com/alert.php || echo 000)
  if [ "$al" = "204" ]; then green "  ok    alert endpoint accepts beacons"; else red "  FAIL  alert.php returned $al"; fail=1; fi

  # The alert log must not be reachable from the web.
  local lg
  lg=$(curl -sS -o /dev/null --max-time 10 -w '%{http_code}' \
       https://emergency.chrislacey.com/911-alerts.log || echo 000)
  if [ "$lg" = "404" ] || [ "$lg" = "403" ]; then
    green "  ok    alert log is not web-readable ($lg)"
  else
    red "  FAIL  alert log reachable over HTTP ($lg) — it holds IPs and GPS positions"; fail=1
  fi

  echo
  [ "$fail" -eq 0 ] && green "All checks passed." || red "Some checks failed (see above)."
  return "$fail"
}

if [ "${1:-}" = "--check" ]; then verify; exit $?; fi

[ -n "$SSH_HOST" ] || { red "Set SSH_HOST first, e.g. SSH_HOST=chris@web01 ./deploy.sh"; exit 2; }

info "Deploying to $SSH_HOST"
scp "$SRC/index.html"            "$SSH_HOST:$CONNECT_DIR/index.html"
scp "$SRC/go.php" "$SRC/alert.php" "$SSH_HOST:$EMERGENCY_DIR/"
# Templates must be present remotely for the seeding step below to copy from.
scp "$SRC/contacts.php.example" "$SRC/alert-config.php.example" "$SSH_HOST:$EMERGENCY_DIR/"
green "  code copied"

# Seed configs only if absent, then stop so they can be filled in.
seeded=$(ssh "$SSH_HOST" bash -s -- "$EMERGENCY_DIR" <<'REMOTE'
set -eu
dir="$1"; seeded=0
for f in contacts alert-config; do
  if [ ! -f "$dir/$f.php" ]; then
    cp "$dir/$f.php.example" "$dir/$f.php" 2>/dev/null || true
    seeded=1
  fi
  chmod 600 "$dir/$f.php" 2>/dev/null || true
done
echo "$seeded"
REMOTE
)

if [ "$seeded" = "1" ]; then
  echo
  red "Config was missing and has been seeded from the templates."
  red "Fill in the real values before this site works:"
  echo "    ssh $SSH_HOST \$EDITOR $EMERGENCY_DIR/contacts.php"
  echo "    ssh $SSH_HOST \$EDITOR $EMERGENCY_DIR/alert-config.php"
  echo "  then re-run: ./deploy.sh --check"
  exit 1
fi

echo
verify
