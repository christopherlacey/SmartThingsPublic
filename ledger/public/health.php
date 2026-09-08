<?php
/**
 * Health.
 *
 * Medications, readings, appointments and conditions — for everyone on file,
 * not just Chris, because the person who needs this page in a hurry is usually
 * looking something up about someone else.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();
require_login();

$me = self_person();
$people = all_people();

$personId = isset($_GET['person']) ? (int) $_GET['person'] : (int) ($me['id'] ?? 0);
$subject  = $personId ? person($personId) : null;

if ($subject === null && $people) {
    $subject  = $people[0];
    $personId = (int) $subject['id'];
}

$meds       = $subject ? active_medications($personId) : [];
$refills    = $subject ? refills_due($personId, 30) : [];
$metrics    = $subject ? tracked_metrics($personId) : [];
$appts      = upcoming_appointments(10);
$conditions = $subject
    ? q('SELECT * FROM conditions WHERE person_id = ? AND resolved_on IS NULL ORDER BY kind, name', [$personId])
    : [];

// Which metric to plot. Default to the one with the most readings so the page
// opens on something worth looking at.
$metricNames = array_column($metrics, 'metric');
$metric = isset($_GET['metric']) && in_array($_GET['metric'], $metricNames, true)
    ? (string) $_GET['metric']
    : ($metricNames[0] ?? null);

$series = ($subject && $metric) ? vitals_series($personId, $metric, 60) : [];

page_head('Health', 'health');
page_header('health', 'Health', $subject ? h($subject['name']) : 'Nobody on file');
?>

<?php if (count($people) > 1): ?>
<section>
  <form method="get" action="health.php" class="stack">
    <div class="field">
      <label for="person">Whose records</label>
      <select id="person" name="person" onchange="this.form.submit()">
        <?php foreach ($people as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= (int) $p['id'] === $personId ? ' selected' : '' ?>>
            <?= h($p['name']) ?><?= $p['is_self'] ? ' (you)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button type="submit" class="btn btn-ghost btn-small">Show</button></noscript>
  </form>
</section>
<?php endif; ?>

<?php if ($subject === null): ?>
  <div class="card"><?php empty_line('No people on file yet.'); ?></div>
<?php else: ?>

<?php /* ------------------------------------------------------- headline --- */ ?>
<section>
  <?php eyebrow('At a glance'); ?>
  <h2><?= h($subject['name']) ?></h2>

  <div class="stats">
    <?php
    stat_tile('Medications', (string) count($meds), count($meds) ? 'currently taking' : 'none recorded');
    stat_tile('Refills due', (string) count($refills),
        $refills ? h($refills[0]['name'] . ' ' . due_phrase($refills[0]['refill_due'])) : 'nothing in 30 days',
        null, $refills ? 'bad' : '');

    $mine = array_values(array_filter($appts, static fn(array $a): bool => (int) $a['person_id'] === $personId));
    stat_tile('Next appointment', $mine ? due_phrase($mine[0]['on_day']) : '—',
        $mine ? h($mine[0]['what']) : 'nothing scheduled');
    stat_tile('Tracked', (string) count($metrics), count($metrics) ? 'metrics with readings' : 'nothing measured yet');
    ?>
  </div>
</section>

<?php /* -------------------------------------------------------- readings -- */ ?>
<section>
  <?php eyebrow('Readings'); ?>
  <h2>Measurements over time</h2>

  <?php if (!$metrics): ?>
    <div class="card"><?php empty_line('No readings recorded yet.'); ?></div>
  <?php else: ?>

    <?php if (count($metrics) > 1): ?>
      <p class="row-actions spaced-below">
        <?php foreach ($metrics as $m): ?>
          <a class="btn btn-ghost btn-small"
             href="health.php?person=<?= $personId ?>&amp;metric=<?= h(urlencode($m['metric'])) ?>"
             <?= $m['metric'] === $metric ? 'aria-current="true"' : '' ?>>
            <?= h($m['metric']) ?>
          </a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>

    <?php if (count($series) > 1): ?>
      <?php $unit = $series[0]['unit'] ?? ''; ?>
      <div class="chart-card">
        <div class="chart-head">
          <p class="chart-title"><?= h($metric) ?><?= $unit ? ' (' . h($unit) . ')' : '' ?></p>
          <p class="chart-note"><?= count($series) ?> readings · latest <?= h(ago($series[count($series) - 1]['measured_on'])) ?></p>
        </div>
        <div class="chart" data-chart="line" data-source="vitals-data"></div>
      </div>

      <?= json_block('vitals-data', [
          'title'  => $metric,
          'unit'   => $unit,
          'format' => 'number',
          'xLabel' => 'Date',
          'labels' => array_map(
              static fn(array $r): string => date('M j', strtotime($r['measured_on'])),
              $series
          ),
          'series' => [[
              'name'   => $metric,
              'values' => array_map(static fn(array $r): float => (float) $r['value'], $series),
          ]],
      ]) ?>
    <?php elseif (count($series) === 1): ?>
      <div class="card">
        <p class="line"><?= h($metric) ?>: <strong><?= h((string) $series[0]['value']) ?>
          <?= h((string) ($series[0]['unit'] ?? '')) ?></strong> on <?= h($series[0]['measured_on']) ?></p>
        <p class="where">One reading is a number, not a trend — a line appears once there are two.</p>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php /* ----------------------------------------------------- medications -- */ ?>
<section>
  <?php eyebrow('Medications'); ?>
  <h2>Currently taking</h2>

  <?php if (!$meds): ?>
    <div class="card"><?php empty_line('No medications recorded.'); ?></div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Medication</th><th>Dose</th><th>When</th><th>For</th><th>Refill</th></tr>
        </thead>
        <tbody>
          <?php foreach ($meds as $m): ?>
            <?php $due = days_until($m['refill_due']); ?>
            <tr>
              <td><?= h($m['name']) ?></td>
              <td class="mono"><?= h($m['dose'] ?? '—') ?></td>
              <td><?= h($m['schedule'] ?? '—') ?></td>
              <td><?= h($m['purpose'] ?? '—') ?></td>
              <td>
                <?php if ($m['refill_due']): ?>
                  <?= h(due_phrase($m['refill_due'])) ?>
                  <?= ($due !== null && $due <= 7) ? tag('urgent', 'soon') : '' ?>
                <?php else: ?>—<?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php /* ------------------------------------------------------ conditions -- */ ?>
<section>
  <?php eyebrow('On file'); ?>
  <h2>Conditions, allergies and history</h2>

  <?php if (!$conditions): ?>
    <div class="card"><?php empty_line('Nothing recorded.'); ?></div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead><tr><th>What</th><th>Type</th><th>Detail</th><th>Since</th></tr></thead>
        <tbody>
          <?php foreach ($conditions as $c): ?>
            <tr>
              <td><?= h($c['name']) ?>
                  <?= $c['kind'] === 'allergy' ? tag('urgent', 'allergy') : '' ?></td>
              <td><?= h($c['kind']) ?></td>
              <td><?= h($c['detail'] ?? '—') ?></td>
              <td class="mono"><?= h($c['noted_on'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php /* ---------------------------------------------------- appointments -- */ ?>
<section>
  <?php eyebrow('Coming up'); ?>
  <h2>Appointments</h2>

  <?php if (!$appts): ?>
    <div class="card"><?php empty_line('Nothing scheduled.'); ?></div>
  <?php else: ?>
    <?php foreach ($appts as $a): ?>
      <div class="card">
        <div class="card-row">
          <p class="who"><?= h($a['what']) ?></p>
          <p class="mono"><?= h(due_phrase($a['on_day'])) ?></p>
        </div>
        <p class="where">
          <?= h($a['person_name']) ?>
          <?php if ($a['provider']): ?> · <?= h($a['provider']) ?><?php endif; ?>
          <?php if ($a['place']): ?> · <?= h($a['place']) ?><?php endif; ?>
          <?php if ($a['at_time']): ?> · <?= h(clock($a['at_time'])) ?><?php endif; ?>
        </p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php endif; ?>

<?php page_footer(); ?>
