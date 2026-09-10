#!/usr/bin/env bash
#
# test-auth.sh — exercise the sign-in gate against a throwaway local site.
#
#   ./test-auth.sh
#
# Stands up PHP's built-in server with ledger-auth.php wired in through
# auto_prepend_file, exactly as Apache does in production, then checks the
# properties that actually matter: that no page answers a signed-out visitor,
# that a disallowed address is refused, that the second factor cannot be
# skipped or replayed, and that a file named login.php in a subdirectory does
# not become a hole in the gate.
#
# Needs php with pdo_sqlite. Touches nothing outside its own temp directory.

set -uo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP="$(mktemp -d)"
PORT="${PORT:-8899}"
B="http://127.0.0.1:$PORT"
PASS=0; FAIL=0

green(){ printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS+1)); }
red(){   printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL+1)); }
ok(){ [ "$2" = "$3" ] && green "$1" || red "$1 (got '$2', want '$3')"; }

cleanup(){ [ -n "${SRV:-}" ] && kill "$SRV" 2>/dev/null; rm -rf "$TMP"; }
trap cleanup EXIT

# ---- build a throwaway copy of the site -----------------------------------

cp "$SRC"/*.php "$SRC"/*.css "$SRC"/*.sql "$TMP/" 2>/dev/null
rm -f "$TMP/ledger-auth-config.php" "$TMP/ledger-config.php"
mkdir -p "$TMP/sess" "$TMP/state" "$TMP/blog"

SECRET="$(php -r "require '$SRC/ledger-totp.php'; echo totp_new_secret();")"

cat > "$TMP/ledger-auth-config.php" <<CFG
<?php
return [
  'allowed_emails' => ['chris@chrislacey.com'],
  'google_hd' => 'chrislacey.com',
  'google_client_id' => 'test.apps.googleusercontent.com',
  'google_client_secret' => 'testsecret',
  'redirect_uri' => 'https://c.lacey.me/oauth-callback.php',
  'totp_enabled' => true,
  'totp_secret'  => '$SECRET',
  'idle_timeout' => 43200,
  'absolute_timeout' => 2592000,
  'state_dir' => '$TMP/state',
];
CFG

cat > "$TMP/ledger-config.php" <<CFG
<?php
return ['reuse_global_pdo'=>false,'dsn'=>'sqlite:$TMP/t.sqlite','user'=>null,'pass'=>null,'currency'=>'USD'];
CFG
php -r "\$p=new PDO('sqlite:$TMP/t.sqlite'); \$p->exec(file_get_contents('$TMP/schema.sqlite.sql'));"

# Stand-ins for the pages whose source is not in this repo.
echo '<?php session_start(); echo "PRIVATE-PAGE";' > "$TMP/index.php"
echo '<?php echo "PRIVATE-BLOG";'                  > "$TMP/blog/index.php"
# A subdirectory file sharing a name with a public endpoint — the gate must
# still cover it.
echo '<?php echo "PRIVATE-DECOY";'                 > "$TMP/blog/login.php"

php -S "127.0.0.1:$PORT" -t "$TMP" \
    -d auto_prepend_file="$TMP/ledger-auth.php" \
    -d session.save_path="$TMP/sess" >"$TMP/srv.log" 2>&1 &
SRV=$!
sleep 2

code(){ curl -sS -o "$TMP/body" -w '%{http_code}' --max-redirs 0 "$@"; }
mksess(){ # email loginAgo seenAgo 2fa -> sid
  local sid; sid=$(php -r 'echo bin2hex(random_bytes(16));')
  php -r '
    [$dir,$sid,$e,$la,$sa,$f] = array_slice($argv,1);
    $d  = "ledger_email|".serialize($e);
    $d .= "ledger_login_at|".serialize(time()-(int)$la);
    $d .= "ledger_seen_at|".serialize(time()-(int)$sa);
    if ($f !== "none") $d .= "ledger_2fa_ok|".serialize($f === "yes");
    file_put_contents("$dir/sess_$sid", $d);
  ' "$TMP/sess" "$sid" "$1" "$2" "$3" "$4"
  echo "$sid"
}

echo "Signed out"
for p in index.php finance.php shopping.php blog/index.php blog/login.php ledger-2fa-setup.php; do
  ok "$p is gated" "$(code "$B/$p")" "302"
done
code "$B/index.php" >/dev/null; grep -q 'PRIVATE-PAGE' "$TMP/body" && red "page body leaked" || green "no page body leaks"
code "$B/blog/index.php" >/dev/null; grep -q 'PRIVATE-BLOG' "$TMP/body" && red "blog body leaked" || green "blog stays private"
code "$B/blog/login.php" >/dev/null; grep -q 'PRIVATE-DECOY' "$TMP/body" && red "subdirectory login.php bypassed the gate" || green "a decoy login.php does not open the gate"

echo
echo "Sign-in endpoints"
ok "login.php reachable" "$(code "$B/login.php")" "200"
grep -q 'Continue with Google' "$TMP/body" && green "offers Google" || red "no Google button"
ok "theme.css served"    "$(code "$B/theme.css")" "200"

echo
echo "Session states"
ok "allowed address gets in"        "$(code -H "Cookie: PHPSESSID=$(mksess chris@chrislacey.com 60 60 yes)" "$B/index.php")" "200"
ok "wrong case still gets in"       "$(code -H "Cookie: PHPSESSID=$(mksess Chris@ChrisLacey.com 60 60 yes)" "$B/index.php")" "200"
ok "another address refused"        "$(code -H "Cookie: PHPSESSID=$(mksess someone@else.com 60 60 yes)"     "$B/index.php")" "302"
ok "near-miss address refused"      "$(code -H "Cookie: PHPSESSID=$(mksess chris@chrislacey.co 60 60 yes)"  "$B/index.php")" "302"
ok "idle past the timeout"          "$(code -H "Cookie: PHPSESSID=$(mksess chris@chrislacey.com 3600 50000 yes)" "$B/index.php")" "302"
ok "past the absolute lifetime"     "$(code -H "Cookie: PHPSESSID=$(mksess chris@chrislacey.com 2700000 60 yes)" "$B/index.php")" "302"
ok "second factor not yet given"    "$(code -H "Cookie: PHPSESSID=$(mksess chris@chrislacey.com 60 60 no)"  "$B/index.php")" "302"

echo
echo "Second factor"
SID=$(mksess chris@chrislacey.com 60 60 no)
CSRF=$(curl -sS -b "PHPSESSID=$SID" "$B/login.php" | grep -oE 'value="[a-f0-9]{64}"' | head -1 | grep -oE '[a-f0-9]{64}')
GOOD=$(php -r "require '$SRC/ledger-totp.php'; echo totp_code_at('$SECRET', totp_counter());")
ok "wrong code refused"  "$(curl -sS -o /dev/null -w '%{http_code}' -b "PHPSESSID=$SID" -X POST -d "csrf=$CSRF" -d "action=totp" -d "code=000000" "$B/login.php")" "200"
ok "bad CSRF refused"    "$(curl -sS -o /dev/null -w '%{http_code}' -b "PHPSESSID=$SID" -X POST -d "csrf=nope"  -d "action=totp" -d "code=$GOOD"  "$B/login.php")" "400"
ok "correct code accepted" "$(curl -sS -o /dev/null -w '%{http_code}' -b "PHPSESSID=$SID" -X POST -d "csrf=$CSRF" -d "action=totp" -d "code=$GOOD" "$B/login.php")" "302"
SID2=$(mksess chris@chrislacey.com 60 60 no)
CSRF2=$(curl -sS -b "PHPSESSID=$SID2" "$B/login.php" | grep -oE 'value="[a-f0-9]{64}"' | head -1 | grep -oE '[a-f0-9]{64}')
ok "the same code again refused" "$(curl -sS -o /dev/null -w '%{http_code}' -b "PHPSESSID=$SID2" -X POST -d "csrf=$CSRF2" -d "action=totp" -d "code=$GOOD" "$B/login.php")" "200"

echo
echo "OAuth callback"
red_loc(){ curl -sS -o /dev/null -D- --max-redirs 0 "$@" | grep -i '^location:' | tr -d '\r' | awk '{print $2}'; }
ok "no parameters"     "$(red_loc "$B/oauth-callback.php")"                       "/login.php?error=state"
ok "consent declined"  "$(red_loc "$B/oauth-callback.php?error=access_denied")"   "/login.php?error=denied"
ok "forged state"      "$(red_loc "$B/oauth-callback.php?code=a&state=forged")"   "/login.php?error=state"

echo
echo "Fails closed"
mv "$TMP/ledger-auth-config.php" "$TMP/held.php"
ok "no config means no entry" "$(code "$B/index.php")" "503"
grep -q 'PRIVATE-PAGE' "$TMP/body" && red "served a page with no config" || green "serves nothing with no config"
mv "$TMP/held.php" "$TMP/ledger-auth-config.php"

echo
echo "No PHP errors anywhere"
grep -iE 'Fatal error|Parse error|Uncaught|Deprecated' "$TMP/srv.log" | head -3
grep -qiE 'Fatal error|Parse error|Uncaught|Deprecated' "$TMP/srv.log" && red "PHP errors in the log" || green "server log is clean"

echo
if [ "$FAIL" -eq 0 ]; then
  printf '\033[32m%s\033[0m\n' "All $PASS checks passed."
else
  printf '\033[31m%s\033[0m\n' "$FAIL of $((PASS+FAIL)) checks failed."
fi
exit "$FAIL"
