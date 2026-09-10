<?php
/**
 * Now — the home tab.
 *
 * The question this page answers is "what is going on right now": where he is,
 * whether that is a shop with something on the list, what is left today, and
 * whether anything in health or money needs attention before the day moves on.
 * Everything below the fold is detail; the top of the page is the answer.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();
require_login();

$today = date('Y-m-d');
$day   = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['date'])
    ? (string) $_GET['date']
    : $today;

$isToday  = $day === $today;
$me       = self_person();
$location = location_summary();
$brief    = day_brief($day);
$events   = day_events($day);

// Errands: everything open, plus the subset that belongs to the shop he is
// standing in — that second list is the whole point of the location feed.
$errands      = open_shopping();
$storeErrands = ($location && $location['in_store'] && $location['store_tag'])
    ? open_shopping($location['store_tag'])
    : [];

$bills    = bills_due(10);
$refills  = $me ? refills_due((int) $me['id'], 14) : [];
$appts    = upcoming_appointments(4);
$worth    = net_worth();

// Spending so far this month, against the same stretch of last month, so the
// comparison is like for like rather than a full month against a partial one.
$dayOfMonth  = (int) date('j');
$spentThis   = (float) (qv(
    'SELECT COALESCE(SUM(-amount), 0) FROM transactions WHERE amount < 0 AND on_day >= date("now", "start of month")'
) ?? 0);
$spentLast   = (float) (qv(
    'SELECT COALESCE(SUM(-amount), 0) FROM transactions
      WHERE amount < 0
        AND on_day >= date("now", "start of month", "-1 month")
        AND on_day <= date("now", "start of month", "-1 month", ?)',
    ['+' . ($dayOfMonth - 1) . ' days']
) ?? 0);

$worthSeries = net_worth_series(12);
$worthSpark  = array_map(static fn(array $r): float => (float) $r['value'], $worthSeries);

$greeting = (int) date('G') < 12 ? 'Good morning' : ((int) date('G') < 18 ? 'Good afternoon' : 'Good evening');

page_head('Now', 'index');
page_header(
    'index',
    h($greeting) . ', <span>' . h(cfg('owner_name', 'Chris')) . '</span>',
    h(long_day($day)) . ($isToday ? '' : ' <span class="tag stale">not today</span>')
);
?>

<?php /* ------------------------------------------------------ where I am -- */ ?>
<section>
  <?php eyebrow('Right now'); ?>
  <h2>Where you are</h2>

  <?php if ($location === null): ?>
    <div class="card">
      <?php empty_line('No location has ever been received.'); ?>
      <p class="line">Point a shortcut, Owntracks or a SmartThings presence sensor at
        <span class="mono">/api/location.php</span> and this fills in. Setup is in the README.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <div class="card-row">
        <p class="who">
          <?php if ($location['place']): ?>
            <?= $location['transition'] === 'depart' ? 'Left ' : 'At ' ?><?= h($location['place']) ?>
          <?php else: ?>
            Somewhere unrecognised
          <?php endif; ?>
          <?= tag($location['confidence']) ?>
        </p>
        <p class="mono"><?= h(ago($location['at'])) ?></p>
      </div>

      <?php if ($location['address']): ?>
        <p class="where"><?= h($location['address']) ?></p>
      <?php endif; ?>

      <?php if (!$location['place'] && $location['lat'] !== null): ?>
        <p class="where mono"><?= h(number_format((float) $location['lat'], 4)) ?>,
           <?= h(number_format((float) $location['lon'], 4)) ?> — no known place within range</p>
      <?php endif; ?>

      <?php if ($location['confidence'] === 'stale'): ?>
        <p class="line empty">This fix is old, so treat it as a last known position rather than where you are.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php /* Standing in a shop with something on the list for it. */ ?>
  <?php if ($storeErrands): ?>
    <div class="notice warn">
      <p><strong>You're at <?= h($location['place']) ?> and there
        <?= count($storeErrands) === 1 ? 'is 1 thing' : 'are ' . count($storeErrands) . ' things' ?>
        on the list for here.</strong></p>
    </div>

    <?php foreach ($storeErrands as $item): ?>
      <div class="card<?= $item['urgent'] ? ' urgent' : '' ?>" data-row>
        <div class="card-row">
          <p class="who">
            <?= h($item['item']) ?>
            <?php if ($item['qty']): ?><span class="mono"> · <?= h($item['qty']) ?></span><?php endif; ?>
            <?= $item['urgent'] ? tag('urgent', 'urgent') : '' ?>
          </p>
          <form method="post" action="api/shopping.php" data-item="<?= (int) $item['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <input type="hidden" name="action" value="bought">
            <button type="submit" class="btn btn-ghost btn-small">Got it</button>
          </form>
        </div>
        <?php if ($item['note']): ?><p class="where"><?= h($item['note']) ?></p><?php endif; ?>
      </div>
    <?php endforeach; ?>

  <?php elseif ($location && $location['in_store']): ?>
    <div class="notice info">
      <p>You're at <?= h($location['place']) ?> and nothing on the list is tagged for here.</p>
    </div>
  <?php endif; ?>
</section>

<?php /* --------------------------------------------------- the numbers --- */ ?>
<section>
  <?php eyebrow('At a glance'); ?>
  <h2>The numbers</h2>

  <div class="stats">
    <?php
    // A single current value is a stat tile, not a one-bar chart. The sparkline
    // carries the trend without spending a whole chart on it.
    stat_tile(
        'Net worth',
        money0($worth),
        count($worthSeries) > 1 ? 'over ' . count($worthSeries) . ' months' : null,
        count($worthSpark) > 1 ? $worthSpark : null
    );

    $delta     = $spentThis - $spentLast;
    $deltaText = $spentLast > 0
        ? money0(abs($delta)) . ($delta >= 0 ? ' more' : ' less') . ' than by this point last month'
        : 'no comparison for last month yet';
    stat_tile('Spent this month', money0($spentThis), h($deltaText), null,
        ($spentLast > 0 && $delta > 0) ? 'bad' : '');

    stat_tile(
        'Errands open',
        (string) count($errands),
        count($errands) ? h(count(array_filter($errands, static fn($i) => (bool) $i['urgent'])) . ' urgent') : 'list is clear'
    );

    $nextAppt = $appts[0] ?? null;
    stat_tile(
        'Next appointment',
        $nextAppt ? due_phrase($nextAppt['on_day']) : '—',
        $nextAppt ? h($nextAppt['what'] . ' · ' . $nextAppt['person_name']) : 'nothing scheduled'
    );
    ?>
  </div>

  <?php if ($bills || $refills): ?>
    <div class="notice warn">
      <?php if ($bills): ?>
        <p><strong>Due soon:</strong>
          <?= h(implode(', ', array_map(
              static fn(array $b): string => $b['name'] . ' (' . due_phrase($b['next_due']) . ')',
              $bills
          ))) ?></p>
      <?php endif; ?>
      <?php if ($refills): ?>
        <p><strong>Refills:</strong>
          <?= h(implode(', ', array_map(
              static fn(array $m): string => $m['name'] . ' (' . due_phrase($m['refill_due']) . ')',
              $refills
          ))) ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</section>

<?php /* --------------------------------------------------------- today --- */ ?>
<section>
  <?php eyebrow($isToday ? 'Today' : 'That day'); ?>
  <h2><?= $isToday ? 'What&rsquo;s on' : 'What was on' ?></h2>

  <?php if ($brief && trim((string) $brief['brief']) !== ''): ?>
    <div class="card">
      <p class="line"><?= nl2br(h($brief['brief'])) ?></p>
      <?php if ($brief['written_at']): ?>
        <p class="where mono">written <?= h(ago($brief['written_at'])) ?></p>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="card"><?php empty_line('No brief has been written for this day yet.'); ?></div>
  <?php endif; ?>

  <?php if ($events): ?>
    <?php foreach ($events as $event): ?>
      <div class="card">
        <div class="card-row">
          <p class="who"><?= h($event['title']) ?></p>
          <p class="mono"><?= h(clock($event['starts_at'])) ?></p>
        </div>
        <p class="where">
          <?= h($event['location'] ?? 'No location') ?>
          <?php if ($event['person_name']): ?> · with <?= h($event['person_name']) ?><?php endif; ?>
          <?= tag('stale', $event['kind']) ?>
        </p>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="card"><?php empty_line('Nothing on the calendar for this day.'); ?></div>
  <?php endif; ?>
</section>

<?php /* -------------------------------------------------------- errands --- */ ?>
<?php if ($errands): ?>
<section>
  <?php eyebrow('Errands'); ?>
  <h2>Things to buy</h2>

  <div class="scroll">
    <table>
      <thead>
        <tr><th>Item</th><th>Qty</th><th>Where</th><th>Added</th></tr>
      </thead>
      <tbody>
        <?php foreach (array_slice($errands, 0, 8) as $item): ?>
          <tr>
            <td><?= h($item['item']) ?><?= $item['urgent'] ? tag('urgent', 'urgent') : '' ?></td>
            <td class="mono"><?= h($item['qty'] ?? '—') ?></td>
            <td><?= h($item['store_tag'] ?? 'anywhere') ?></td>
            <td class="mono"><?= h(ago($item['added_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (count($errands) > 8): ?>
    <p class="hint spaced"><a href="errands.php">All <?= count($errands) ?> open errands →</a></p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php /* ---------------------------------------------------- net worth ---- */ ?>
<?php if (count($worthSeries) > 1): ?>
<section>
  <?php eyebrow('Money'); ?>
  <h2>Net worth over time</h2>

  <div class="chart-card">
    <div class="chart-head">
      <p class="chart-title">Assets minus what you owe</p>
      <p class="chart-note">month end · <?= count($worthSeries) ?> months</p>
    </div>
    <div class="chart" data-chart="line" data-source="worth-data"></div>
  </div>

  <?= json_block('worth-data', [
      'title'   => 'Net worth',
      'format'  => 'money0',
      'xLabel'  => 'Month',
      'labels'  => array_map(static fn(array $r): string => date('M y', strtotime($r['month'] . '-01')), $worthSeries),
      'series'  => [[
          'name'   => 'Net worth',
          'values' => array_map(static fn(array $r): float => (float) $r['value'], $worthSeries),
      ]],
  ]) ?>
</section>
<?php endif; ?>

<?php /* --------------------------------------------------------- people --- */ ?>
<section>
  <?php eyebrow('People'); ?>
  <h2>Who&rsquo;s where</h2>

  <?php
  $others = array_slice(array_filter(all_people(), static fn(array $p): bool => !$p['is_self']), 0, 6);
  ?>

  <?php if ($others): ?>
    <?php foreach ($others as $p): ?>
      <a class="card" href="person.php?id=<?= (int) $p['id'] ?>">
        <p class="who"><?= h($p['name']) ?></p>
        <p class="where">
          <?= h(trim(($p['city'] ?? '') . ($p['region'] ? ', ' . $p['region'] : '')) ?: 'Location unknown') ?>
          <?= tag($p['location_confidence']) ?>
        </p>
      </a>
    <?php endforeach; ?>
    <p class="hint spaced"><a href="people.php">Everyone →</a></p>
  <?php else: ?>
    <div class="card"><?php empty_line('Nobody on file yet.'); ?></div>
  <?php endif; ?>
</section>

<p class="hint">
  <a href="index.php?date=<?= h(date('Y-m-d', strtotime($day . ' -1 day'))) ?>">&larr; <?= h(date('M j', strtotime($day . ' -1 day'))) ?></a>
  &nbsp;·&nbsp;
  <a href="index.php?date=<?= h(date('Y-m-d', strtotime($day . ' +1 day'))) ?>"><?= h(date('M j', strtotime($day . ' +1 day'))) ?> &rarr;</a>
  <?php if (!$isToday): ?>&nbsp;·&nbsp;<a href="index.php">Back to today</a><?php endif; ?>
</p>

<?php page_footer(); ?>
