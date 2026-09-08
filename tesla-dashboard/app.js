/* ---------------------------------------------------------------------------
   Lacey Dashboard — behaviour.

   Everything on screen comes from data.json, fetched on load and re-fetched on
   an interval. There is no build step and no framework: this file is served as
   written, which is what makes it debuggable from the car with nothing but the
   Tesla browser.

   Two constraints shaped the code:

     * MCU2 Teslas run an older Chromium. Optional chaining (?.), nullish
       coalescing (??) and friends are avoided throughout — hence the get()
       helper, which reads worse than a?.b but actually runs on the target.
     * The car is on LTE and will drop. Every network dependency degrades:
       a failed data.json keeps the last good render on screen, and a failed
       Leaflet falls back to a coordinate readout instead of a blank panel.
--------------------------------------------------------------------------- */

(function () {
  'use strict';

  var DATA_URL = 'data.json';
  var DEFAULT_REFRESH = 120;      // seconds between data.json polls
  var DEFAULT_STALE = 20;         // a location fix older than this is flagged

  var state = {
    data: null,
    tab: 'map',
    focus: null,                  // person id, or null for "everyone"
    map: null,
    markers: {},                  // id -> { marker, circle }
    mapBroken: false,
    lastGood: null,
    failures: 0
  };

  /* ------------------------------------------------------------ helpers -- */

  function $(sel) { return document.querySelector(sel); }

  // Safe property read. Stands in for a?.b on browsers that lack it.
  function get(obj, key, fallback) {
    if (obj && obj[key] !== undefined && obj[key] !== null) return obj[key];
    return fallback;
  }

  function arr(obj, key) {
    var v = get(obj, key, null);
    return Object.prototype.toString.call(v) === '[object Array]' ? v : [];
  }

  var ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  function esc(s) {
    if (s === null || s === undefined) return '';
    return String(s).replace(/[&<>"']/g, function (c) { return ESCAPES[c]; });
  }

  // "14:05" -> "2:05 PM". Anything unparseable is passed through untouched so a
  // hand-typed "all day" in data.json still renders as written.
  function fmtTime(hhmm) {
    var m = /^(\d{1,2}):(\d{2})$/.exec(String(hhmm || '').trim());
    if (!m) return esc(hhmm);
    var h = parseInt(m[1], 10);
    var suffix = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12;
    if (h12 === 0) h12 = 12;
    return h12 + ':' + m[2] + ' ' + suffix;
  }

  // Minutes since midnight, or null when the value isn't a clock time.
  function minutesOf(hhmm) {
    var m = /^(\d{1,2}):(\d{2})$/.exec(String(hhmm || '').trim());
    if (!m) return null;
    return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
  }

  function nowMinutes() {
    var d = new Date();
    return d.getHours() * 60 + d.getMinutes();
  }

  // "4 min ago" / "2 hr ago". Returns null if the timestamp is missing or junk.
  function ago(iso) {
    if (!iso) return null;
    var t = Date.parse(iso);
    if (isNaN(t)) return null;
    var secs = Math.round((Date.now() - t) / 1000);
    if (secs < 0) secs = 0;
    if (secs < 60) return secs + ' sec ago';
    var mins = Math.round(secs / 60);
    if (mins < 60) return mins + ' min ago';
    var hrs = Math.round(mins / 60);
    if (hrs < 24) return hrs + ' hr ago';
    return Math.round(hrs / 24) + ' d ago';
  }

  function minutesSince(iso) {
    if (!iso) return null;
    var t = Date.parse(iso);
    if (isNaN(t)) return null;
    return (Date.now() - t) / 60000;
  }

  function people() { return arr(state.data, 'people'); }

  function shown() {
    var all = people();
    if (!state.focus) return all;
    var only = all.filter(function (p) { return p.id === state.focus; });
    return only.length ? only : all;
  }

  /* -------------------------------------------------------------- clock -- */

  function tickClock() {
    var d = new Date();
    var h = d.getHours();
    var suffix = h >= 12 ? 'PM' : 'AM';
    var h12 = h % 12; if (h12 === 0) h12 = 12;
    var mm = d.getMinutes() < 10 ? '0' + d.getMinutes() : String(d.getMinutes());
    $('#clock-time').textContent = h12 + ':' + mm + ' ' + suffix;
    $('#clock-date').textContent = d.toLocaleDateString(undefined, {
      weekday: 'long', month: 'long', day: 'numeric'
    });
  }

  /* --------------------------------------------------------- schedule fx -- */

  // Sorts today's events and marks exactly one as current and one as next.
  function analyseDay(person) {
    var events = arr(person, 'schedule').slice();

    events.sort(function (a, b) {
      var am = minutesOf(get(a, 'start', ''));
      var bm = minutesOf(get(b, 'start', ''));
      if (am === null && bm === null) return 0;
      if (am === null) return -1;          // all-day items float to the top
      if (bm === null) return 1;
      return am - bm;
    });

    var now = nowMinutes();
    var current = null, next = null;

    for (var i = 0; i < events.length; i++) {
      var e = events[i];
      var s = minutesOf(get(e, 'start', ''));
      var en = minutesOf(get(e, 'end', ''));
      if (s === null) { e._state = 'allday'; continue; }
      if (en === null) en = s + 60;        // assume an hour when no end is given
      if (now >= s && now < en) { e._state = 'current'; if (!current) current = e; }
      else if (now >= en) { e._state = 'past'; }
      else { e._state = 'upcoming'; if (!next) { next = e; e._state = 'next'; } }
    }
    return { events: events, current: current, next: next };
  }

  /* ---------------------------------------------------------------- rail -- */

  function renderRail() {
    var html = people().map(function (p) {
      var loc = get(p, 'location', {});
      var day = analyseDay(p);
      var fixAgo = ago(get(loc, 'updated', null));
      var mins = minutesSince(get(loc, 'updated', null));
      var staleAfter = get(get(state.data, 'meta', {}), 'staleMinutes', DEFAULT_STALE);
      var isStale = mins !== null && mins > staleAfter;

      var moving = get(loc, 'status', '') === 'moving';
      var speed = get(loc, 'speedMph', null);
      var detail = get(loc, 'detail', '');
      if (moving && speed !== null) {
        detail = (detail ? detail + ' · ' : '') + Math.round(speed) + ' mph';
      }

      var flag = medicalFlag(p);

      // nowTxt/nextTxt are assembled from pieces that are already escaped by
      // esc()/fmtTime(), so they are interpolated raw below. Anything added
      // here must be escaped at the point it is concatenated.
      var nowTxt = day.current
        ? esc(get(day.current, 'title', '')) +
          (get(day.current, 'where', '') ? ' <span class="at">· ' + esc(day.current.where) + '</span>' : '')
        : 'Nothing scheduled';
      var nextTxt = day.next
        ? fmtTime(get(day.next, 'start', '')) + ' · ' + esc(get(day.next, 'title', ''))
        : 'Clear for the rest of the day';

      return '' +
        '<article class="person' + (state.focus === p.id ? ' focused' : '') + '"' +
        ' style="--person:' + esc(get(p, 'color', '#0EA5E9')) + '"' +
        ' data-person="' + esc(p.id) + '" role="button" tabindex="0"' +
        ' aria-label="' + esc(get(p, 'name', '')) + ' — tap to focus">' +
          (flag ? '<span class="flag">' + esc(flag) + '</span>' : '') +
          '<div class="who">' +
            '<span class="avatar">' + esc(get(p, 'initials', String(get(p, 'name', '?')).charAt(0))) + '</span>' +
            '<span class="name">' + esc(get(p, 'name', '')) + '</span>' +
            (get(p, 'role', '') && !flag ? '<span class="role">' + esc(p.role) + '</span>' : '') +
          '</div>' +
          '<div class="place">' + esc(get(loc, 'place', 'Location unknown')) + '</div>' +
          (detail ? '<div class="place-detail">' + esc(detail) + '</div>' : '') +
          '<div class="fix' + (isStale ? ' stale' : '') + '">' +
            (fixAgo ? (moving ? 'Moving · ' : '') + 'fix ' + esc(fixAgo) : 'No recent fix') +
          '</div>' +
          '<div class="now">' +
            '<div class="nowline is-now' + (day.current ? '' : ' empty') + '">' +
              '<span class="tag">Now</span><span class="txt">' + nowTxt + '</span></div>' +
            '<div class="nowline' + (day.next ? '' : ' empty') + '">' +
              '<span class="tag">Next</span><span class="txt">' + nextTxt + '</span></div>' +
          '</div>' +
        '</article>';
    }).join('');

    $('#rail').innerHTML = html;
  }

  // The one thing allowed to interrupt a glance: a severe allergy or an
  // explicit alert set on the person's medical record.
  function medicalFlag(person) {
    var med = get(person, 'medical', {});
    if (get(med, 'alert', '')) return String(med.alert);
    var sev = arr(med, 'allergies').filter(function (a) {
      var s = String(get(a, 'severity', '')).toLowerCase();
      return s === 'severe' || s === 'anaphylaxis';
    });
    if (sev.length) return 'Severe allergy';
    return '';
  }

  /* ----------------------------------------------------------------- map -- */

  function initMap() {
    if (typeof window.L === 'undefined') { state.mapBroken = true; return; }
    if (state.map) return;

    state.map = window.L.map('map', {
      zoomControl: true,
      attributionControl: true,
      // Touch screen in a moving car: no scroll-wheel, no double-tap zoom
      // fighting the driver, and inertia off so a pan stops where it's let go.
      scrollWheelZoom: false,
      doubleClickZoom: false,
      inertia: false,
      tap: true
    }).setView([39.5, -98.35], 4);

    window.L.tileLayer(
      'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
      {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors &copy; CARTO'
      }
    ).addTo(state.map);
  }

  function updateMap(fit) {
    if (state.mapBroken || !state.map) return;
    var L = window.L;
    var bounds = [];

    people().forEach(function (p) {
      var loc = get(p, 'location', {});
      var lat = get(loc, 'lat', null);
      var lon = get(loc, 'lon', null);
      if (lat === null || lon === null) return;

      var color = get(p, 'color', '#0EA5E9');
      var latlng = [Number(lat), Number(lon)];
      bounds.push(latlng);

      var entry = state.markers[p.id];
      if (!entry) {
        var icon = L.divIcon({
          className: '',
          html: '<div class="pin" style="background:' + esc(color) + ';--ring:' + esc(color) + '">' +
                esc(get(p, 'initials', '?')) + '</div>',
          iconSize: [34, 34],
          iconAnchor: [17, 17]
        });
        entry = {
          marker: L.marker(latlng, { icon: icon, keyboard: false }).addTo(state.map),
          halo: L.circle(latlng, {
            radius: 120, color: color, weight: 1, opacity: 0.5,
            fillColor: color, fillOpacity: 0.10
          }).addTo(state.map)
        };
        state.markers[p.id] = entry;
      } else {
        entry.marker.setLatLng(latlng);
        entry.halo.setLatLng(latlng);
      }

      entry.marker.bindTooltip(
        esc(get(p, 'name', '')) + ' — ' + esc(get(loc, 'place', 'Unknown')),
        { direction: 'top', offset: [0, -18] }
      );
    });

    // Drop pins for anyone who has since lost their fix.
    Object.keys(state.markers).forEach(function (id) {
      var still = people().some(function (p) {
        var l = get(p, 'location', {});
        return p.id === id && get(l, 'lat', null) !== null;
      });
      if (!still) {
        state.map.removeLayer(state.markers[id].marker);
        state.map.removeLayer(state.markers[id].halo);
        delete state.markers[id];
      }
    });

    if (fit && bounds.length) fitMap();
    // Leaflet mis-measures a container that was display:none when it loaded.
    setTimeout(function () { if (state.map) state.map.invalidateSize(); }, 60);
  }

  function fitMap() {
    if (!state.map) return;
    var list = shown();
    var bounds = [];
    list.forEach(function (p) {
      var loc = get(p, 'location', {});
      if (get(loc, 'lat', null) !== null && get(loc, 'lon', null) !== null) {
        bounds.push([Number(loc.lat), Number(loc.lon)]);
      }
    });
    if (!bounds.length) return;
    if (bounds.length === 1) state.map.setView(bounds[0], 15);
    else state.map.fitBounds(bounds, { padding: [90, 90], maxZoom: 16 });
  }

  function renderMapSide() {
    // Legend doubles as the exact-coordinate readout.
    $('#map-legend').innerHTML = people().map(function (p) {
      var loc = get(p, 'location', {});
      var lat = get(loc, 'lat', null), lon = get(loc, 'lon', null);
      var coord = (lat === null || lon === null)
        ? 'no fix'
        : Number(lat).toFixed(5) + ', ' + Number(lon).toFixed(5);
      return '<div class="legend-row" style="--person:' + esc(get(p, 'color', '#0EA5E9')) + '">' +
               '<span class="swatch"></span>' +
               '<span class="lname">' + esc(get(p, 'name', '')) + '</span>' +
               '<span class="lcoord">' + esc(coord) + '</span>' +
             '</div>';
    }).join('');

    if (state.mapBroken) {
      $('#map-fallback').classList.add('show');
      $('#view-map').classList.add('fallback-on');
      $('#fallback-grid').innerHTML = people().map(function (p) {
        var loc = get(p, 'location', {});
        var lat = get(loc, 'lat', null), lon = get(loc, 'lon', null);
        var coord = (lat === null || lon === null) ? '—'
          : Number(lat).toFixed(5) + ', ' + Number(lon).toFixed(5);
        var maps = (lat === null || lon === null) ? null
          : 'https://www.google.com/maps/search/?api=1&query=' + Number(lat) + ',' + Number(lon);
        return '<div class="col" style="--person:' + esc(get(p, 'color', '#0EA5E9')) + '">' +
                 '<h3>' + esc(get(p, 'name', '')) + '</h3>' +
                 '<div class="col-body">' +
                   '<div class="place" style="font-size:1.3rem">' + esc(get(loc, 'place', 'Unknown')) + '</div>' +
                   '<div class="fix">' + esc(coord) + '</div>' +
                   '<div class="fix">' + esc(ago(get(loc, 'updated', null)) || 'no recent fix') + '</div>' +
                   (maps ? '<a class="call" style="margin-top:.6rem" href="' + esc(maps) + '" target="_blank" rel="noopener">' +
                             '<span>Open in Maps</span><span class="c-num">›</span></a>' : '') +
                 '</div>' +
               '</div>';
      }).join('');
    } else {
      $('#map-fallback').classList.remove('show');
      $('#view-map').classList.remove('fallback-on');
    }
  }

  /* ----------------------------------------------------------------- day -- */

  function renderDay() {
    var list = shown();
    var host = $('#day-cols');
    host.className = 'cols' + (list.length === 1 ? ' single' : '');
    host.innerHTML = list.map(function (p) {
      var day = analyseDay(p);
      var body = day.events.length
        ? day.events.map(function (e) {
            var st = get(e, '_state', 'upcoming');
            var kind = String(get(e, 'kind', '')).toLowerCase();
            var cls = 'evt' +
              (st === 'past' ? ' past' : '') +
              (st === 'current' ? ' current' : '') +
              (st === 'next' ? ' next' : '') +
              (kind ? ' kind-' + esc(kind) : '');
            var when = minutesOf(get(e, 'start', '')) === null
              ? 'All day'
              : fmtTime(get(e, 'start', '')) + (get(e, 'end', '') ? '<br>' + fmtTime(e.end) : '');
            var badge = st === 'current' ? '<span class="badge">Now</span>'
                      : st === 'next' ? '<span class="badge">Next</span>'
                      : kind === 'medical' ? '<span class="badge">Medical</span>' : '';
            return '<div class="' + cls + '">' +
                     '<div class="when">' + when + '</div>' +
                     '<div>' +
                       '<div class="what">' + esc(get(e, 'title', '')) + badge + '</div>' +
                       (get(e, 'where', '') ? '<div class="where">' + esc(e.where) + '</div>' : '') +
                       (get(e, 'note', '') ? '<div class="note">' + esc(e.note) + '</div>' : '') +
                     '</div>' +
                   '</div>';
          }).join('')
        : '<div class="empty-note">Nothing on the calendar today.</div>';

      var remaining = day.events.filter(function (e) { return get(e, '_state', '') !== 'past'; }).length;

      return '<section class="col" style="--person:' + esc(get(p, 'color', '#0EA5E9')) + '">' +
               '<h3>' + esc(get(p, 'name', '')) +
                 '<span class="sub">' + remaining + ' left today</span></h3>' +
               '<div class="col-body scroll">' + body + '</div>' +
             '</section>';
    }).join('');
  }

  /* ------------------------------------------------------------- medical -- */

  function section(title, inner) {
    if (!inner) return '';
    return '<div class="med-section"><h4>' + esc(title) + '</h4>' + inner + '</div>';
  }

  function callRow(role, name, phone, personColor) {
    if (!phone) {
      return name ? '<div class="med-row"><span class="m-name">' + esc(name) +
                    '</span><span class="m-meta">' + esc(role) + '</span></div>' : '';
    }
    var tel = String(phone).replace(/[^0-9+]/g, '');
    return '<a class="call" href="tel:' + esc(tel) + '">' +
             '<span><span class="c-role">' + esc(role) + '</span>' + esc(name) + '</span>' +
             '<span class="c-num">' + esc(phone) + '</span>' +
           '</a>';
  }

  function renderMedical() {
    var list = shown();
    var host = $('#med-cols');
    host.className = 'cols' + (list.length === 1 ? ' single' : '');

    host.innerHTML = list.map(function (p) {
      var med = get(p, 'medical', {});

      var allergies = arr(med, 'allergies');
      var allergyHtml = allergies.length
        ? '<div class="chip-row">' + allergies.map(function (a) {
            var sev = String(get(a, 'severity', '')).toLowerCase();
            var severe = (sev === 'severe' || sev === 'anaphylaxis');
            var label = esc(get(a, 'what', ''));
            var reaction = get(a, 'reaction', '');
            return '<span class="chip' + (severe ? ' severe' : '') + '">' + label +
                   (reaction ? ' — ' + esc(reaction) : '') + '</span>';
          }).join('') + '</div>'
        : '<div class="note-p">No known allergies.</div>';

      var meds = arr(med, 'medications');
      var medsHtml = meds.length
        ? meds.map(function (m) {
            return '<div class="med-row">' +
                     '<span class="m-name">' + esc(get(m, 'name', '')) + '</span>' +
                     '<span class="m-meta">' + esc(get(m, 'dose', '')) +
                       (get(m, 'schedule', '') ? ' · ' + esc(m.schedule) : '') + '</span>' +
                     (get(m, 'note', '') ? '<span class="m-note">' + esc(m.note) + '</span>' : '') +
                   '</div>';
          }).join('')
        : '';

      var conds = arr(med, 'conditions');
      var condHtml = conds.length
        ? conds.map(function (c) {
            return '<div class="med-row">' +
                     '<span class="m-name">' + esc(get(c, 'name', '')) + '</span>' +
                     '<span class="m-meta">' + esc(get(c, 'since', '')) + '</span>' +
                     (get(c, 'notes', '') ? '<span class="m-note">' + esc(c.notes) + '</span>' : '') +
                   '</div>';
          }).join('')
        : '';

      var devices = arr(med, 'devices');
      var devHtml = devices.length
        ? '<div class="chip-row">' + devices.map(function (d) {
            return '<span class="chip">' + esc(d) + '</span>';
          }).join('') + '</div>'
        : '';

      var providers = arr(med, 'providers');
      var provHtml = providers.map(function (v) {
        return callRow(get(v, 'role', 'Provider'), get(v, 'name', ''), get(v, 'phone', ''));
      }).join('');

      var pharmacy = get(med, 'pharmacy', null);
      var pharmHtml = pharmacy
        ? callRow('Pharmacy', get(pharmacy, 'name', ''), get(pharmacy, 'phone', '')) +
          (get(pharmacy, 'address', '')
            ? '<div class="note-p">' + esc(pharmacy.address) + '</div>' : '')
        : '';

      var contacts = arr(med, 'emergency');
      var contactHtml = contacts.map(function (c) {
        return callRow(get(c, 'relation', 'Contact'), get(c, 'name', ''), get(c, 'phone', ''));
      }).join('');

      var appts = arr(med, 'appointments');
      var apptHtml = appts.length
        ? appts.map(function (a) {
            return '<div class="med-row">' +
                     '<span class="m-name">' + esc(get(a, 'what', '')) + '</span>' +
                     '<span class="m-meta">' + esc(get(a, 'when', '')) + '</span>' +
                     (get(a, 'where', '') ? '<span class="m-note">' + esc(a.where) + '</span>' : '') +
                   '</div>';
          }).join('')
        : '';

      var notes = arr(med, 'notes');
      var notesHtml = notes.map(function (n) {
        return '<p class="note-p">' + esc(n) + '</p>';
      }).join('');

      var vitals = [];
      if (get(med, 'bloodType', '')) vitals.push('<span class="chip big">Blood ' + esc(med.bloodType) + '</span>');
      if (get(med, 'organDonor', null) !== null) {
        vitals.push('<span class="chip big">' + (med.organDonor ? 'Organ donor' : 'Not an organ donor') + '</span>');
      }
      var vitalsHtml = vitals.length ? '<div class="chip-row">' + vitals.join('') + '</div>' : '';

      var flag = medicalFlag(p);

      return '<section class="col" style="--person:' + esc(get(p, 'color', '#0EA5E9')) + '">' +
               '<h3>' + esc(get(p, 'name', '')) +
                 (flag ? '<span class="sub" style="color:var(--urgent)">' + esc(flag) + '</span>' : '') +
               '</h3>' +
               '<div class="col-body scroll">' +
                 vitalsHtml +
                 section('Allergies', allergyHtml) +
                 section('Conditions', condHtml) +
                 section('Medications', medsHtml) +
                 section('Devices & implants', devHtml) +
                 section('Upcoming care', apptHtml) +
                 section('Care team', provHtml) +
                 section('Pharmacy', pharmHtml) +
                 section('Emergency contacts', contactHtml) +
                 section('Notes', notesHtml) +
               '</div>' +
             '</section>';
    }).join('');
  }

  /* --------------------------------------------------------------- shell -- */

  function renderAll(fitMapAfter) {
    if (!state.data) return;
    renderRail();
    renderMapSide();
    renderDay();
    renderMedical();
    updateMap(fitMapAfter);
  }

  function setTab(tab) {
    state.tab = tab;
    ['map', 'day', 'medical'].forEach(function (t) {
      var btn = $('#tab-' + t);
      var view = $('#view-' + t);
      if (btn) btn.setAttribute('aria-selected', t === tab ? 'true' : 'false');
      if (view) view.classList.toggle('active', t === tab);
    });
    if (tab === 'map' && state.map) {
      // The container had no size while hidden; Leaflet needs telling.
      setTimeout(function () { state.map.invalidateSize(); fitMap(); }, 50);
    }
  }

  function setFocus(id) {
    state.focus = (state.focus === id) ? null : id;
    renderRail();
    renderDay();
    renderMedical();
    if (state.tab === 'map') fitMap();
  }

  function setSync(kind, text) {
    var el = $('#sync');
    el.className = 'sync' + (kind ? ' ' + kind : '');
    $('#sync-text').textContent = text;
  }

  /* ---------------------------------------------------------------- data -- */

  function load(first) {
    // Preview builds (make-preview.py) embed their sample data and have no
    // data.json to fetch. Production never defines this, so the fetch path
    // below is unchanged on the real site.
    if (window.__PREVIEW_DATA__) {
      state.data = window.__PREVIEW_DATA__;
      state.lastGood = Date.now();
      state.failures = 0;
      $('#setup').hidden = true;
      if (first) initMap();
      renderAll(first);
      setSync('', 'Sample data');
      return;
    }

    // Cache-bust: the Tesla browser is aggressive about holding onto files, and
    // a dashboard showing yesterday's positions is worse than showing none.
    fetch(DATA_URL + '?t=' + Date.now(), { cache: 'no-store' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (json) {
        state.data = json;
        state.lastGood = Date.now();
        state.failures = 0;
        $('#setup').hidden = true;
        if (first) initMap();
        renderAll(first);
        setSync('', 'Updated ' + new Date().toLocaleTimeString(undefined,
          { hour: 'numeric', minute: '2-digit' }));
        scheduleNext();
      })
      .catch(function (err) {
        state.failures++;
        if (!state.data) {
          // Never loaded at all — this is a setup problem, so say so plainly
          // rather than leaving a dark empty screen in the car.
          $('#setup').hidden = false;
          $('#setup-detail').textContent = String(err && err.message ? err.message : err);
        } else {
          // We have a previous good render; keep it and flag the staleness.
          setSync(state.failures > 2 ? 'error' : 'stale',
                  'Offline · last ' + (ago(new Date(state.lastGood).toISOString()) || 'unknown'));
        }
        scheduleNext();
      });
  }

  var timer = null;
  function scheduleNext() {
    if (timer) clearTimeout(timer);
    var secs = get(get(state.data, 'meta', {}), 'refreshSeconds', DEFAULT_REFRESH);
    if (state.failures > 0) secs = Math.min(secs, 30);   // retry sooner when down
    timer = setTimeout(function () { load(false); }, Math.max(10, secs) * 1000);
  }

  /* ----------------------------------------------------------------- init -- */

  function init() {
    tickClock();
    setInterval(tickClock, 1000);

    // Re-render once a minute so "now/next" and "fix N min ago" stay truthful
    // between data fetches.
    setInterval(function () { if (state.data) { renderRail(); renderDay(); } }, 60000);

    ['map', 'day', 'medical'].forEach(function (t) {
      var btn = $('#tab-' + t);
      if (btn) btn.addEventListener('click', function () { setTab(t); });
    });

    $('#rail').addEventListener('click', function (e) {
      var card = e.target.closest ? e.target.closest('.person') : null;
      if (card) setFocus(card.getAttribute('data-person'));
    });

    $('#recenter').addEventListener('click', function () {
      state.focus = null;
      renderRail();
      fitMap();
    });

    // Leaflet is loaded from a CDN; if the car has no signal at boot it simply
    // won't be there. Decide once, up front, and render the fallback instead.
    if (typeof window.L === 'undefined') state.mapBroken = true;

    setTab('map');
    setSync('stale', 'Loading…');
    load(true);

    // Coming back from sleep, the data is certainly stale — refresh on wake.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) load(false);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
