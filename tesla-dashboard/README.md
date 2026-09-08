# Tesla Model X Dashboard

A single-screen dashboard for the Model X centre display: where Chris, John and
Addy each are, what each of them has on today, and their medical information.

Target URL: `https://lacey.me/Tesla-Model-X-Dashboard/`

## Files

| File | What it is |
|---|---|
| `index.html` | The page. |
| `app.css` | Styling, sized for the 17" car screen. |
| `app.js` | Behaviour: fetch, render, refresh, map. |
| `data.example.json` | The data schema, with placeholder values. Copy to `data.json`. |
| `vendor/leaflet/` | Leaflet 1.9.4, self-hosted (BSD-2-Clause, see its `LICENSE`). |
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

```sh
# check what's live without changing anything
./deploy.sh --check

# deploy
SSH_HOST=chris@your-web-host \
DASH_DIR=/var/www/lacey.me/Tesla-Model-X-Dashboard \
  ./deploy.sh
```

`data.json` on the server is never overwritten. On a first deploy the script
copies the example into place and stops, so you can fill it in before the car
shows a screen full of `REPLACE`.

`--check` verifies the page is the current version, that `app.css`, `app.js` and
the vendored Leaflet all return 200, that `data.json` is reachable and parses,
and that it no longer contains placeholder values.

## Before this goes public

The URL is unlisted and carries `noindex`, but that only deters search engines,
not people. As published, anyone who has or guesses the URL can read all three
people's exact live coordinates and full medical records.

Two things are worth deciding deliberately rather than by default:

- **John and Addy can't consent through Chris.** Publishing your own location
  and medical history is your call to make; publishing theirs isn't, unless
  they've each agreed to specifically this.
- **It can't be taken back.** A page like this gets scraped and cached. Removing
  it later doesn't remove the copies.

If you want it gated later, the cheapest change is a token: have the car's
bookmark carry `?k=<long-random-string>`, and put a few lines of PHP in front of
`index.html` that 404s without it. Nothing about the dashboard itself changes,
and the car still opens it in one tap with no typing.
