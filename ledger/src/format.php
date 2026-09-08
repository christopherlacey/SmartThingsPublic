<?php
/**
 * Display helpers. Everything user-visible goes through h().
 */

declare(strict_types=1);

/** Escape for HTML. */
function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Money, always with a sign that reads at a glance. */
function money(?float $n, bool $signed = false): string
{
    if ($n === null) {
        return '—';
    }
    $sign = '';
    if ($signed) {
        $sign = $n > 0 ? '+' : ($n < 0 ? '−' : '');
    } elseif ($n < 0) {
        $sign = '−';
    }
    return $sign . '$' . number_format(abs($n), 2);
}

/** Money rounded to the dollar, for headline figures. */
function money0(?float $n): string
{
    return $n === null ? '—' : ($n < 0 ? '−' : '') . '$' . number_format(abs($n), 0);
}

/** "3 minutes ago", "yesterday", "12 days ago". */
function ago(?string $ts): string
{
    if (!$ts) {
        return 'never';
    }
    $then = strtotime($ts . ' UTC') ?: strtotime($ts);
    if ($then === false) {
        return 'unknown';
    }

    $secs = time() - $then;
    if ($secs < 0)      return 'just now';
    if ($secs < 60)     return 'just now';
    if ($secs < 3600)   return floor($secs / 60) . ' min ago';
    if ($secs < 86400)  return floor($secs / 3600) . ' hr ago';
    if ($secs < 172800) return 'yesterday';
    if ($secs < 2592000) return floor($secs / 86400) . ' days ago';
    return date('M j, Y', $then);
}

/** A day string as "Monday, September 7, 2026". */
function long_day(string $day): string
{
    $t = strtotime($day);
    return $t === false ? $day : date('l, F j, Y', $t);
}

/** "9:30 AM" from "09:30". */
function clock(?string $hhmm): string
{
    if (!$hhmm) {
        return 'All day';
    }
    $t = strtotime($hhmm);
    return $t === false ? $hhmm : date('g:i A', $t);
}

/** Whole days from today until $day. Negative means past. */
function days_until(?string $day): ?int
{
    if (!$day) {
        return null;
    }
    $t = strtotime($day);
    if ($t === false) {
        return null;
    }
    return (int) floor(($t - strtotime('today')) / 86400);
}

/** "in 3 days" / "3 days overdue" / "today". */
function due_phrase(?string $day): string
{
    $d = days_until($day);
    if ($d === null) return '—';
    if ($d === 0)    return 'today';
    if ($d === 1)    return 'tomorrow';
    if ($d > 1)      return "in $d days";
    if ($d === -1)   return '1 day overdue';
    return abs($d) . ' days overdue';
}

/** Round-trip a value into a JSON block the page can read but never execute. */
function json_block(string $id, mixed $data): string
{
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return '<script type="application/json" id="' . h($id) . '">' . $json . '</script>';
}
