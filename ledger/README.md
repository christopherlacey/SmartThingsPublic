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

## One thing to look at

`c.lacey.me` served every page of this dashboard — including Medical, and the
full names and locations of 26 people — to an unauthenticated request from a
throwaway cloud container. `noindex` keeps it out of search results but is not
access control, and the pages set `Cache-Control: no-store` but nothing checks
who is asking.

Nothing in this change makes that better or worse; Finance and Shopping simply
inherit whatever protects the rest, which today is the obscurity of the URL.
Given that the two new tabs add household spending to what's already there, it
is worth deciding deliberately. Even HTTP basic auth at the Apache level, or an
IP allowlist, would close it — happy to wire either up.
