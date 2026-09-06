# connect.chrislacey.com

The "every way to reach Chris" page, plus the two server-side pieces it depends on.

## Files

| File | What it is |
|---|---|
| `index.html` | The page itself. Deploy to `connect.chrislacey.com`. |
| `go.php` | Link redirector. Deploy to `emergency.chrislacey.com/go.php`. |
| `contacts.php.example` | Channel code → real destination. Copy to `contacts.php`, fill in, **never commit**. |
| `alert.php` | Fires when someone taps a 911 button. Deploy to `emergency.chrislacey.com/alert.php`. |
| `alert-config.php.example` | Where 911 alerts go. Copy to `alert-config.php`, fill in, **never commit**. |

## Fix this first

Every button on the live site is currently broken. `go.php?c=call` returns HTTP 500
with `Contact configuration missing.` — `contacts.php` is missing or unreadable on
the server. Until it exists, nothing on the page works except 911.

`tel:911` is hardcoded in the page and deliberately does **not** route through
`go.php`. A redirector outage must never be able to break the 911 button.

## Channel codes

All 19 buttons route through `go.php?c=<code>`. Each needs an entry in `contacts.php`.

**Chris** (page order is fastest → slowest, WhatsApp call first, phone call last):
`whatsapp-call`, `whatsapp-message`, `facetime`, `imessage`, `signal`, `telegram`,
`google-chat`, `sms`, `email`, `schedule`, `call`

**Student or Friend of Addy:** `addy-student`, `addy-friend`, `addy-urgent`

**Brasil:** `brasil` · **NGNS:** `ngns-discord` · **Business:** `barandcocoa`
· **Elsewhere:** `website`, `linkedin`

## The 911 alert

Tapping either 911 button (top button or bottom strip) dials 911 *and* sends Chris
an alert. Two things matter about how it's wired:

- **Nothing delays the call.** The page uses `navigator.sendBeacon` and never waits
  for a response, and `alert.php` returns `204` before it does any work. The dialer
  opens at the same speed it would with no alerting at all.
- **Location is requested on page load, not on tap.** Asking at tap time would put
  a permission dialog between the person and their call. If they allow it, the
  coordinates are already cached and ride along instantly; if they deny it, the
  alert still sends with everything else.

Alerts go to Chris's phone (SMS, WhatsApp, a ringing voice call, push) and Chris's
computer (push, plus the full dossier by email) — and nowhere else. Every
destination in `alert-config.php` is a personal endpoint by design; do not add a
shared inbox, team address or group chat. Unconfigured channels are skipped, and
the on-disk log is written either way, so a press is never lost.

**What gets reported:** timestamp, IP and reverse DNS, CDN geo (city/region/country),
GPS coordinates if allowed, device and browser, screen, network type, timezone and
languages, referrer, all request headers, and that IP's other recent hits from the
site's own access log.

**What deliberately does not happen:** no third-party lookup service is ever called
on the visitor. Everything above is either sent by their browser, attached by the
CDN, or already in Chris's own logs — their address is never handed to an outside
data broker to enrich.

Two consequences worth knowing before you deploy:

- The alert log holds IP addresses and sometimes GPS coordinates of people in an
  emergency. Keep it outside the web root (the config defaults to `/var/log/`),
  restrict who can read it, and set a retention period.
- Collecting this needs to be disclosed. The page footer says so in plain language;
  `privacy.chrislacey.com` should describe it too, since precise location is
  sensitive data under GDPR/CCPA and consent has to be informed to count.

## Deploy

```sh
# page
rsync index.html  connect.chrislacey.com:/var/www/connect/

# redirector + alerting
rsync go.php alert.php  emergency.chrislacey.com:/var/www/emergency/

# config, once, by hand — never from the repo
cp contacts.php.example      contacts.php       && $EDITOR contacts.php
cp alert-config.php.example  alert-config.php   && $EDITOR alert-config.php
chmod 600 contacts.php alert-config.php
```

Then check:

```sh
curl -sI "https://emergency.chrislacey.com/go.php?c=whatsapp-call" | head -1   # want 302
curl -sI "https://emergency.chrislacey.com/go.php?c=nope"          | head -1   # want 404
```

## Still needs your input

Placeholders in `contacts.php.example` marked `REPLACE`:

- **Brasil** — I couldn't find this page. Nothing links to it from `chrislacey.com`
  and no obvious subdomain resolves, so `brasil` points at a placeholder.
- **LinkedIn** — I don't have your profile URL.
- **Google Chat** — needs a DM or space link from your account.
- **Addy** — `addy-student` and `addy-friend` point at placeholder mailboxes on
  purpose, so students and their parents never end up holding your cell number.
  `addy-urgent` is the one exception and goes straight to WhatsApp call.
