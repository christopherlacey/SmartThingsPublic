# Chris Lacey's Dashboard — c.lacey.me

A private dashboard: where you are, what's on today, what needs buying, and the
health and money records behind it.

---

## Read this first

**This rebuild exists because the version it replaces has no authentication.**
Its pages answer requests without a session, so the records behind them are
readable by anyone who reaches the host. That is the problem this branch is
here to fix, and it is not fixed by merging — only by deploying.

Specifics are deliberately kept out of this file. This repository is a fork of a
public project, so everything in it is world-readable, and a precise write-up of
a live weakness on a running site does not belong somewhere anyone can read it
before the site is fixed. If you need the detail, it is in the session that
produced this branch.

That also changed what the original brief meant. The request was "the login
structure is fine, just let me reveal the password" — but there was no login to
keep. So this **builds** the sign-in rather than modifying one, and the
password-reveal control that was asked for is on it.

Two things follow:

1. **Treat what was reachable as disclosed.** It was served to anyone who knew
   the hostname, for as long as it was up. That includes records about people
   other than you.
2. **Deploying is the fix, and the order matters.** The importer reads the old
   pages, so import before you overwrite them. Deploying *alongside* the old
   pages fixes nothing.

`./deploy.sh --check` is the test that this actually closed: it fails if any
page returns 200 to an anonymous request, if the config, database or `src/` is
fetchable, if HSTS is missing, or if the session cookie lacks HttpOnly, Secure
or SameSite. Run it straight after the deploy — a pass is the moment the
exposure ends.

---

## What's here

| Path | What it is |
|---|---|
| `public/` | The docroot. The **only** directory that should be web-reachable. |
| `src/` | Application code. Must sit above the docroot. |
| `bin/` | Command-line setup: `first-run.sh` for a one-pass install, plus the database, password, second factor, imports and backups. |
| `schema.sql` | The whole database. Re-runnable. |
| `config.php.example` | Copy to `config.php`, fill in, never commit. |
| `deploy.sh` | Deploy and verify, including the anonymous-access checks. |

### The tabs

- **Now** — the home tab. Where you are, whether you're standing in a shop with
  something on the list for it, today's schedule and brief, the headline numbers,
  and what's due.
- **Health** — medications, refills, readings over time, conditions and
  allergies, appointments. Per person, not just you.
- **Money** — net worth and its trend, account balances, where the money went,
  cash flow, bills due, recent transactions.
- **Errands** — the shopping list, grouped by where each thing is bought.
- **People** — everyone on file and where they are.
- **Change log** — every write to the ledger, newest first.

---

## Setting it up

The fast path, on the server, after `deploy.sh` has copied the code:

```sh
cd /var/www/ledger && ./bin/first-run.sh
```

It fills in the schema, the account, the second factor and the legacy import in
one pass, skips whatever is already done so it is safe to re-run, and refuses to
continue if the database path points inside the web root. It deliberately does
**not** touch the vhost — pointing the server at `ledger/public` is the step that
actually takes the old pages out of service, and that should be a decision you
make, not one a script makes for you.

The same steps by hand:

```sh
cp config.php.example config.php && chmod 600 config.php
$EDITOR config.php                 # db path, timezone, location token
php bin/init-db.php                # create the database
php bin/set-password.php chris     # prompts twice, echo off, 12 chars minimum
php bin/setup-totp.php             # second factor + recovery codes
```

`setup-totp.php` prints a secret to paste into your authenticator, then makes
you type a live code back before it saves anything — so a mistyped secret fails
at enrolment rather than the next time you try to sign in. It finishes by
printing ten single-use recovery codes. Write those down somewhere that is not
the phone holding the authenticator; they are the only way back in if that phone
is lost, and only their hashes are kept, so they cannot be shown again.

```sh
php bin/setup-totp.php --codes     # re-issue recovery codes
php bin/setup-totp.php --off       # back to password only
```

Then, to see it working before entering anything real:

```sh
php bin/seed-example.php           # everything it writes is prefixed EXAMPLE
cd public && php -S 127.0.0.1:8080
php bin/seed-example.php --wipe    # when you're done looking
```

`seed-example.php` refuses to run against a database that already has people in
it, so it can't land on top of real records.

### Deploying

```sh
SSH_HOST=chris@your-host \
WEB_DIR=/var/www/ledger/public \
APP_DIR=/var/www/ledger \
  ./deploy.sh

./deploy.sh --check                # verify without changing anything
```

The docroot must be `public/`, not the directory above it. `--check` confirms
that `config.php`, the database and `src/` are all unreachable over HTTP, and
that every data page turns an anonymous visitor away.

---

## Where the data comes from

Nothing here invents your records. The database ships empty and every tab reads
what's actually in it — an empty section says so plainly rather than showing a
placeholder that looks like a fact.

**The one live feed is location**, because the Now tab is worth little without
it. `POST /api/location.php` takes a position from whatever already runs on your
phone or in the house:

```sh
curl -X POST https://c.lacey.me/api/location.php \
     -H 'Authorization: Bearer <location_token from config.php>' \
     -H 'Content-Type: application/json' \
     -d '{"lat":32.7941,"lon":-79.8626,"accuracy_m":12,"transition":"arrive"}'
```

It accepts JSON or ordinary form fields, and understands `enter`/`exit` as well
as `arrive`/`depart`, so an iOS Shortcut, Owntracks, Home Assistant and a
SmartThings presence sensor can all post to it unchanged. A sender with no
coordinates can post `label=Home` instead and it will match a place by name.

The response tells the sender what's waiting there:

```json
{"ok":true,"place":"Harris Teeter","kind":"store","waiting":["Coffee beans (2 bags)"]}
```

which is enough for a Shortcut automation to raise a notification as you walk in.

**To make "am I in a store" work**, put real rows in `places` — a name, its
coordinates, a radius, and a `store_tag`. Items on the shopping list tagged with
that same `store_tag` are what the Now tab raises when a ping lands inside the
geofence. The example seed includes three places with placeholder coordinates in
roughly the right part of Mount Pleasant; they need replacing with real ones or
nothing will ever match.

Everything else — medications, balances, transactions, people — is entered or
imported. There is no bank or medical connection here, and adding one means
handing a third party credentials to accounts that matter, so it should be a
decision taken on its own rather than a side effect of a redesign.

---

## Bringing the old site across

```sh
php bin/import-legacy.php --from-site https://c.lacey.me --dry-run
php bin/import-legacy.php --from-site https://c.lacey.me
```

The dry run reports counts and writes nothing. Against the live site as it
stands, that is 23 people, 598 fields on them, and 160 change-log entries.

Everything is read before a single row is written, and the writes run in one
transaction — a half-imported ledger that looks complete is worse than no import
at all. Re-running against a database that already has people in it is refused
rather than silently duplicating them.

`--from-site` also accepts a **local directory**, which is the easier order of
operations if you would rather take the old site down first and import
afterwards:

```sh
wget -E -k -p https://c.lacey.me/people.php https://c.lacey.me/index.php \
     https://c.lacey.me/changelog.php 'https://c.lacey.me/person.php?id=1' ...
php bin/import-legacy.php --from-site ./saved-copy
```

Two things it cannot recover, because the old pages never rendered them:
per-field `source` values, and change-log timestamps (the log groups by day but
the imported rows are stamped at import time). Confidence tags — confirmed,
inferred, stale — are read from the overview page, which is the only page that
shows them.

**If you get a database dump instead**, that is a better source than the HTML.
Run:

```sh
php bin/import-legacy.php --inspect 'sqlite:/path/to/old.sqlite'
php bin/import-legacy.php --inspect 'mysql:host=localhost;dbname=old' --user u --pass p
```

It prints the legacy tables, their columns and row counts — structure only,
never row contents — next to the fields this schema wants, so the mapping can be
written against something real. That mapping is the one piece deliberately left
blank: guessing at column names for medical and financial records is how an
import quietly puts the wrong value in the wrong field.

## Medical records

```sh
php bin/import-health.php --file /root/records.json --dry-run
php bin/import-health.php --file /root/records.json
php bin/import-health.php --vitals-csv /root/weights.csv --metric weight --unit lb
```

Reads a file **already on this server**. It makes no network calls, so the
records never pass through anything else to get here. Medications, conditions
and allergies, appointments and numeric readings; the JSON shape is documented
at the top of the script, and HDA's own medication export is accepted as-is
(`dosage` reads as `dose`).

Everything is validated and counted before a single row is written, then written
in one transaction. A bad date or a missing name fails the whole import rather
than loading the good half — a medication list that is silently missing two
entries is more dangerous than one that refused to load.

### Where to put the records in the first place

This matters more than the import command, so it is worth being blunt about it.

**Do not** paste records into a chat window with an assistant — they end up in
that conversation's transcript. **Do not** put them in this repository: it is a
fork of a public project and is world-readable. **Do not** leave them in a
cloud sandbox or scratch directory, which is wiped without warning.

Two routes that are actually fine:

- **HDA** (`app.healthdataavatar.com`) — drag documents in, it extracts
  medications and the rest, and an assistant can read the structured result
  through the connector without you handing over the files. It also has a guided
  Subject Access Request flow for getting records out of a GP in the first place.
- **Straight to this server** — `scp` the file to a directory outside the web
  root, run the import above, then `shred -u` it. The script prints that command
  when it finishes.

The dashboard reads only its own database, which lives outside the docroot. It
has no connection to HDA or to any health provider, and adding one would mean
giving a web-facing PHP app standing credentials to a medical record system —
worth deciding on deliberately rather than as a side effect.

## Backups

```sh
php bin/backup.php --to /backups --age-recipient age1ql3z...
php bin/backup.php --to /backups --recipient chris@chrislacey.com   # gpg
```

Two things this does that copying the file does not:

- **`VACUUM INTO`**, so the copy is consistent even if a write lands mid-backup.
  A shell `cp` of a live SQLite file can capture a torn page and give you a
  backup that only fails on the day you need it.
- **Encrypts to a public key**, so the backup is safe to put somewhere you don't
  fully control — which is the point of having one. The private key never goes on
  the server, so a compromised host cannot read its own backups.

The recipient key is checked *before* the snapshot is taken, so a wrong key never
leaves a plaintext copy on disk; if encryption fails anyway, the plaintext
snapshot is deleted rather than left behind. `--keep N` prunes older backups
(default 30) — old copies of medical records are a liability, not an asset.

Nightly, via cron:

```
17 3 * * *  php /var/www/ledger/bin/backup.php --to /backups --age-recipient age1ql3z... >> /var/log/ledger-backup.log 2>&1
```

Restore is just a decrypt:

```sh
age -d -i ~/age.key -o ledger.sqlite /backups/ledger-2026-09-08-031701.sqlite.age
```

Verified end to end: encrypt, confirm the result is not readable as a database,
decrypt, `PRAGMA integrity_check` clean, all rows present.

## Encryption at rest

The backups above are encrypted. The **live database is not** — it can't be, in
a form the site can still read, sort and total. Handle that at the host layer:

- **Full-disk encryption on the server** (LUKS on Linux). This is the piece that
  covers a decommissioned disk, a seized machine, or a stolen laptop.
- **`config.php` and the database file are `0600`**, owned by the web user; the
  database sits outside the docroot and `deploy.sh --check` proves it isn't
  fetchable.
- **The backup destination should be a different machine or provider** than the
  one running the site.

Encrypting individual columns in the application was considered and rejected: an
encrypted column can't be searched, sorted or summed, so the charts and lookups
that make this dashboard useful would stop working — and the key would still be
sitting on the same box as the data, which is most of the threat model unchanged.

## What a review of this code found

The application was reviewed adversarially after it was written. Two real
problems came out of it, both now fixed, both worth knowing about because they
are the kind of thing that comes back:

- **`safe_next()` was an open redirect.** It rejected a `scheme:` prefix and a
  literal `//`, but browsers treat a backslash as a slash when parsing http(s)
  URLs, so `?next=/\evil.example` passed every check and redirected off-site
  right after a successful sign-in — from the real domain, with the real
  certificate, which is a good place to be asked for a password again. It is now
  an allow-list of this app's own page names. Blocklisting URL prefixes does not
  work; there is always another spelling.

- **The Health tab's person picker did nothing at all.** It used an inline
  `onchange`, which this app's own Content-Security-Policy forbids, and the
  fallback button was inside `<noscript>` so it never rendered either. Fixed
  with a real event listener in `ledger.js` and a button that is always in the
  markup and only hidden once the script runs.

The same review traced and cleared: SQL injection (every statement is bound,
`EMULATE_PREPARES` off), XSS including the stored data written by the location
API and the imported legacy records, the two-step sign-in for a bypass,
CSRF coverage, the `location.php` token check, `exec()` use in `backup.php`, and
XXE in the importer's HTML parsing.

Worth being straight about the limits: the same author wrote and reviewed this.
That is better than no review, and it is not the same as someone independent
reading it. If this ends up holding what it is designed to hold, it is worth an
outside pair of eyes.

## Security notes

This holds medical and financial records for you and for other people, so:

- **One account, password-hashed** (`password_hash`/`password_verify`), stored
  only in the database. `bin/set-password.php` reads it with echo off so it
  never lands in shell history.
- **Every data page fails closed.** `require_login()` runs before any output.
- **Failed logins are throttled per IP**, counted from the direct peer rather
  than `X-Forwarded-For`, which a caller can set freely.
- **Sessions** are HttpOnly, `SameSite=Lax`, Secure by default, regenerated on
  sign-in, and dropped after 30 minutes idle. `Strict-Transport-Security` is
  sent so the first request of a visit can't be answered over plain HTTP.
- **Each TOTP code is single-use.** The time step of the last accepted code is
  recorded and anything at or below it is refused, so a code read over your
  shoulder — or typed into a convincing copy of this sign-in page — is dead
  the moment it is used once.
- **The post-sign-in redirect is an allow-list**, not a filter: `?next=` has to
  name one of this app's own pages or it goes to the dashboard.
- **The database lives outside the web root**, and `deploy.sh --check` verifies
  it is not fetchable.
- **A strict Content-Security-Policy** with no inline script or style, no
  third-party script origin, and `frame-ancestors 'none'`. It is enforced, not
  decorative — it caught an inline handler in this codebase that was silently
  doing nothing, so treat a CSP console error as a real bug, not noise.
- **`Cache-Control: no-store`** on every page, so records don't sit in a cache
  after sign-out.
- **The location token is separate from the password** so it can be rotated on
  its own, and an empty token disables that endpoint rather than leaving it open.
- **Fields marked `sensitive`** render masked and are revealed one at a time.
  This guards against someone reading your screen over your shoulder, and
  nothing more — the value is still in the delivered HTML, so it is not a
  substitute for not storing something in the first place.

- **A second factor**, TOTP (RFC 6238), verified against the standard test
  vectors so it works with any authenticator app. The password alone never
  creates a session: it sets a pending state that expires in five minutes, and
  the code step is throttled on the same counter. Ten single-use recovery codes
  are stored as hashes.

What remains, and is worth deciding on separately:

- **The live database is not encrypted at rest** — see the section above for why
  that is a host-layer job, and what to do about it.
- **Recovery codes are only as good as where you put them.** They bypass the
  second factor by design. Somewhere physical, not the phone, not the same
  password manager.

---

## Branding

Type, colour, spacing and the footer come from `chrislacey.com`, so this reads
as the same property: Fraunces for headings, IBM Plex Sans for text, IBM Plex
Mono for anything numeric, the same light and dark token sets, the portrait
ring, and the footer carrying the headshot with the Emergency and Privacy links.

Chart colours are a validated extension of that palette rather than a free
choice. Slot 1 is the brand accent (`#0EA5E9`); the dark column is the same
hues re-stepped for the dark surface (slot 1 becomes `#149AD8`, because
`#38BDF8` sits above the dark lightness band). The set was checked for
colour-vision separation, chroma and contrast in both modes. Three of the
light-mode series sit under 3:1 against the surface, which is allowed only with
relief — so every chart ships direct labels and a "Show the numbers" table view,
and that table view is not optional decoration.

If you change a series colour, re-validate the set rather than eyeballing it,
and keep the slot order: the ordering is what makes adjacent series
distinguishable, not the individual hues.
