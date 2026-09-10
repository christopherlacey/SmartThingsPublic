<?php
/**
 * The Lacey Ledger — server-rendered SVG charts.
 *
 * No chart library, no JavaScript, no external requests: the server does the
 * arithmetic and emits SVG. That keeps a private page private (nothing about
 * the household's spending is handed to a CDN) and it renders instantly.
 *
 * Colour carries no meaning here on purpose. A red/green spend/income pair
 * fails colour-vision separation in light mode (deutan ΔE 4.5, well under the
 * 8 needed), so direction is shown by SIGN, POSITION and LABEL, and the bars
 * themselves use one sequential hue.
 */

declare(strict_types=1);

/** Money in cents -> "$1,234.56", with the sign kept when asked. */
function money(int $cents, bool $signed = false, string $symbol = '$'): string
{
    $sign = '';
    if ($signed) {
        $sign = $cents < 0 ? "\u{2212}" : ($cents > 0 ? '+' : '');
    }
    return $sign . $symbol . number_format(abs($cents) / 100, 2);
}

/** Compact money for axis labels: $1.2k, $840. */
function money_short(int $cents, string $symbol = '$'): string
{
    $v = abs($cents) / 100;
    if ($v >= 1000) {
        return $symbol . rtrim(rtrim(number_format($v / 1000, 1), '0'), '.') . 'k';
    }
    return $symbol . number_format($v, 0);
}

/**
 * A bar with only its top two corners rounded, anchored to the baseline.
 * Radius collapses on short bars so a 3px bar never looks like a lozenge.
 */
function bar_path(float $x, float $y, float $w, float $h, float $r = 4.0): string
{
    $r = min($r, $w / 2, max($h, 0.01));
    $x2 = $x + $w;
    $y2 = $y + $h;
    return sprintf(
        'M%.2f %.2f V%.2f Q%.2f %.2f %.2f %.2f H%.2f Q%.2f %.2f %.2f %.2f V%.2f Z',
        $x, $y2,
        $y + $r,
        $x, $y, $x + $r, $y,
        $x2 - $r,
        $x2, $y, $x2, $y + $r,
        $y2
    );
}

/**
 * Daily columns over a date range.
 *
 * @param array $days  ordered list of ['date' => 'YYYY-MM-DD', 'cents' => int]
 *                     where cents is money OUT for that day (>= 0).
 */
function ledger_day_chart(array $days, string $currency = '$'): string
{
    if (!$days) {
        return '<p class="line empty">No days with activity in this window.</p>';
    }

    $max = 0;
    foreach ($days as $d) {
        $max = max($max, (int) $d['cents']);
    }
    if ($max <= 0) {
        $max = 1;
    }

    // Round the top of the scale up to something a person would say out loud.
    $step = 10 ** max(0, strlen((string) intdiv($max, 100)) - 1) * 100;
    $top  = (int) (ceil($max / $step) * $step);

    // The plot stretches to fill the width, so only geometry lives in the SVG.
    // Axis labels are HTML alongside it — text inside a non-uniformly scaled
    // SVG gets squashed on narrow screens, which is exactly what we don't want.
    $w = 1000.0;
    $h = 200.0;
    $n = count($days);
    $slot = $w / $n;
    $bw = max(1.5, min(24.0, $slot - 2));   // 2px surface gap between bars

    $svg = '<svg class="plot" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" '
         . 'preserveAspectRatio="none" aria-label="Money out per day">';

    foreach ([0.5, 1.0] as $frac) {
        $y = $h - ($h * $frac);
        $svg .= sprintf('<line class="grid" x1="0" y1="%.1f" x2="%.1f" y2="%.1f"/>', $y, $w, $y);
    }

    $i = 0;
    foreach ($days as $d) {
        $cents = max(0, (int) $d['cents']);
        $x     = $i * $slot + ($slot - $bw) / 2;
        $when  = date('D j M', strtotime($d['date']));

        if ($cents > 0) {
            $bh = max(2.0, $h * ($cents / $top));
            $svg .= '<path class="bar" d="' . bar_path($x, $h - $bh, $bw, $bh) . '">'
                  . '<title>' . htmlspecialchars($when . ' — ' . money($cents, false, $currency))
                  . '</title></path>';
        } else {
            // A day with nothing spent is a fact worth seeing, not a gap.
            $svg .= sprintf(
                '<rect class="bar-zero" x="%.2f" y="%.2f" width="%.2f" height="2" rx="1">'
                . '<title>%s</title></rect>',
                $x, $h - 2, $bw, htmlspecialchars($when . ' — nothing spent')
            );
        }
        $i++;
    }

    $svg .= sprintf('<line class="axis-line" x1="0" y1="%.1f" x2="%.1f" y2="%.1f"/>', $h, $w, $h);
    $svg .= '</svg>';

    $first = htmlspecialchars(date('j M', strtotime($days[0]['date'])));
    $last  = htmlspecialchars(date('j M', strtotime(end($days)['date'])));

    return '<div class="chart">'
         . '<div class="chart-y">'
         .   '<span>' . htmlspecialchars(money_short($top, $currency)) . '</span>'
         .   '<span>' . htmlspecialchars(money_short((int) round($top / 2), $currency)) . '</span>'
         .   '<span>' . htmlspecialchars($currency . '0') . '</span>'
         . '</div>'
         . '<div class="chart-plot">' . $svg
         .   '<div class="chart-x"><span>' . $first . '</span><span>' . $last . '</span></div>'
         . '</div>'
         . '</div>';
}

/**
 * Horizontal ranked bars — categories, merchants, anything ordered by size.
 * One hue; rank is carried by order and by the value printed on each row.
 *
 * @param array $rows list of ['label' => string, 'cents' => int]
 */
function ledger_rank_chart(array $rows, string $currency = '$'): string
{
    if (!$rows) {
        return '<p class="line empty">Nothing to rank yet.</p>';
    }

    $max = 1;
    foreach ($rows as $r) {
        $max = max($max, (int) $r['cents']);
    }

    $out = '<div class="ranks">';
    foreach ($rows as $r) {
        $cents = (int) $r['cents'];
        $pct   = max(0.6, ($cents / $max) * 100);
        $out  .= '<div class="rank">'
               . '<div class="rank-label">' . htmlspecialchars((string) $r['label']) . '</div>'
               . '<div class="rank-track">'
               . '<div class="rank-fill" style="width:' . number_format($pct, 2) . '%"></div>'
               . '</div>'
               . '<div class="rank-value mono">' . htmlspecialchars(money($cents, false, $currency)) . '</div>'
               . '</div>';
    }
    return $out . '</div>';
}

/**
 * A sparkline for a stat tile — shape only, no axes.
 *
 * @param int[] $values
 */
function ledger_sparkline(array $values): string
{
    $values = array_values(array_map('intval', $values));
    if (count($values) < 2) {
        return '';
    }

    $w = 120;
    $h = 28;
    $min = min($values);
    $max = max($values);
    $range = ($max - $min) ?: 1;

    $pts = [];
    foreach ($values as $i => $v) {
        $pts[] = sprintf(
            '%.2f,%.2f',
            ($i / (count($values) - 1)) * $w,
            $h - (($v - $min) / $range) * ($h - 4) - 2
        );
    }

    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" aria-hidden="true" '
         . 'preserveAspectRatio="none"><polyline points="' . implode(' ', $pts) . '"/></svg>';
}
