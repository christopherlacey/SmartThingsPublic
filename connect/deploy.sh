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
  local codes bad=0 unset_n=0 live=0
  codes=$(grep -oE 'go\.php\?c=[a-z0-9-]+' "$SRC/index.html" | sed 's/.*c=//' | sort -u)
  for c in $codes; do
    local code
    # Retry once: a single slow response from shared hosting is not a failure.
    code=$(curl -sS -o /dev/null --max-time 20 -w '%{http_code}' \
           "https://emergency.chrislacey.com/go.php?c=$c" 2>/dev/null)
    # On a timeout curl still prints 000, so retry on that value rather than on
    # exit status — chaining with || concatenated both codes into "000302".
    if [ "$code" = "000" ] || [ -z "$code" ]; then
      code=$(curl -sS -o /dev/null --max-time 20 -w '%{http_code}' \
             "https://emergency.chrislacey.com/go.php?c=$c" 2>/dev/null)
    fi
    [ -z "$code" ] && code=000
    case "$code" in
      302) live=$((live+1)) ;;
      # 503 is go.php reporting a blank entry in ~/.contact-config.php. That is a
      # config gap, not a broken deploy, so it is called out separately.
      503) info "  ---   c=$c has no value in ~/.contact-config.php yet"; unset_n=$((unset_n+1)) ;;
      *)   red  "  FAIL  c=$c returned $code"; bad=$((bad+1)); fail=1 ;;
    esac
  done
  green "  ok    $live channel code(s) redirecting"
  [ "$unset_n" -gt 0 ] && info "        $unset_n still blank in ~/.contact-config.php - edit it, no redeploy needed"

  # An unknown code must 404, not redirect somewhere arbitrary.
  # go.php sends an unknown code back to the emergency page with a 302; that is
  # its long-standing behaviour, not something to "fix" to a 404.
  local nf
  nf=$(curl -sS -o /dev/null --max-time 20 -w '%{http_code}' \
       "https://emergency.chrislacey.com/go.php?c=definitely-not-real" || echo 000)
  case "$nf" in
    302|404) green "  ok    unknown code rejected ($nf)" ;;
    *)       red   "  FAIL  unknown code returned $nf"; fail=1 ;;
  esac

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
  if [ "$fail" -eq 0 ] && [ "${unset_n:-0}" -eq 0 ]; then
    green "All checks passed."
  elif [ "$fail" -eq 0 ]; then
    green "Deploy is healthy. Fill in the blank entries above to finish."
  else
    red "Some checks failed (see above)."
  fi
  return "$fail"
}

if [ "${1:-}" = "--check" ]; then verify; exit $?; fi

# --- Local mode: the web server IS this machine (DreamHost shared hosting, where
#     sites live at ~/example.com rather than under /var/www). No SSH involved.
if [ "${1:-}" = "--local" ]; then
  CONNECT_LOCAL="${CONNECT_LOCAL:-$HOME/connect.chrislacey.com}"
  EMERG_LOCAL="${EMERG_LOCAL:-$HOME/emergency.chrislacey.com}"
  CONFIG="${CONFIG:-$HOME/.contact-config.php}"
  STAMP=$(date +%Y%m%d-%H%M%S)

  for d in "$CONNECT_LOCAL" "$EMERG_LOCAL"; do
    [ -d "$d" ] || { red "No such directory: $d"; exit 2; }
  done

  # Back up anything we are about to replace. These are live files.
  mkdir -p "$HOME/.connect-backups/$STAMP"
  for f in "$CONNECT_LOCAL/Index.html" "$CONNECT_LOCAL/index.html" "$EMERG_LOCAL/go.php" "$EMERG_LOCAL/alert.php"; do
    [ -f "$f" ] && cp -p "$f" "$HOME/.connect-backups/$STAMP/$(basename "$(dirname "$f")")--$(basename "$f")"
  done
  green "  backed up existing files to ~/.connect-backups/$STAMP"

  # The page. Keep whichever index casing the site already uses, so we replace
  # the served file instead of adding a second one Apache might pick between.
  if [ -f "$CONNECT_LOCAL/Index.html" ]; then
    cp "$SRC/index.html" "$CONNECT_LOCAL/Index.html"; green "  page  -> $CONNECT_LOCAL/Index.html"
  else
    cp "$SRC/index.html" "$CONNECT_LOCAL/index.html"; green "  page  -> $CONNECT_LOCAL/index.html"
  fi

  cp "$SRC/go.php"    "$EMERG_LOCAL/go.php";    green "  go.php -> $EMERG_LOCAL"
  cp "$SRC/alert.php" "$EMERG_LOCAL/alert.php"; green "  alert.php -> $EMERG_LOCAL"

  # Config lives above the web root and is never overwritten once it exists.
  if [ ! -f "$CONFIG" ]; then
    cp "$SRC/contact-config.php.example" "$CONFIG"
    chmod 600 "$CONFIG"
    echo
    red "Created $CONFIG with every value blank."
    red "Until you fill it in, each button returns 503 'not set up yet' — deliberately,"
    red "so nothing dials a wrong number in the meantime. Edit it now:"
    echo "    nano $CONFIG"
    echo "  then re-run: $0 --check"
    exit 1
  fi
  chmod 600 "$CONFIG"
  green "  config already present at $CONFIG (left untouched)"
  echo
  verify
  exit $?
fi

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
