#!/usr/bin/env bash
#
# deploy.sh — push the Model X dashboard to the Apache host.
#
#   ./deploy.sh --check     verify the live site only, change nothing
#   ./deploy.sh             deploy, then verify
#
# Configure once (or export these in your shell):
#   SSH_HOST   user@host for the web server
#   DASH_DIR   docroot path serving lacey.me/Tesla-Model-X-Dashboard
#   BASE_URL   public URL, if it ever differs from the default below
#
# data.json is never overwritten — it holds the real information and lives only
# on the server. On a first deploy the example is seeded and the script stops,
# so the car never shows a screen full of REPLACE.

set -euo pipefail

SSH_HOST="${SSH_HOST:-}"
DASH_DIR="${DASH_DIR:-/var/www/lacey.me/Tesla-Model-X-Dashboard}"
BASE_URL="${BASE_URL:-https://lacey.me/Tesla-Model-X-Dashboard}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

green() { printf '\033[32m%s\033[0m\n' "$1"; }
red()   { printf '\033[31m%s\033[0m\n' "$1"; }
info()  { printf '\033[36m%s\033[0m\n' "$1"; }
warn()  { printf '\033[33m%s\033[0m\n' "$1"; }

fetch() { curl -sS -L --max-time 20 "$1"; }
code()  { curl -sS -o /dev/null -L --max-time 20 -w '%{http_code}' "$1" || echo 000; }

verify() {
  local fail=0
  info "Verifying $BASE_URL"

  # The page itself should be the current build.
  if fetch "$BASE_URL/" | grep -q 'id="rail"'; then
    green "  ok    page is the current version"
  else
    red   "  FAIL  page missing or stale"; fail=1
  fi

  # Every static asset the page needs. A 404 on any of these is a blank or
  # broken dashboard in the car, which you'd only find out while driving.
  local asset
  for asset in app.css app.js vendor/leaflet/leaflet.js vendor/leaflet/leaflet.css; do
    local c; c=$(code "$BASE_URL/$asset")
    if [ "$c" = "200" ]; then
      green "  ok    $asset"
    else
      red   "  FAIL  $asset returned $c"; fail=1
    fi
  done

  # data.json must exist and be valid JSON — the whole page is empty without it.
  local dj; dj=$(fetch "$BASE_URL/data.json" || true)
  if [ -z "$dj" ]; then
    red "  FAIL  data.json is empty or unreachable"; fail=1
  elif ! printf '%s' "$dj" | python3 -m json.tool >/dev/null 2>&1; then
    red "  FAIL  data.json is not valid JSON"; fail=1
  else
    green "  ok    data.json parses"
    # The check that matters most: real data, not the shipped placeholders.
    if printf '%s' "$dj" | grep -q 'REPLACE'; then
      red "  FAIL  data.json still contains REPLACE placeholders"; fail=1
    else
      green "  ok    data.json has no placeholders left"
      local n; n=$(printf '%s' "$dj" | python3 -c 'import json,sys; print(len(json.load(sys.stdin).get("people",[])))' 2>/dev/null || echo 0)
      green "  ok    $n people configured"
    fi
  fi

  # The page asks not to be indexed. Confirm that survived the copy.
  if fetch "$BASE_URL/" | grep -q 'name="robots"'; then
    green "  ok    noindex present"
  else
    warn  "  warn  noindex meta missing"
  fi

  if [ "$fail" -eq 0 ]; then green "All checks passed."; else red "Checks failed."; return 1; fi
}

if [ "${1:-}" = "--check" ]; then
  verify
  exit $?
fi

if [ -z "$SSH_HOST" ]; then
  red "SSH_HOST is not set. See the header of this script."
  exit 2
fi

info "Deploying to $SSH_HOST:$DASH_DIR"

ssh "$SSH_HOST" "mkdir -p '$DASH_DIR/vendor/leaflet/images'"

# Site files. data.json is deliberately absent from this list.
scp -q "$SRC/index.html" "$SRC/app.css" "$SRC/app.js" "$SRC/data.example.json" \
       "$SSH_HOST:$DASH_DIR/"
scp -q "$SRC/vendor/leaflet/leaflet.js" "$SRC/vendor/leaflet/leaflet.css" \
       "$SRC/vendor/leaflet/LICENSE" "$SSH_HOST:$DASH_DIR/vendor/leaflet/"
scp -q "$SRC"/vendor/leaflet/images/* "$SSH_HOST:$DASH_DIR/vendor/leaflet/images/"

green "Files copied."

# Seed data.json only if it isn't there, and stop rather than going live blank.
if ssh "$SSH_HOST" "test -f '$DASH_DIR/data.json'"; then
  green "data.json already present — left untouched."
else
  ssh "$SSH_HOST" "cp '$DASH_DIR/data.example.json' '$DASH_DIR/data.json'"
  warn "data.json did not exist. The example has been copied into place."
  warn "Fill it in before the car sees this:"
  warn "  ssh $SSH_HOST \$EDITOR $DASH_DIR/data.json"
  warn "Then re-run: ./deploy.sh --check"
  exit 0
fi

verify
