# Tesla Model X Dashboard

A single-screen dashboard for the Model X centre display: where Chris, John and
Addy each are, what each of them has on today, and their medical information.

Target URL: `https://tesla.lacey.me/TmX$23!/` — an unlisted path, which is the
only thing standing between this page and the open internet.

`grok.lacey.me` is a redirect onto that URL, configured separately at the
DNS/vhost level. Nothing in this directory sets it up or depends on it, and
`deploy.sh` never touches it.

## Files

| File | What it is |
|---|---|
| `index.html` | The page. |
| `app.css` | Styling, sized for the 17" car screen. |
| `app.js` | Behaviour: fetch, render, refresh, map. |
| `data.example.json` | The data schema, with placeholder values. Copy to `data.json`. |
| `vendor/leaflet/` | Leaflet 1.9.4, self-hosted (BSD-2-Clause, see its `LICENSE`). |
| `.htaccess` | Stops the path leaking: no directory listing, no caching, no indexing. |
| `deploy.sh` | Copies the site to the web host, then verifies it. |

`data.json` holds the real information and is **gitignored**. Keep it that way:
this repository is a public fork on GitHub, so anything committed here is
published whether or not the website is.

## How it works

The page is static. Everything on screen comes from `data.json`, which the page
re-fetches every `meta.refreshSeconds` (default 120s). Nothing needs a redeploy
to change what the car shows — rewrite `data.json` on the server and the
dashboard picks it up on its next poll.

That makes the ingest side deliberately open-ended: a cron job, an iOS Shortcut
POSTing a position, or a script against the Google Calendar and Life360 APIs can
all just write this one file. None of that is built yet — see "Wiring up real
data" below.

## Layout

- **Rail** (always visible): one card per person — current location, how old the
  fix is, what they're doing now, what's next. Tap a card to narrow the panel to
  that person; tap again, or "Show everyone", to go back.
- **Map**: exact positions, colour-matched to the rail, with the coordinates
  listed in the legend.
- **Today**: each person's schedule, with the current and next items marked and
  finished items dimmed.
- **Medical**: blood type, allergies, conditions, medications, devices, upcoming
  care, care team, pharmacy and emergency contacts. Every phone number is a
  full-size tap-to-dial target, which is the main reason to have this in a car.

## Designed for the car, specifically

- Sized for 1920×1200 (MCU2) through 2200×1300 (MCU3); one `clamp()` on `html`
  scales the whole thing.
- No hover anywhere — it's a touch screen. Touch targets are ~64px.
- The page itself never scrolls; only the inner panels do.
- No optional chaining or other post-2019 syntax, because MCU2 runs an older
  Chromium.
- Leaflet is self-hosted, so the map doesn't depend on a CDN being reachable
  from the car.
- Failure is designed for: if `data.json` can't be fetched, the last good render
  stays on screen and the sync indicator goes amber, then red. If Leaflet or the
  map tiles can't load, the map panel falls back to a coordinate readout with an
  "Open in Maps" link rather than going blank.

The one remaining external dependency is the basemap tiles
(`basemaps.cartocdn.com`) and the Google Fonts stylesheet. Both degrade: no
tiles means pins on a dark background, no fonts means the system stack.

## Setting up the data

```sh
cp data.example.json data.json
$EDITOR data.json
```

Every value in the example is `REPLACE`. The shape:

- `meta.refreshSeconds` — how often the car re-reads the file.
- `meta.staleMinutes` — a location fix older than this turns amber on the rail.
- `people[]` — `id`, `name`, `initials`, `color` (used for the rail, the map pin
  and the accents), plus:
  - `location` — `lat`, `lon`, `place`, `detail`, `status` (`stationary` or
    `moving`), `speedMph`, and `updated` as an ISO 8601 timestamp.
  - `schedule[]` — `start`/`end` as `"HH:MM"` 24-hour local, `title`, `where`,
    optional `note`, and `kind` (`work`, `school`, `medical`, `personal`).
    Omit `start` for an all-day item. `kind: "medical"` is highlighted.
  - `medical` — `bloodType`, `organDonor`, `allergies[]` (an entry with
    `severity: "severe"` or `"anaphylaxis"` raises the red flag on the rail
    card), `conditions[]`, `medications[]`, `devices[]`, `appointments[]`,
    `providers[]`, `pharmacy`, `emergency[]`, `notes[]`, and `alert` for a
    custom red flag.

## Wiring up real data

Not built. The dashboard reads a file, so any of these would work without
touching the front end:

- **Schedule** — a cron job against the Google Calendar API writing today's
  events into each person's `schedule[]`.
- **Location** — whatever you actually use. An iOS Shortcut automation POSTing
  to a small PHP endpoint is the least moving parts; Life360 and Home Assistant
  both have APIs; the Tesla API can supply the car's own position.

Whatever writes the file should write to a temp file and `mv` it into place, so
the car never fetches a half-written JSON.

## Deploy

Two ways, depending on where you run it.

**On the web server** — simplest, and the only option that works on shared
hosting where you can't write to `/var/www`. Run the whole block; the first
three lines are what put `deploy.sh` on the box in the first place:

```sh
ssh myfsdev@lacey.me
git clone -b claude/life-dashboard-tesla-6uz3mj \
  https://github.com/christopherlacey/SmartThingsPublic.git
cd SmartThingsPublic/tesla-dashboard
./deploy.sh --local
```

That installs the site, seeds `data.json` from the template, and stops. It then
prints the exact next two commands, with the path already quoted — the secret
path contains `$` and `!`, which an unquoted paste will mangle. Roughly:

```sh
nano ~/tesla.lacey.me/'TmX$23!'/data.json
./deploy.sh --check
```

Use `nano` (or `vi`) by name rather than `$EDITOR`, which is unset on many
shared hosts — bash then tries to *execute* the path instead of editing it.

**From a workstation** with a checkout and ssh access to the host:

```sh
SSH_HOST=myfsdev@lacey.me ./deploy.sh
```

The docroot is worked out from the URL host: `~/tesla.lacey.me` where that
exists (shared hosting), otherwise `/var/www/tesla.lacey.me`. Override with
`HOST_ROOT` if yours is elsewhere. Creating the directory does not create the
vhost — the domain still has to exist in the hosting panel or nothing is served
from it.

Either way, `./deploy.sh --check` verifies the live site and changes nothing.

The docroot and URL default to `tesla.lacey.me` and the unlisted path; override
`BASE_URL` to move it, and the docroot follows automatically.

The secret path contains `$` and `!`. Both are legal in a URL and on disk, but
they are exactly the characters a shell will eat, so `SECRET_PATH` is
single-quoted in `deploy.sh` and every remote path is single-quoted again for
the far side. In double quotes bash reads `"TmX$23!"` as `TmX3!` and deploys to
the wrong directory without complaining. Don't "tidy" those quotes.

The script refuses to deploy to any host that isn't `lacey.me` or
`chrislacey.com` unless you pass `CONFIRM_DOMAIN=yes`, because sending this
particular payload to the wrong domain isn't a recoverable mistake.

`data.json` on the server is never overwritten. On a first deploy the script
copies the example into place and stops, so you can fill it in before the car
shows a screen full of `REPLACE`.

`--check` verifies the page is the current version, that `app.css`, `app.js` and
the vendored Leaflet all return 200, that `data.json` is reachable, parses and
no longer contains placeholder values, and — most importantly — that the parent
directory does not hand the secret path back to anyone who asks for it.

## What protects this, and what doesn't

There is no login. The unlisted path *is* the access control, so everything
that could reveal the path is a way in. `.htaccess` closes the ones on our
side — directory listing off, `no-store` on the HTML and JSON, `X-Robots-Tag`
so a crawler that finds `data.json` directly won't index it — and the page
sends `Referrer-Policy: no-referrer` so the URL doesn't travel to the tile
server or Google Fonts.

What that still leaves:

- **`TmX$23!` is seven human-chosen characters.** It stops casual discovery and
  search engines. It is not a password, and it doesn't survive being written
  down, screenshotted with the URL bar visible, or read off the car screen by a
  passenger.
- **`data.json` is world-readable to anyone with the path** — the page fetches
  it client-side, so it has to be. That single file is every position and every
  medical record, in plain JSON, ready to copy.
- **It can't be taken back.** Once the path is out, rotating it doesn't remove
  whatever was already fetched.
- **John and Addy can't consent through Chris.** Publishing your own location
  and medical history is your call; publishing theirs is theirs to agree to.

If you later want real protection without changing the dashboard or how the car
opens it, put a few lines of PHP in front of `index.html` that require
`?k=<long random string>` and 404 without it, and let the car's bookmark carry
the key. Same one-tap experience, but the secret is then long enough to be worth
something and can be rotated without moving the site.
