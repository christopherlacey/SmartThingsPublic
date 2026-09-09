#!/usr/bin/env bash
#
# deploy.sh — push the Model X dashboard to the Apache host.
#
#   ./deploy.sh --check     verify the live site only, change nothing
#   ./deploy.sh --local     install from this checkout into DASH_DIR on THIS
#                           machine -- use when running on the web server itself,
#                           which needs no ssh, no scp and no keys
#   ./deploy.sh             copy from here to SSH_HOST over ssh, then verify
#
# Configure once (or export these in your shell):
#   SSH_HOST   user@host for the web server, e.g. myfsdev@lacey.me
#   HOST_ROOT  docroot of the subdomain. Defaults to ~/<domain> where that
#              exists (shared hosting) and /var/www/<domain> otherwise.
#   BASE_URL   public URL of the dashboard, if it differs from the default
#
# The car loads tesla.lacey.me. grok.lacey.me is a redirect onto it, configured
# separately at the DNS/vhost level -- nothing in this script touches it.
#
# The dashboard lives at an unlisted path rather than a guessable one, so the
# path itself is a secret. Two consequences the code below has to respect:
#
#   * The path contains '$' and '!'. Both are legal in a URL and on disk, but a
#     remote shell will happily expand them, so every remote path is passed
#     single-quoted. Locally, SECRET_PATH must stay in single quotes too --
#     in double quotes bash would read "$23" as a positional parameter and
#     quietly deploy to the wrong directory.
#   * data.json is fetched by the page, so it is readable by anyone who has the
#     path. The path is the only thing protecting it.
#
# data.json is never overwritten. On a first deploy the example is seeded and
# the script stops, so the car never shows a screen full of REPLACE.

set -euo pipefail

# Single quotes are load-bearing here. Do not "tidy" these into double quotes.
SECRET_PATH='TmX$23!'

SSH_HOST="${SSH_HOST:-}"
BASE_URL="${BASE_URL:-https://tesla.lacey.me/$SECRET_PATH}"

# Derive the docroot from BASE_URL rather than hardcoding it, so overriding one
# can't leave the other quietly pointing at a different host.
BASE_HOST=$(printf '%s' "$BASE_URL" | sed -E 's#^https?://##; s#/.*##')

# Where the docroot lives depends on the kind of host. Shared hosting (this is
# a DreamHost-style box: pdx1-shared-...) puts each domain under the user's home
# directory and gives no write access to /var/www at all, so prefer ~/<domain>
# and fall back to /var/www/<domain> only on a box that actually uses it.
if [ -z "${HOST_ROOT:-}" ]; then
  if [ -d "$HOME/$BASE_HOST" ]; then
    HOST_ROOT="$HOME/$BASE_HOST"
  elif [ -d "/var/www/$BASE_HOST" ]; then
    HOST_ROOT="/var/www/$BASE_HOST"
  else
    HOST_ROOT="$HOME/$BASE_HOST"
  fi
fi
DASH_DIR="${DASH_DIR:-$HOST_ROOT/$SECRET_PATH}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

green() { printf '\033[32m%s\033[0m\n' "$1"; }
red()   { printf '\033[31m%s\033[0m\n' "$1"; }
info()  { printf '\033[36m%s\033[0m\n' "$1"; }
warn()  { printf '\033[33m%s\033[0m\n' "$1"; }

fetch() { curl -sS -L --max-time 20 "$1"; }
code()  { curl -sS -o /dev/null -L --max-time 20 -w '%{http_code}' "$1" || echo 000; }

# This page publishes three people's live coordinates and full medical records.
# Sending it to a domain that isn't yours is not a recoverable mistake, so an
# unrecognised host has to be confirmed on purpose.
guard_domain() {
  local host="$BASE_HOST"
  case "$host" in
    *.lacey.me|lacey.me|*.chrislacey.com|chrislacey.com)
      green "  ok    target host $host is one you control" ;;
    *)
      red   "REFUSING: $host is not a lacey.me or chrislacey.com host."
      red   "This deploys live location and medical data. Check the domain."
      red   "If it really is yours, re-run with CONFIRM_DOMAIN=yes"
      [ "${CONFIRM_DOMAIN:-}" = "yes" ] || exit 3
      warn  "  CONFIRM_DOMAIN=yes given — proceeding to $host" ;;
  esac
}

verify() {
  local fail=0
  info "Verifying $BASE_URL"

  if fetch "$BASE_URL/" | grep -q 'id="rail"'; then
    green "  ok    page is the current version"
  else
    red   "  FAIL  page missing or stale"; fail=1
  fi

  # Every static asset the page needs. A 404 on any of these is a broken
  # dashboard, which you would otherwise discover while driving.
  local asset
  for asset in app.css app.js vendor/leaflet/leaflet.js vendor/leaflet/leaflet.css; do
    local c; c=$(code "$BASE_URL/$asset")
    if [ "$c" = "200" ]; then green "  ok    $asset"
    else red "  FAIL  $asset returned $c"; fail=1; fi
  done

  local dj; dj=$(fetch "$BASE_URL/data.json" || true)
  if [ -z "$dj" ]; then
    red "  FAIL  data.json is empty or unreachable"; fail=1
  elif ! printf '%s' "$dj" | python3 -m json.tool >/dev/null 2>&1; then
    red "  FAIL  data.json is not valid JSON"; fail=1
  else
    green "  ok    data.json parses"
    if printf '%s' "$dj" | grep -q 'REPLACE'; then
      red "  FAIL  data.json still contains REPLACE placeholders"; fail=1
    else
      green "  ok    data.json has no placeholders left"
      local n; n=$(printf '%s' "$dj" | python3 -c 'import json,sys; print(len(json.load(sys.stdin).get("people",[])))' 2>/dev/null || echo 0)
      green "  ok    $n people configured"
    fi
  fi

  # The unlisted path is the only thing protecting all of the above. If the
  # parent directory is listable, the path is not actually secret.
  # Ask the question that actually matters -- does the parent hand the secret
  # path back to whoever asks -- rather than matching one server's wording for
  # an autoindex. Apache says "Index of", others say "Directory listing for",
  # and a listing is only dangerous if our directory is in it.
  local parent listing leak=0
  parent=$(printf '%s' "$BASE_URL" | sed -E 's#/[^/]+$##')
  listing=$(fetch "$parent/" || true)
  if printf '%s' "$listing" | grep -qiE 'TmX(%24|\$)23(%21|!)'; then
    red "  FAIL  $parent/ lists the secret path in its response body"; leak=1
  elif printf '%s' "$listing" | grep -qiE 'index of[[:space:]]*/|directory listing for|<title>Index'; then
    red "  FAIL  $parent/ is directory-listable — set 'Options -Indexes'"; leak=1
  fi
  if [ "$leak" -eq 1 ]; then fail=1; else green "  ok    parent directory does not leak the path"; fi

  # And the path must not be advertised to anyone the browser talks to.
  if fetch "$BASE_URL/" | grep -q 'name="robots"'; then
    green "  ok    noindex present"
  else
    warn  "  warn  noindex meta missing"
  fi

  if [ "$fail" -eq 0 ]; then green "All checks passed."; else red "Checks failed."; return 1; fi
}


# Install from this checkout into DASH_DIR on the machine we are already on.
# This is the path to use when you have sshed into the web server: cloning the
# repo there and running this avoids needing a workstation with both a checkout
# and ssh keys.
install_local() {
  info "Installing into $DASH_DIR (local)"

  if [ ! -d "$HOST_ROOT" ]; then
    warn "$HOST_ROOT does not exist yet — creating it."
    warn "Note: on shared hosting the directory alone is not enough. The domain"
    warn "$BASE_HOST also has to be added in the hosting panel, or nothing will"
    warn "be served from here."
  fi

  if ! mkdir -p "$DASH_DIR/vendor/leaflet/images" 2>/dev/null; then
    red "Cannot create $DASH_DIR — check the path and that you own it."
    red "If it belongs to the web user, re-run under that account."
    exit 4
  fi

  install -m 644 "$SRC/index.html" "$SRC/app.css" "$SRC/app.js" \
                 "$SRC/data.example.json" "$SRC/.htaccess" "$DASH_DIR/"
  install -m 644 "$SRC/vendor/leaflet/leaflet.js" "$SRC/vendor/leaflet/leaflet.css" \
                 "$SRC/vendor/leaflet/LICENSE" "$DASH_DIR/vendor/leaflet/"
  install -m 644 "$SRC"/vendor/leaflet/images/*.png "$DASH_DIR/vendor/leaflet/images/"

  green "Files installed."

  # Same rule as the remote path: never clobber real data, and never go live
  # showing placeholders.
  if [ -f "$DASH_DIR/data.json" ]; then
    green "data.json already present — left untouched."
  else
    install -m 640 "$SRC/data.example.json" "$DASH_DIR/data.json"
    warn "data.json did not exist. The example has been copied into place."
    warn "Fill it in before the car sees this, then re-run --check:"
    warn ""
    warn "  nano '$DASH_DIR/data.json'"
    warn "  cd '$SRC' && ./deploy.sh --check"
    warn ""
    exit 0
  fi
}

if [ "${1:-}" = "--check" ]; then
  verify; exit $?
fi

if [ "${1:-}" = "--local" ]; then
  guard_domain
  install_local
  verify
  exit $?
fi

if [ -z "$SSH_HOST" ]; then
  red "SSH_HOST is not set. See the header of this script."
  exit 2
fi

guard_domain
info "Deploying to $SSH_HOST:$DASH_DIR"

# Remote paths are single-quoted on the far side so the remote shell does not
# expand the '$' and '!' in the secret path.
ssh "$SSH_HOST" "mkdir -p '$DASH_DIR/vendor/leaflet/images'"

scp -q "$SRC/index.html" "$SRC/app.css" "$SRC/app.js" \
       "$SRC/data.example.json" "$SRC/.htaccess" \
       "$SSH_HOST:'$DASH_DIR/'"
scp -q "$SRC/vendor/leaflet/leaflet.js" "$SRC/vendor/leaflet/leaflet.css" \
       "$SRC/vendor/leaflet/LICENSE" \
       "$SSH_HOST:'$DASH_DIR/vendor/leaflet/'"
scp -q "$SRC"/vendor/leaflet/images/* \
       "$SSH_HOST:'$DASH_DIR/vendor/leaflet/images/'"

green "Files copied."

if ssh "$SSH_HOST" "test -f '$DASH_DIR/data.json'"; then
  green "data.json already present — left untouched."
else
  ssh "$SSH_HOST" "cp '$DASH_DIR/data.example.json' '$DASH_DIR/data.json'"
  warn "data.json did not exist. The example has been copied into place."
  warn "Fill it in before the car sees this, then re-run --check:"
  warn "  ssh $SSH_HOST nano \\'$DASH_DIR/data.json\\'"
  exit 0
fi

verify
