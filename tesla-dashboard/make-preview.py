#!/usr/bin/env python3
"""
Build a self-contained preview of the dashboard for device testing.

The real site is several files served from Apache. To open it on the car screen
before the host is set up, everything has to collapse into one HTML file with no
relative fetches: styles, Leaflet, the app, and a sample dataset all inline.

The output is written for a host that wraps content in its own document, so it
emits no <!doctype>, <html>, <head> or <body> tags -- just the title, styles,
markup and scripts.

    python3 make-preview.py out.html

Nothing here is a second copy of the dashboard: the markup, CSS and JS are read
from the real files, so a preview can't drift from what actually deploys.
"""

import json
import pathlib
import re
import sys

HERE = pathlib.Path(__file__).parent
OUT = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "preview.html")


def read(*parts):
    return HERE.joinpath(*parts).read_text(encoding="utf-8")



# The fixture was authored around midday. Left alone it would show a frozen
# afternoon and a location fix hours stale, so the preview would look broken
# rather than idle. This anchors the sample day to whenever the page is opened,
# keeping the shape of the fixture and only sliding it along the clock.
FIXTURE_ANCHOR_MIN = 12 * 60 + 10          # when the sample day was written
FIXTURE_FIX_AGES = {"chris": 4, "john": 1, "addy": 26}   # minutes, as authored

FRESHEN_OPEN = """window.__PREVIEW_DATA__ = (function (d) {
  var ANCHOR = %d, AGES = %s;
  var n = new Date(), delta = (n.getHours() * 60 + n.getMinutes()) - ANCHOR;
  function shift(t) {
    var m = /^(\\d{1,2}):(\\d{2})$/.exec(t || '');
    if (!m) return t;
    var v = ((+m[1] * 60 + +m[2]) + delta + 1440) %% 1440;
    return ('0' + Math.floor(v / 60)).slice(-2) + ':' + ('0' + (v %% 60)).slice(-2);
  }
  for (var i = 0; i < d.people.length; i++) {
    var p = d.people[i], s = p.schedule || [];
    for (var j = 0; j < s.length; j++) { s[j].start = shift(s[j].start); s[j].end = shift(s[j].end); }
    var age = AGES[p.id] === undefined ? 5 : AGES[p.id];
    if (p.location) p.location.updated = new Date(Date.now() - age * 60000).toISOString();
  }
  return d;
})(""" % (FIXTURE_ANCHOR_MIN, json.dumps(FIXTURE_FIX_AGES))

FRESHEN_CLOSE = ");"

index = read("index.html")
app_css = read("app.css")
leaflet_css = read("vendor", "leaflet", "leaflet.css")
leaflet_js = read("vendor", "leaflet", "leaflet.js")
app_js = read("app.js")
sample = json.loads(read("sample.json"))

# Body content only: strip the document scaffolding the host supplies itself.
body = re.search(r"<body>(.*)</body>", index, re.S).group(1)

# The external <script src> tags are replaced by the inline copies below.
body = re.sub(r'\s*<script src="[^"]+"></script>', "", body)

# Leaflet resolves its marker sprites relative to the stylesheet. There is no
# stylesheet URL once it is inlined, and the pins are divIcons rather than
# images, so drop the rules that would chase missing files.
leaflet_css = re.sub(r"\.leaflet-default-icon-path[^}]*}", "", leaflet_css)

# One honest banner. This build shows fabricated sample records, and the map has
# no basemap because tile hosts aren't reachable from the preview host.
badge = (
    '<span class="preview-badge" role="note">'
    "Sample data &middot; no basemap on this host</span>"
)
body = body.replace(
    '<div class="brand">Lacey <span>Dashboard</span></div>',
    '<div class="brand">Lacey <span>Dashboard</span></div>\n    ' + badge,
)

badge_css = """
/* Preview-only. The real deploy has neither of these problems, so this rule
   ships in the generated file and never in app.css. */
.preview-badge {
  flex: none;
  font-size: 0.72rem;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: #d99a4e;
  border: 1px solid #d99a4e;
  border-radius: 999px;
  padding: 0.22rem 0.7rem;
  white-space: nowrap;
}
@media (max-width: 1100px) { .preview-badge { order: 3; } }
"""

OUT.write_text(
    "<title>Model X Life Dashboard</title>\n"
    '<link rel="preconnect" href="https://fonts.googleapis.com">\n'
    '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>\n'
    '<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600'
    '&family=IBM+Plex+Sans:wght@300;400;500&display=swap" rel="stylesheet">\n'
    f"<style>\n{leaflet_css}\n</style>\n"
    f"<style>\n{app_css}\n{badge_css}</style>\n"
    f"{body}\n"
    f"<script>{leaflet_js}</script>\n"
    f"<script>{FRESHEN_OPEN}{json.dumps(sample)}{FRESHEN_CLOSE}</script>\n"
    f"<script>{app_js}</script>\n",
    encoding="utf-8",
)

kb = OUT.stat().st_size / 1024
print(f"wrote {OUT} ({kb:.0f} KB)")
