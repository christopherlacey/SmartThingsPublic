# c.lacey.me — The Lacey Ledger

Two new tabs (**Finance** and **Shopping**), a new shared theme that restyles
every tab, and a map component — as deployable source, following the same
pattern as `connect/`.

## Files

| File | What it is |
|---|---|
| `theme.css` | The whole design system. Restyles every tab, including the ones untouched here. |
| `header.php` | **Replaces** the existing one. Page chrome + the nav, now with Finance and Shopping. |
| `footer.php` | **Replaces** the existing one. Same footer markup, styled from `theme.css`. |
| `finance.php` | New tab. Transactions, per-day summaries, categories, merchants, trend. |
| `shopping.php` | New tab. The household list — add, tick off, restore, clear. |
| `charts.php` | Server-rendered SVG charts. No chart library, no JS, no external calls. |
| `map.php` | Drop-in US map component. Open it directly to see it self-test. |
| `ledger-db.php` | PDO handle for the two new tabs, plus CSRF helpers. |
| `ledger-config.php.example` | Copy to `ledger-config.php`, fill in, **never commit**. |
| `schema.sqlite.sql` / `schema.mysql.sql` | The two new tables. Use whichever matches your database. |
| `import-transactions.php` | CLI importer for bank/card CSV exports. |
| `ledger-auth.php` | **The gate.** Runs before every PHP request via `auto_prepend_file`. |
| `login.php` | Sign in with Google, then the authenticator code. |
| `oauth-callback.php` | Where Google sends the browser back. Checks every claim. |
| `logout.php` | Drops the session. |
| `ledger-totp.php` | RFC 6238 codes. Verified against the RFC's own test vectors. |
| `ledger-2fa-setup.php` | Enrol the second factor. |
| `ledger-auth-config.php.example` | Copy to `ledger-auth-config.php`, fill in, **never commit**. |
| `htaccess.example` / `user.ini.example` | Rendered to `.htaccess` / `.user.ini` at deploy time. |
| `test-auth.sh` | Runs the gate against a throwaway local site. 46 checks. |
| `deploy.sh` | Backs up, copies, verifies. |

## Read this before deploying

**`header.php` and `footer.php` are replaced with files written without sight of
the originals.** I could reach `c.lacey.me` over HTTP but not its source — it is
not in this repo and not in any repo this session could open — so the new chrome
was reconstructed from the rendered HTML of all five existing tabs.

That leads to one assumption the whole restyle rests on:

> Every tab gets its markup from a shared `header.php`, and prints no `<nav>`
> or `<style>` of its own.

The evidence is strong — all five tabs emit byte-identical CSS and nav, and
`header.php` exists on the server — but it is still an assumption. `deploy.sh`
checks it explicitly: after copying, it fetches `index.php` and fails loudly if
the Overview nav did not pick up the new tabs. If that check fails, the pages
print their own `<nav>` and each needs a one-line edit to include `header.php`
instead.

`deploy.sh` takes a timestamped backup of the current `header.php`, `footer.php`
and `theme.css` on every run and prints the one-line rollback.

The new header is deliberately forgiving about how a page talks to it, so no
existing tab should need editing:

- **Title** — uses `$PAGE_TITLE`, `$page_title` or `$title`, whichever is set.
- **Active tab** — worked out from the script filename, so pages don't declare it.

## Deploy

```sh
./deploy.sh --dry-run                    # what would change
SSH_HOST=chris@your-web-host \
LEDGER_DIR=/var/www/ledger \
  ./deploy.sh
./deploy.sh --check                      # verify the live site, change nothing
```

First run seeds `ledger-config.php` from the template and stops so you can fill
it in. Then load the schema once:

```sh
mysql ledger < schema.mysql.sql          # or:
sqlite3 /var/lib/ledger/ledger.sqlite < schema.sqlite.sql
```

If `db.php` already opens a PDO and leaves it in `$pdo`, `$db` or `$dbh`, set
`reuse_global_pdo => true` in the config instead of giving a second DSN.

## Finance

Reads the `transactions` table. Amounts are **signed, in cents** — negative is
money out — so nothing drifts through rounding.

The page shows money out and in for the window, net, a typical day, a column per
day, category and merchant rankings, a month-over-month trend, and every day
expanded into its own transaction table. The window switches between 30 days,
90 days and 12 months.

**It has no data source yet.** Nothing on the tab is illustrative: with an empty
table it says the table is empty rather than showing plausible-looking figures,
and it will keep doing that until real transactions are loaded. Getting them in
is the one piece still needing your input — see *Still needs your input* below.

To load a bank export:

```sh
php import-transactions.php statement.csv --dry-run
php import-transactions.php statement.csv --account="Joint checking"
```

The importer sniffs the header row, so most bank CSVs work untouched: it
recognises `date`/`posted`/`transaction date`, `description`/`payee`/`memo`,
`amount` or a `debit`+`credit` pair, plus optional merchant, category, account
and a source id. Amounts in the `(45.00)` parenthesised style are read as
negative. Re-running the same file adds nothing — rows with a source id match on
it, and rows without match on date + amount + description — so overlapping
exports top up instead of duplicating.

## Shopping

Owns its data outright, so it works as soon as the schema is loaded. Add an item
with an optional quantity, aisle and who asked for it; items group by aisle,
tick off into "In the cart", and clear in one go. Everything is a real form
post — the list works with JavaScript off.

Writes go through POST + a per-session CSRF token and finish with a 303
redirect, so refreshing never re-adds an item.

## Map

`map.php` plots people on a US map. The state outlines were projected once from
the US Census cartographic boundary files (public domain, via `us-atlas`) into
an Albers equal-area projection with the usual Alaska and Hawaii insets, and
baked into the file as plain SVG paths.

Nothing is fetched at render time — deliberately. A tile-server map would hand
the household's locations to a third party on every page view; this one never
leaves the box, needs no API key, and works offline. It is the same reasoning
`connect/` uses for never sending a visitor's IP to an enrichment service.

Open `map.php` in a browser to see it self-test. To put it on People or
Overview, one line above the list of cards:

```php
require_once __DIR__ . '/map.php';
echo ledger_map($people);
```

Each row can be an array or an object; it reads `name`/`who`, `place`/`location`,
optional `lat`+`lon`, `status` and `href`. Places resolve through a small
built-in gazetteer of the towns the Ledger actually refers to plus the larger US
metros. **A place it does not know is never guessed at** — it is listed under
the map as "not on the map", so nobody is ever plotted somewhere they aren't.
Add a row to `LEDGER_GAZETTEER` to place one.

Pins within about fifteen pixels merge into one numbered pin, so Mount Pleasant
and Charleston read as a single cluster of four rather than two dots stacked on
each other.

## Design

The palette, type and spacing are lifted from `chrislacey.com` so the private
Ledger and the public site read as one system: `#F5F8FA` ground, `#0EA5E9`
accent, Fraunces for display, IBM Plex Sans for text, IBM Plex Mono for
anything numeric. It is light-first with a real dark mode — the dark palette is
its own set of values, not an inverted light one — and follows both the system
setting and an explicit `data-theme` on the root.

A few things worth knowing:

- **Colour never carries meaning on its own.** A red/green spend-versus-income
  pair fails colour-vision separation in light mode (deuteranope ΔE 4.5, well
  under the 8 needed — measured, not guessed), so direction is carried by the
  sign, the position and the label, and the charts use a single hue.
- **Charts are server-rendered SVG.** No library, no JavaScript, no request
  leaving the server, and nothing to load before the page is readable.
- **No text lives inside a stretched SVG.** The plot fills the width by
  stretching, which is right for bars and wrong for letterforms, so every axis
  label is HTML beside the plot instead.
- Moving between tabs uses a cross-document view transition where the browser
  supports it, and a plain navigation where it doesn't.
- `prefers-reduced-motion` disables every transition, including the tab one.

## Still needs your input

- **Where transaction data comes from.** Nothing on the box holds it, and
  nothing in the connected accounts I could see does either — no Trello board,
  no Notion database. If it's a bank CSV, `import-transactions.php` handles it
  today. If it's Plaid, a bank feed or a spreadsheet, say which and the importer
  can grow a source for it.
- **Who "our" means on Shopping.** The list records who added each item as free
  text. If it should be a real per-person thing — separate lists, or who's
  shopping right now — that needs a decision before it's worth building.
- **Whether `index.php`/`people.php` should get the map**, and which of them.

## Sign-in

The whole site is private. Every page — Overview, People, Medical, Trip, Change
log, Finance, Shopping and the blog — turns away anyone who is not signed in.

**One identity gets in: `chris@chrislacey.com`.** The allow list lives in
`ledger-auth-config.php` and is checked on every request, not just at sign-in,
so removing an address takes effect immediately.

That list is deliberately hand-written rather than read from the people table.
That table is fed by public forms on `chrislacey.com` and `teeter.lacey.me`, so
deriving access from it would let a form submission influence who can get in.

### The two steps

1. **Google.** Authorization-code flow with PKCE. The callback checks the
   issuer, that the audience is this client, expiry, the nonce it minted for
   this browser, that the address is *verified*, that it is on the allow list,
   and that the `hd` claim matches the Workspace domain — so a personal Google
   account cannot get in on a lookalike address. Whatever 2-step verification
   Workspace enforces applies here, because Google is doing the authenticating.
2. **A code from your authenticator.** A second factor the Ledger owns itself,
   so access does not rest entirely on the Google session being sound. Set it
   up at `/ledger-2fa-setup.php` once signed in.

Until a TOTP secret is in the config, step 2 is skipped — that is the bootstrap
that lets you sign in with Google alone the first time and enrol.

There is no QR code on the enrolment page on purpose: drawing one would mean
either shipping a QR encoder or sending the secret to an image service, and the
second of those hands your second factor to a stranger. Google Authenticator
takes a typed setup key.

### How it covers pages whose source isn't here

`ledger-auth.php` is loaded through PHP's `auto_prepend_file`, so it runs before
every PHP request under the docroot — including `index.php`, `people.php` and
the blog, none of whose source is in this repo. The gate does not depend on each
page remembering to ask for it.

`deploy.sh` renders both wirings, since which one applies depends on how PHP is
run: `.htaccess` (`php_value`, mod_php) and `.user.ini` (PHP-FPM or CGI). Having
both is harmless.

Two consequences worth knowing:

- **`auto_prepend_file` governs PHP only.** Static files are served straight off
  disk, which is why `.htaccess` also denies `*.sql`, `*.example`, `*.md`, the
  config files and the `deploy.sh` backups outright. `theme.css` stays readable
  on purpose — the sign-in page needs it and it holds nothing.
- **PHP caches `.user.ini`** for `user_ini.cache_ttl` seconds (300 by default),
  so a change there can take five minutes to take effect.

### What it fails to

Closed, at every turn. A missing `ledger-auth-config.php` returns 503 rather
than defaulting to open. A failed OAuth callback burns the `state` so it cannot
be retried. Five bad codes lock the second step for fifteen minutes. A code that
has been used once is refused for the rest of its 30-second window.

### Sessions

Cookies are `Secure`, `HttpOnly`, `SameSite=Lax` (Lax, not Strict — the OAuth
return is a cross-site redirect). The session id is regenerated at each step, so
nothing that existed before sign-in carries weight. Signed out after 12 hours
idle or 30 days absolute, whichever comes first.

The default session name is kept rather than changed, so anything else already
using PHP's session on this host keeps working; the gate closes the session
before handing control to the page, so a page calling `session_start()` itself
behaves exactly as it did before.

### Setting up the Google client

Google Cloud console → APIs & Services → Credentials → **Create OAuth client
ID** → Web application. Add `https://c.lacey.me/oauth-callback.php` as an
authorised redirect URI, then put the client ID and secret in
`ledger-auth-config.php`. On the OAuth consent screen, **Internal** is the right
user type for a Workspace domain — it means no one outside the domain can even
begin the flow.

### The blog's own login

`/blog/admin.php` has its own separate sign-in, written before this existed. Now
that the gate covers `/blog/` too, that second login is redundant — you will be
asked to sign in twice. Its source is not in this repo, so retiring it is a
manual step: once you are happy the gate is working, remove its login check, and
retire whatever credential it holds. Until then nothing is *less* safe; it is
just two doors instead of one.

### What is tested

```sh
./test-auth.sh        # 46 checks, no setup, nothing left behind
```

It stands up PHP's built-in server with `ledger-auth.php` wired through
`auto_prepend_file` exactly as Apache does, then checks the properties that
matter: every page redirecting while signed out, no page body leaking, a
disallowed address refused, a wrong-case address accepted, idle and absolute
timeouts, the second factor withheld until a code is entered, a replayed code
refused, CSRF rejected, forged OAuth `state` refused, and 503 rather than open
access when the config is missing.

`ledger-totp.php` is separately verified against all six RFC 6238 test vectors
plus replay refusal and clock drift either side.

The Google round trip itself needs real credentials, so that is the one part
only your first real sign-in can confirm.

### Signing out

Sign-out is a POST with a token, never a link, so no other site can sign you
out by pointing an image or a redirect at the URL. Visiting `/logout.php`
directly asks rather than acting.

A failed sign-in does not end a session that already exists, either — the OAuth
callback drops only its own handshake keys. Otherwise
`/oauth-callback.php?error=x` would be a one-click sign-out for anyone who could
get your browser to follow a link.

### The lockout

Five wrong codes inside fifteen minutes locks the second step, and attempts made
while locked keep the window alive rather than buying five fresh tries every
quarter of an hour. It is a sliding window of failure timestamps, not a counter
with a reset.

Worth being clear about what this does and doesn't defend: the code form is only
reachable once Google has already signed someone in as an allowed address, so
brute-forcing it means already holding the Workspace account. That is also why
attempts while locked can safely extend the lock — a stranger cannot reach this
form at all, so it cannot be used to lock you out of your own dashboard.

### Five bugs found in review, all fixed and all pinned by tests

**The gate matched public endpoints on file name.** Any file called `login.php`
anywhere under the docroot was served without signing in — and blogs very often
have one. `/blog/login.php` is a 404 today so nothing was exposed, but it was a
hole waiting for a file to be added. The gate now matches the resolved absolute
path, and the suite plants a decoy `blog/login.php` on every run.

**The second factor failed open.** The replay counter and the lockout both live
in `state_dir`, and the code treated an unwritable directory as "no counter
yet": every code in the ±1 window stayed valid, repeatedly, and the five-attempt
lockout never engaged. Nothing surfaced it — sign-in still worked perfectly.
`deploy.sh` made it likely, too: it tried to `chown` the state directory with
`|| true` on every step, so a non-root deploy left it unwritable by the web
server and said nothing. Now the store throws instead of returning quietly, a
code that cannot be spent is refused rather than accepted, and the deploy fails
loudly with the exact `chown` to run (`STATE_OWNER=www-data ./deploy.sh` names
the user outright).

**The whole gate rested on one overridable setting.** `auto_prepend_file` is
per-directory, so a `.htaccess` or `.user.ini` deeper in the tree — the sort of
thing a blog plugin writes by itself — would silently unhook it for that
subtree, and nothing in the pages re-checked. `header.php` now requires the gate
itself, so any page using it enforces sign-in on its own account; under the
prepend that `require_once` is a no-op. Both config templates now recommend
`php_admin_value` in the vhost or FPM pool, which no directory can override —
worth doing, since pages whose source is not in this repo have no such backstop.

**Sign-out was a plain link, and a failed callback ended a live session.** Either
let any other site log you out. Both are closed above.

**Constants sat below the CLI early-return.** PHP hoists function declarations
but evaluates `const` in order, so on the CLI side `ledger-auth.php` had all its
functions and none of its constants — a half-loaded state that would fatal the
moment anything on the command line touched the rate limiter. The constants now
sit above the return.

