<?php
/**
 * Change log — every write to the ledger, newest first.
 *
 * This is the audit trail for medical and financial fields as much as it is a
 * convenience, so it reads from `changes` and nothing prunes it.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();
require_login();

$changes = recent_changes(300);

// Group by day so the page reads as a diary rather than one long table.
$byDay = [];
foreach ($changes as $c) {
    $byDay[substr($c['at'], 0, 10)][] = $c;
}

page_head('Change log', 'changelog');
page_header('changelog', 'Change log', 'Every write to the ledger, newest first');
?>

<section>
  <?php if (!$changes): ?>
    <div class="card"><?php empty_line('Nothing has been changed yet.'); ?></div>
  <?php else: ?>
    <?php foreach ($byDay as $day => $rows): ?>
      <h3><?= h(long_day($day)) ?></h3>
      <div class="scroll">
        <table>
          <thead>
            <tr><th>Time</th><th>What</th><th>Who</th><th>Field</th><th>Before</th><th>After</th><th>Source</th></tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $c): ?>
              <tr>
                <td class="mono"><?= h(substr($c['at'], 11, 5)) ?></td>
                <td><?= h($c['entity']) ?> <span class="mono"><?= h($c['action']) ?></span></td>
                <td><?= h($c['person_name'] ?? '—') ?></td>
                <td><?= h($c['field'] ?? '—') ?></td>
                <td class="old"><?= h($c['before_val'] ?? '') ?></td>
                <td class="new"><?= h($c['after_val'] ?? '') ?></td>
                <td class="mono"><?= h($c['source'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php page_footer(); ?>
