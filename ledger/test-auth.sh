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

# The replay counter and the lockout both live in state_dir. If it cannot be
# written neither control is in force, so a code must be REFUSED rather than
# waved through. This is what a deploy that leaves /var/lib/ledger owned by the
# wrong user produces. Run against a second server configured that way from the
# start — permission bits alone would not do it, since these tests may run as
# root, which ignores them.
BAD="$TMP/bad"; mkdir -p "$BAD/sess"
cp "$TMP"/*.php "$TMP"/*.css "$TMP"/*.sql "$BAD/" 2>/dev/null
: > "$BAD/not-a-dir"
sed "s|'state_dir' => .*|'state_dir' => '$BAD/not-a-dir',|" "$TMP/ledger-auth-config.php" > "$BAD/ledger-auth-config.php"
sed "s|$TMP/t.sqlite|$TMP/t.sqlite|" "$TMP/ledger-config.php" > "$BAD/ledger-config.php"
echo '<?php echo "PRIVATE-PAGE";' > "$BAD/index.php"

php -S "127.0.0.1:$((PORT+2))" -t "$BAD" \
    -d auto_prepend_file="$BAD/ledger-auth.php" \
    -d session.save_path="$BAD/sess" >"$TMP/srv3.log" 2>&1 &
SRV3=$!
sleep 2
BB="http://127.0.0.1:$((PORT+2))"

SIDB=$(php -r 'echo bin2hex(random_bytes(16));')
php -r '
  [$dir,$sid] = array_slice($argv,1);
  $d  = "ledger_email|".serialize("chris@chrislacey.com");
  $d .= "ledger_login_at|".serialize(time()-60);
  $d .= "ledger_seen_at|".serialize(time()-60);
  $d .= "ledger_2fa_ok|".serialize(false);
  file_put_contents("$dir/sess_$sid", $d);
' "$BAD/sess" "$SIDB"

CSRFB=$(curl -sS -b "PHPSESSID=$SIDB" "$BB/login.php" | grep -oE 'value="[a-f0-9]{64}"' | head -1 | grep -oE '[a-f0-9]{64}')
CODEB=$(php -r "require '$SRC/ledger-totp.php'; echo totp_code_at('$SECRET', totp_counter());")
scb=$(curl -sS -o "$TMP/bodyb" -w '%{http_code}' -b "PHPSESSID=$SIDB" -X POST \
      -d "csrf=$CSRFB" -d "action=totp" -d "code=$CODEB" "$BB/login.php")
kill "$SRV3" 2>/dev/null
if grep -q 'be checked safely right now' "$TMP/bodyb"; then
  green "an unusable state dir refuses the code"
else
  red "an unusable state dir did NOT refuse the code (http $scb) — replay protection would be off"
  sed -e 's/<[^>]*>//g' "$TMP/bodyb" | grep -viE '^[[:space:]]*$' | head -3 | sed 's/^/        /'
fi

echo
echo "Gate does not rest on auto_prepend_file alone"
# A .htaccess or .user.ini deeper in the tree can unhook the prepend. Pages
# that use header.php require the gate themselves, so they must still refuse.
# Tested with a second server started WITHOUT auto_prepend_file.
cat > "$TMP/selftest.php" <<'PG'
<?php $PAGE_TITLE = 'Self test'; require __DIR__ . '/header.php';
echo "PRIVATE-VIA-HEADER"; require __DIR__ . '/footer.php';
PG
php -S "127.0.0.1:$((PORT+1))" -t "$TMP" -d session.save_path="$TMP/sess" >"$TMP/srv2.log" 2>&1 &
SRV2=$!
sleep 2
U="http://127.0.0.1:$((PORT+1))"
c2=$(curl -sS -o "$TMP/body2" -w '%{http_code}' --max-redirs 0 "$U/selftest.php")
if grep -q 'PRIVATE-VIA-HEADER' "$TMP/body2"; then
  red "header.php rendered with the prepend unhooked (got $c2)"
else
  green "header.php enforces the gate on its own (got $c2)"
fi

# The backstop has to cover the work a page does BEFORE it includes the header,
# which for Shopping is the write handler and for Finance the queries.
for page in shopping.php finance.php; do
  cp=$(curl -sS -o "$TMP/body3" -w '%{http_code}' --max-redirs 0 "$U/$page")
  if [ "$cp" = "302" ]; then
    green "$page refuses before doing any work (prepend unhooked)"
  else
    red "$page answered $cp with the prepend unhooked"
  fi
done
# A write must not be attempted either.
cw=$(curl -sS -o /dev/null -w '%{http_code}' --max-redirs 0 -X POST \
     -d "action=add" -d "item=SHOULD-NOT-LAND" "$U/shopping.php")
if [ "$cw" = "302" ]; then green "a write posted with the prepend unhooked is refused"
else red "shopping.php answered $cw to an unauthenticated write"; fi
kill "$SRV2" 2>/dev/null

echo
echo "An unreadable state file fails closed"
# Present-but-unreadable must not read as "no failures yet", which would
# disarm the replay counter for an attempt.
UR="$TMP/unreadable"; mkdir -p "$UR"
cp "$SRC/ledger-auth.php" "$SRC/ledger-totp.php" "$UR/"
cat > "$UR/probe.php" <<'UNR'
<?php
$dir = __DIR__ . '/st'; @mkdir($dir, 0700, true);
file_put_contents(__DIR__ . '/ledger-auth-config.php',
    "<?php return ['state_dir'=>'$dir','allowed_emails'=>['a@b.c'],'google_hd'=>'',"
  . "'google_client_id'=>'x','google_client_secret'=>'y','redirect_uri'=>'z',"
  . "'totp_enabled'=>false,'totp_secret'=>'','idle_timeout'=>1,'absolute_timeout'=>1];");
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/probe.php';
require __DIR__ . '/ledger-auth.php';
// A readable store is usable.
ledger_state_write('totp.json', ['last_counter' => 1]);
$before = ledger_state_usable() ? 'usable' : 'refused';
// Make it unreadable. Running as root defeats chmod, so remove read access by
// replacing the file with a directory of the same name — unreadable as a file
// for any uid.
unlink("$dir/totp.json"); mkdir("$dir/totp.json");
$after = ledger_state_usable() ? 'usable' : 'refused';
echo "$before/$after";
UNR
res=$(cd "$UR" && php probe.php 2>/dev/null)
ok "usable when readable, refused when not" "$res" "usable/refused"

echo
echo "Signing out is a POST"
SIDO=$(mksess chris@chrislacey.com 60 60 yes)
ok "a GET only asks"        "$(code -H "Cookie: PHPSESSID=$SIDO" "$B/logout.php")" "200"
ok "still signed in after it" "$(code -H "Cookie: PHPSESSID=$SIDO" "$B/index.php")" "200"
ok "a POST with no token"   "$(curl -sS -o /dev/null -w '%{http_code}' -b "PHPSESSID=$SIDO" -X POST -d "csrf=nope" "$B/logout.php")" "400"
ok "still signed in after that" "$(code -H "Cookie: PHPSESSID=$SIDO" "$B/index.php")" "200"
CSRFO=$(curl -sS -b "PHPSESSID=$SIDO" "$B/logout.php" | grep -oE 'value="[a-f0-9]{64}"' | head -1 | grep -oE '[a-f0-9]{64}')
ok "a POST with the token"  "$(curl -sS -o /dev/null -w '%{http_code}' -b "PHPSESSID=$SIDO" -X POST -d "csrf=$CSRFO" "$B/logout.php")" "302"
ok "and now signed out"     "$(code -H "Cookie: PHPSESSID=$SIDO" "$B/index.php")" "302"

echo
echo "A failed callback does not end a live session"
# Any site can send a browser to /oauth-callback.php?error=x. That must not be
# a way to sign someone out.
SIDC=$(mksess chris@chrislacey.com 60 60 yes)
curl -sS -o /dev/null -b "PHPSESSID=$SIDC" --max-redirs 0 "$B/oauth-callback.php?error=access_denied"
ok "still signed in afterwards" "$(code -H "Cookie: PHPSESSID=$SIDC" "$B/index.php")" "200"
curl -sS -o /dev/null -b "PHPSESSID=$SIDC" --max-redirs 0 "$B/oauth-callback.php?code=x&state=forged"
ok "and after a forged state too" "$(code -H "Cookie: PHPSESSID=$SIDC" "$B/index.php")" "200"

echo
echo "Lockout window slides"
LOCKDIR="$TMP/lockdir"; mkdir -p "$LOCKDIR"
cp "$SRC/ledger-auth.php" "$SRC/ledger-totp.php" "$LOCKDIR/"
cat > "$LOCKDIR/lockout.php" <<'LOCK'
<?php
$dir = __DIR__ . '/lockstate';
@mkdir($dir, 0700, true);
file_put_contents(__DIR__ . '/ledger-auth-config.php',
    "<?php return ['state_dir'=>'$dir','allowed_emails'=>['a@b.c'],'google_hd'=>'',"
  . "'google_client_id'=>'x','google_client_secret'=>'y','redirect_uri'=>'z',"
  . "'totp_enabled'=>false,'totp_secret'=>'','idle_timeout'=>1,'absolute_timeout'=>1];");
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . '/probe.php';
require __DIR__ . '/ledger-auth.php';
$bad = 0;
$chk = function (string $what, bool $okv) use (&$bad) {
    echo $okv ? "  PASS  $what\n" : "  FAIL  $what\n"; if (!$okv) $bad++;
};
$seed = fn(array $t) => ledger_state_write('login-attempts.json', ['failures' => $t]);
$now = time();
ledger_attempts_cleared();          $chk('a clean slate is not locked', ledger_locked_for() === 0);
$seed([$now-5,$now-4,$now-3,$now-2,$now-1]); $chk('five recent failures lock it', ledger_locked_for() > 0);
$seed([$now-5,$now-4,$now-3,$now-2]);        $chk('four do not', ledger_locked_for() === 0);
$seed([$now-899,$now-800,$now-700,$now-600,$now-500]); $chk('locked while all five are in the window', ledger_locked_for() > 0);
$seed([$now-901,$now-800,$now-700,$now-600,$now-500]); $chk('released once the oldest ages out', ledger_locked_for() === 0);
$t = [];
for ($i = 0; $i < 5; $i++) { $t[] = $now - 890 + $i; }
for ($k = 800; $k >= 100; $k -= 100) { $t[] = $now - $k; }
$seed($t);                          $chk('hammering keeps it locked', ledger_locked_for() > 0);
ledger_attempts_cleared();          $chk('a correct code clears it', ledger_locked_for() === 0);
exit($bad ? 1 : 0);
LOCK
lockout_out=$(cd "$LOCKDIR" && php lockout.php 2>&1)
echo "$lockout_out" | sed 's/^/  /'
if grep -q FAIL <<<"$lockout_out"; then
  FAIL=$((FAIL+1))
else
  PASS=$((PASS+$(grep -c PASS <<<"$lockout_out")))
fi
echo
echo "The command line is not gated"
# The gate returns early for CLI so cron and the importer keep working. The
# constants have to sit above that return, or the file loads with all of its
# functions and none of its constants.
cat > "$TMP/cliprobe.php" <<'CLI'
<?php
$ok = function_exists('ledger_state_dir')
   && defined('LEDGER_LOCKOUT') && defined('LEDGER_MAX_ATTEMPTS')
   && defined('LEDGER_PUBLIC_FILES');
echo $ok ? "CLI-OK" : "CLI-INCOMPLETE";
CLI
cliout=$(php -d auto_prepend_file="$TMP/ledger-auth.php" "$TMP/cliprobe.php" 2>&1)
ok "loads whole on the CLI" "$cliout" "CLI-OK"
impout=$(cd "$TMP" && php -d auto_prepend_file="$TMP/ledger-auth.php" import-transactions.php /dev/null 2>&1 | head -1)
case "$impout" in
  *Empty*|*Usage*|*Imported*|*Dry*) green "the importer still runs" ;;
  *) red "the importer was blocked or broke: $impout" ;;
esac

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
