# Chris Lacey's Dashboard — c.lacey.me

A private dashboard: where you are, what's on today, what needs buying, and the
health and money records behind it.

---

## Read this first

**The site this replaces had no sign-in.** Every page answered anonymous
requests with the full record. From an ordinary cloud container, with no
cookie, no password and no special access:

```
GET https://c.lacey.me/index.php        200 — the day's brief, 22 named people and their cities
GET https://c.lacey.me/person.php?id=13 200 — 42 KB, "Chris Lacey — Everything on file"
GET https://c.lacey.me/people.php       200
GET https://c.lacey.me/changelog.php    200 — 83 KB of change history
```

There was no `login.php` at all, and no `<form>`, `password`, or `session`
anywhere in the markup. So the brief for this rebuild — "the login structure is
perfectly fine, just let me reveal the password" — did not match what was
running: there was no login to keep.

That changes what "add a reveal toggle" means. This rebuild **builds** the
sign-in rather than modifying one, and the reveal toggle asked for is on it.
Two things follow that are worth doing regardless of what happens to this code:

1. **Treat everything that was on the old site as disclosed.** It was reachable
   by anyone who knew the hostname, for as long as it was up, and it was
   `noindex` but not access-controlled. Names, cities and personal fields for 22
   people were in it.
2. **Take the old pages down before pointing the domain at anything new.**
   Deploying this alongside them fixes nothing while `index.php` still answers
   anonymously.

`./deploy.sh --check` tests exactly this: it fails if any page returns 200 to an
anonymous request.

---

## What's here

| Path | What it is |
|---|---|
| `public/` | The docroot. The **only** directory that should be web-reachable. |
| `src/` | Application code. Must sit above the docroot. |
| `bin/` | Command-line setup: create the database, set the password, seed examples. |
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

```sh
cp config.php.example config.php && chmod 600 config.php
$EDITOR config.php                 # db path, timezone, location token
php bin/init-db.php                # create the database
php bin/set-password.php chris     # prompts twice, echo off, 12 chars minimum
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

## Security notes

This holds medical and financial records for you and for other people, so:

- **One account, password-hashed** (`password_hash`/`password_verify`), stored
  only in the database. `bin/set-password.php` reads it with echo off so it
  never lands in shell history.
- **Every data page fails closed.** `require_login()` runs before any output.
- **Failed logins are throttled per IP**, counted from the direct peer rather
  than `X-Forwarded-For`, which a caller can set freely.
- **Sessions** are HttpOnly, `SameSite=Lax`, Secure by default, regenerated on
  sign-in, and dropped after 30 minutes idle.
- **The database lives outside the web root**, and `deploy.sh --check` verifies
  it is not fetchable.
- **A strict Content-Security-Policy** with no inline script or style, no
  third-party script origin, and `frame-ancestors 'none'`.
- **`Cache-Control: no-store`** on every page, so records don't sit in a cache
  after sign-out.
- **The location token is separate from the password** so it can be rotated on
  its own, and an empty token disables that endpoint rather than leaving it open.
- **Fields marked `sensitive`** render masked and are revealed one at a time.
  This guards against someone reading your screen over your shoulder, and
  nothing more — the value is still in the delivered HTML, so it is not a
  substitute for not storing something in the first place.

Two things this deliberately does **not** do, which are worth deciding on
separately:

- **No second factor.** One password is the only thing in front of the whole
  record. The `account` table has a `totp_secret` column reserved for it.
- **The database is not encrypted at rest.** Anyone with the file, or with a
  host backup, has everything. Full-disk encryption on the host and an encrypted
  backup destination are the cheap version of fixing this.

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
