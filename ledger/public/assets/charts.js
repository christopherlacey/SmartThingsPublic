/* Charts for The Lacey Ledger.
 *
 * Hand-rolled SVG rather than a charting library: the page runs under a strict
 * CSP with no third-party script origin, and every form this dashboard needs
 * fits in a few hundred lines.
 *
 * Series colours come from the CSS custom properties --series-1..5, so the
 * validated light and dark palettes swap with the theme and no hex is repeated
 * here. Rules held to throughout, from the house data-viz standard:
 *
 *   - One value axis. Never two scales on one chart.
 *   - Colour follows the entity, not its rank.
 *   - A legend whenever there are two or more series; direct labels up to four.
 *   - Grid and axes recessive; text in text tokens, never in a series colour.
 *   - A hover layer by default, and a table view for every chart, which is what
 *     licenses the light-mode series colours that sit under 3:1 on the surface.
 */

(function () {
  "use strict";

  var SVG = "http://www.w3.org/2000/svg";

  function el(name, attrs) {
    var node = document.createElementNS(SVG, name);
    for (var key in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, key) && attrs[key] !== null) {
        node.setAttribute(key, String(attrs[key]));
      }
    }
    return node;
  }

  function readJSON(id) {
    var node = document.getElementById(id);
    if (!node) return null;
    try {
      return JSON.parse(node.textContent);
    } catch (e) {
      return null;
    }
  }

  function seriesColor(i) {
    // Slots are fixed and never cycled; a 6th series is a data problem, not a
    // colour problem, so it falls back to the muted ink rather than inventing a hue.
    if (i > 4) return "var(--text-faint)";
    return "var(--series-" + (i + 1) + ")";
  }

  function fmt(value, how) {
    if (value === null || value === undefined || isNaN(value)) return "—";
    if (how === "money") {
      var sign = value < 0 ? "−" : "";
      var abs = Math.abs(value);
      if (abs >= 10000) return sign + "$" + Math.round(abs).toLocaleString();
      return sign + "$" + abs.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    if (how === "money0") {
      return (value < 0 ? "−" : "") + "$" + Math.round(Math.abs(value)).toLocaleString();
    }
    var rounded = Math.round(value * 100) / 100;
    return String(rounded);
  }

  /* Nice round bounds so the axis reads in human numbers. */
  function niceBounds(min, max) {
    if (min === max) {
      var pad = Math.abs(min) * 0.1 || 1;
      min -= pad;
      max += pad;
    }
    var span = max - min;
    var step = Math.pow(10, Math.floor(Math.log(span / 4) / Math.LN10));
    var err = (span / 4) / step;
    if (err >= 7.5) step *= 10;
    else if (err >= 3) step *= 5;
    else if (err >= 1.5) step *= 2;
    return {
      min: Math.floor(min / step) * step,
      max: Math.ceil(max / step) * step,
      step: step
    };
  }

  function ticks(bounds) {
    var out = [];
    for (var v = bounds.min; v <= bounds.max + bounds.step / 1000; v += bounds.step) {
      out.push(Math.round(v * 1e6) / 1e6);
    }
    return out;
  }

  /* ------------------------------------------------------------- tooltip -- */

  function makeTip(host) {
    var tip = document.createElement("div");
    tip.className = "chart-tip";
    tip.setAttribute("role", "status");
    host.style.position = "relative";
    host.appendChild(tip);
    return tip;
  }

  function showTip(tip, host, x, y, label, value) {
    tip.innerHTML = "";
    var l = document.createElement("span");
    l.className = "tip-label";
    l.textContent = label;
    var v = document.createElement("span");
    v.className = "tip-value";
    v.textContent = value;
    tip.appendChild(l);
    tip.appendChild(v);
    tip.style.left = x + "px";
    tip.style.top = (y - 8) + "px";
    tip.classList.add("on");
  }

  function hideTip(tip) {
    tip.classList.remove("on");
  }

  /* --------------------------------------------------------------- table -- */

  /* Every chart ships a table view. It is the accessible fallback, and it is the
   * relief the palette requires for the light-mode series colours that fall
   * below 3:1 against the surface. */
  function attachTable(host, headers, rows) {
    var wrap = document.createElement("div");

    var button = document.createElement("button");
    button.type = "button";
    button.className = "table-toggle";
    button.textContent = "Show the numbers";
    button.setAttribute("aria-expanded", "false");

    var view = document.createElement("div");
    view.className = "table-view scroll";
    view.hidden = true;

    var table = document.createElement("table");
    var thead = document.createElement("thead");
    var hr = document.createElement("tr");
    headers.forEach(function (head, i) {
      var th = document.createElement("th");
      th.textContent = head;
      if (i > 0) th.className = "num";
      hr.appendChild(th);
    });
    thead.appendChild(hr);
    table.appendChild(thead);

    var tbody = document.createElement("tbody");
    rows.forEach(function (row) {
      var tr = document.createElement("tr");
      row.forEach(function (cell, i) {
        var td = document.createElement("td");
        td.textContent = cell;
        if (i > 0) td.className = "num";
        tr.appendChild(td);
      });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    view.appendChild(table);

    button.addEventListener("click", function () {
      view.hidden = !view.hidden;
      button.setAttribute("aria-expanded", String(!view.hidden));
      button.textContent = view.hidden ? "Show the numbers" : "Hide the numbers";
    });

    wrap.appendChild(button);
    wrap.appendChild(view);
    host.appendChild(wrap);
  }

  function attachLegend(host, names) {
    // One series needs no legend box — the chart title already names it.
    if (names.length < 2) return;
    var ul = document.createElement("ul");
    ul.className = "legend";
    names.forEach(function (name, i) {
      var li = document.createElement("li");
      var sw = document.createElement("span");
      sw.className = "swatch";
      sw.style.background = seriesColor(i);
      var text = document.createElement("span");
      text.textContent = name;
      li.appendChild(sw);
      li.appendChild(text);
      ul.appendChild(li);
    });
    host.appendChild(ul);
  }

  /* ---------------------------------------------------------- sparkline -- */

  /* Trend inside a stat tile. No axes, no labels — the tile states the value. */
  function sparkline(host) {
    var raw = (host.getAttribute("data-spark") || "").split(",")
      .map(parseFloat).filter(function (n) { return !isNaN(n); });
    if (raw.length < 2) return;

    var w = 120, h = 30, pad = 3;
    var min = Math.min.apply(null, raw), max = Math.max.apply(null, raw);
    var span = (max - min) || 1;

    var svg = el("svg", { viewBox: "0 0 " + w + " " + h, preserveAspectRatio: "none", "aria-hidden": "true" });
    var points = raw.map(function (v, i) {
      var x = pad + (i / (raw.length - 1)) * (w - pad * 2);
      var y = h - pad - ((v - min) / span) * (h - pad * 2);
      return [x, y];
    });

    svg.appendChild(el("path", {
      d: "M" + points.map(function (p) { return p[0].toFixed(1) + " " + p[1].toFixed(1); }).join(" L"),
      class: "series-line",
      stroke: "var(--series-1)"
    }));

    var last = points[points.length - 1];
    svg.appendChild(el("circle", { cx: last[0], cy: last[1], r: 2.5, fill: "var(--series-1)" }));

    host.appendChild(svg);
  }

  /* -------------------------------------------------------------- line --- */

  /* Trend over time. Crosshair follows the pointer across the whole plot, so a
   * thin 2px line never has to be hit exactly. */
  function lineChart(host, data) {
    var labels = data.labels || [];
    var series = data.series || [];
    if (!labels.length || !series.length) return;

    var w = 640, h = 240;
    var padL = 52, padR = 16, padT = 14, padB = 28;
    var plotW = w - padL - padR, plotH = h - padT - padB;

    var all = [];
    series.forEach(function (s) {
      s.values.forEach(function (v) { if (v !== null && !isNaN(v)) all.push(v); });
    });
    if (!all.length) return;

    // Weight and blood pressure don't start at zero; forcing a zero baseline
    // would flatten the only movement worth seeing.
    var bounds = niceBounds(Math.min.apply(null, all), Math.max.apply(null, all));
    var svg = el("svg", { viewBox: "0 0 " + w + " " + h, role: "img" });
    svg.appendChild(el("title", {})).textContent = data.title || "Trend";

    function xAt(i) { return padL + (labels.length === 1 ? plotW / 2 : (i / (labels.length - 1)) * plotW); }
    function yAt(v) { return padT + plotH - ((v - bounds.min) / (bounds.max - bounds.min)) * plotH; }

    ticks(bounds).forEach(function (t) {
      var y = yAt(t);
      svg.appendChild(el("line", { x1: padL, y1: y, x2: w - padR, y2: y, class: "grid-line" }));
      var text = el("text", { x: padL - 8, y: y + 3, "text-anchor": "end", class: "axis-text" });
      text.textContent = fmt(t, data.format);
      svg.appendChild(text);
    });

    // Thin out x labels so they never collide.
    var every = Math.ceil(labels.length / 7);
    labels.forEach(function (label, i) {
      if (i % every !== 0 && i !== labels.length - 1) return;
      var text = el("text", { x: xAt(i), y: h - 8, "text-anchor": "middle", class: "axis-text" });
      text.textContent = label;
      svg.appendChild(text);
    });

    series.forEach(function (s, si) {
      var color = seriesColor(si);
      var path = [];
      s.values.forEach(function (v, i) {
        if (v === null || isNaN(v)) return;
        path.push((path.length ? "L" : "M") + xAt(i).toFixed(1) + " " + yAt(v).toFixed(1));
      });
      svg.appendChild(el("path", { d: path.join(" "), class: "series-line", stroke: color }));

      // Markers only when the points are far enough apart to read as marks.
      if (labels.length <= 24) {
        s.values.forEach(function (v, i) {
          if (v === null || isNaN(v)) return;
          svg.appendChild(el("circle", { cx: xAt(i), cy: yAt(v), r: 4, fill: color, class: "series-dot" }));
        });
      }

      // Direct label at the end of the line, up to four series.
      if (series.length > 1 && series.length <= 4) {
        for (var i = s.values.length - 1; i >= 0; i--) {
          if (s.values[i] !== null && !isNaN(s.values[i])) {
            var tag = el("text", {
              x: xAt(i) + 7, y: yAt(s.values[i]) + 3,
              class: "value-label", "text-anchor": "start"
            });
            tag.textContent = s.name;
            svg.appendChild(tag);
            break;
          }
        }
      }
    });

    host.appendChild(svg);

    var tip = makeTip(host);
    var cross = el("line", { class: "baseline", y1: padT, y2: padT + plotH, x1: -99, x2: -99 });
    svg.appendChild(cross);

    var hit = el("rect", { x: padL, y: padT, width: plotW, height: plotH, fill: "transparent" });
    svg.appendChild(hit);

    function onMove(ev) {
      var box = svg.getBoundingClientRect();
      var px = (ev.clientX - box.left) / box.width * w;
      var i = Math.round(((px - padL) / plotW) * (labels.length - 1));
      i = Math.max(0, Math.min(labels.length - 1, i));

      cross.setAttribute("x1", xAt(i));
      cross.setAttribute("x2", xAt(i));

      var parts = series.map(function (s) {
        return (series.length > 1 ? s.name + " " : "") + fmt(s.values[i], data.format) + (data.unit ? " " + data.unit : "");
      }).join(" · ");

      var hostBox = host.getBoundingClientRect();
      showTip(tip, host, ev.clientX - hostBox.left, ev.clientY - hostBox.top, labels[i], parts);
    }

    hit.addEventListener("mousemove", onMove);
    hit.addEventListener("mouseleave", function () {
      hideTip(tip);
      cross.setAttribute("x1", -99);
      cross.setAttribute("x2", -99);
    });

    attachLegend(host, series.map(function (s) { return s.name; }));
    attachTable(
      host,
      [data.xLabel || "When"].concat(series.map(function (s) { return s.name; })),
      labels.map(function (label, i) {
        return [label].concat(series.map(function (s) { return fmt(s.values[i], data.format); }));
      })
    );
  }

  /* --------------------------------------------------------------- bars --- */

  /* Magnitude, ranked. Horizontal because category names are words, and one hue
   * darkening with value — this is magnitude, not identity. */
  function barChart(host, data) {
    var items = (data.items || []).filter(function (d) { return d.value !== null; });
    if (!items.length) return;

    var rowH = 30, padL = 0, padR = 0, padT = 4;
    var w = 640;
    var h = padT + items.length * rowH + 4;
    var labelW = 150, valueW = 74;
    var barMax = w - labelW - valueW - 12;
    var max = Math.max.apply(null, items.map(function (d) { return d.value; })) || 1;

    var svg = el("svg", { viewBox: "0 0 " + w + " " + h, role: "img" });
    svg.appendChild(el("title", {})).textContent = data.title || "Breakdown";

    var ramp = ["var(--seq-700)", "var(--seq-550)", "var(--seq-400)", "var(--seq-250)", "var(--seq-100)"];
    var tip = makeTip(host);

    items.forEach(function (d, i) {
      var y = padT + i * rowH;
      // More is darker. Rank maps onto the ramp, five steps at most.
      var shade = ramp[Math.min(ramp.length - 1, Math.floor((1 - d.value / max) * ramp.length))];
      var barW = Math.max(2, (d.value / max) * barMax);

      var name = el("text", { x: 0, y: y + rowH / 2 + 4, class: "axis-text", "text-anchor": "start" });
      name.textContent = d.label;
      svg.appendChild(name);

      // 4px rounded data-end, anchored flat to the baseline.
      var bar = el("rect", {
        x: labelW, y: y + 6, width: barW, height: rowH - 14,
        rx: 4, fill: shade
      });
      svg.appendChild(bar);

      var value = el("text", {
        x: labelW + barW + 8, y: y + rowH / 2 + 4,
        class: "value-label", "text-anchor": "start"
      });
      value.textContent = fmt(d.value, data.format);
      svg.appendChild(value);

      // Hit target spans the whole row, not just the bar.
      var hit = el("rect", { x: 0, y: y, width: w, height: rowH, fill: "transparent" });
      hit.addEventListener("mousemove", function (ev) {
        var box = host.getBoundingClientRect();
        showTip(tip, host, ev.clientX - box.left, ev.clientY - box.top,
          d.label, fmt(d.value, data.format) + (d.note ? " · " + d.note : ""));
      });
      hit.addEventListener("mouseleave", function () { hideTip(tip); });
      svg.appendChild(hit);
    });

    host.appendChild(svg);
    attachTable(host, [data.xLabel || "Category", data.valueLabel || "Amount"],
      items.map(function (d) { return [d.label, fmt(d.value, data.format)]; }));
  }

  /* ------------------------------------------------------------ grouped --- */

  /* Two named series side by side — identity, so categorical colour. */
  function groupedChart(host, data) {
    var labels = data.labels || [];
    var series = data.series || [];
    if (!labels.length || !series.length) return;

    var w = 640, h = 230;
    var padL = 56, padR = 14, padT = 14, padB = 30;
    var plotW = w - padL - padR, plotH = h - padT - padB;

    var all = [];
    series.forEach(function (s) { s.values.forEach(function (v) { all.push(v || 0); }); });
    var bounds = niceBounds(0, Math.max.apply(null, all) || 1);

    var svg = el("svg", { viewBox: "0 0 " + w + " " + h, role: "img" });
    svg.appendChild(el("title", {})).textContent = data.title || "Comparison";

    function yAt(v) { return padT + plotH - ((v - bounds.min) / (bounds.max - bounds.min)) * plotH; }

    ticks(bounds).forEach(function (t) {
      var y = yAt(t);
      svg.appendChild(el("line", { x1: padL, y1: y, x2: w - padR, y2: y, class: "grid-line" }));
      var text = el("text", { x: padL - 8, y: y + 3, "text-anchor": "end", class: "axis-text" });
      text.textContent = fmt(t, data.format === "money" ? "money0" : data.format);
      svg.appendChild(text);
    });

    var groupW = plotW / labels.length;
    // 2px of surface between adjacent bars, per the mark spec.
    var barW = Math.min(28, (groupW - 10) / series.length - 2);
    var tip = makeTip(host);

    labels.forEach(function (label, gi) {
      var groupX = padL + gi * groupW;

      series.forEach(function (s, si) {
        var v = s.values[gi] || 0;
        var x = groupX + (groupW - (barW + 2) * series.length) / 2 + si * (barW + 2);
        var y = yAt(v);
        var barH = Math.max(1, padT + plotH - y);

        var bar = el("rect", {
          x: x, y: y, width: barW, height: barH, rx: 4,
          fill: seriesColor(si)
        });
        svg.appendChild(bar);

        var hit = el("rect", { x: x - 1, y: padT, width: barW + 2, height: plotH, fill: "transparent" });
        hit.addEventListener("mousemove", function (ev) {
          var box = host.getBoundingClientRect();
          showTip(tip, host, ev.clientX - box.left, ev.clientY - box.top,
            label + " · " + s.name, fmt(v, data.format));
        });
        hit.addEventListener("mouseleave", function () { hideTip(tip); });
        svg.appendChild(hit);
      });

      var text = el("text", { x: groupX + groupW / 2, y: h - 9, "text-anchor": "middle", class: "axis-text" });
      text.textContent = label;
      svg.appendChild(text);
    });

    host.appendChild(svg);
    attachLegend(host, series.map(function (s) { return s.name; }));
    attachTable(
      host,
      [data.xLabel || "Month"].concat(series.map(function (s) { return s.name; })),
      labels.map(function (label, i) {
        return [label].concat(series.map(function (s) { return fmt(s.values[i], data.format); }));
      })
    );
  }

  /* ---------------------------------------------------------------- init -- */

  function render() {
    document.querySelectorAll("[data-spark]").forEach(sparkline);

    document.querySelectorAll("[data-chart]").forEach(function (host) {
      var data = readJSON(host.getAttribute("data-source"));
      if (!data) return;

      switch (host.getAttribute("data-chart")) {
        case "line":    lineChart(host, data); break;
        case "bars":    barChart(host, data); break;
        case "grouped": groupedChart(host, data); break;
      }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", render);
  } else {
    render();
  }
})();
