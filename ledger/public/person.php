<?php
/**
 * One person — everything on file, plus what changed and when.
 *
 * Fields flagged sensitive arrive masked and are revealed one at a time, so the
 * page can be open in front of someone without spilling an account number.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();
require_login();

$id      = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$subject = $id ? person($id) : null;

if ($subject === null) {
    http_response_code(404);
    page_head('Not found', 'people');
    page_header('people', 'Not found', 'No such person');
    echo '<div class="card"><p class="line">There is nobody on file with that id. <a href="people.php">Back to People</a>.</p></div>';
    page_footer();
    exit;
}

$fields = q(
    'SELECT * FROM person_fields WHERE person_id = ? ORDER BY section, field COLLATE NOCASE',
    [$id]
);

$bySection = [];
foreach ($fields as $f) {
    $bySection[$f['section']][] = $f;
}

$changes = q(
    'SELECT * FROM changes WHERE person_id = ? ORDER BY at DESC, id DESC LIMIT 60',
    [$id]
);

$meds  = active_medications($id);
$appts = q(
    'SELECT * FROM appointments WHERE person_id = ? AND on_day >= date("now") ORDER BY on_day LIMIT 5',
    [$id]
);

page_head($subject['name'], 'people');
page_header(
    'people',
    h($subject['name']),
    h(trim(($subject['city'] ?? '') . ($subject['region'] ? ', ' . $subject['region'] : '')) ?: 'Location unknown')
        . tag($subject['location_confidence'])
);
?>

<section>
  <?php eyebrow('Everything on file'); ?>
  <h2>Record</h2>

  <?php if (!$bySection): ?>
    <div class="card"><?php empty_line('Nothing recorded beyond the name.'); ?></div>
  <?php else: ?>
    <?php foreach ($bySection as $section => $rows): ?>
      <h3><?= h($section) ?></h3>
      <div class="scroll">
        <table>
          <tbody>
            <?php foreach ($rows as $f): ?>
              <tr>
                <td class="field"><?= h($f['field']) ?></td>
                <td>
                  <?php if ($f['sensitive']): ?>
                    <span data-sensitive data-value="<?= h($f['value'] ?? '') ?>"></span>
                  <?php else: ?>
                    <?= h($f['value'] ?? '—') ?>
                  <?php endif; ?>
                </td>
                <td class="mono"><?= h($f['source'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php if ($meds || $appts): ?>
<section>
  <?php eyebrow('Health'); ?>
  <h2>Medications and appointments</h2>

  <?php foreach ($meds as $m): ?>
    <div class="card">
      <div class="card-row">
        <p class="who"><?= h($m['name']) ?></p>
        <p class="mono"><?= h($m['dose'] ?? '') ?></p>
      </div>
      <p class="where"><?= h($m['schedule'] ?? '—') ?><?php if ($m['purpose']): ?> · <?= h($m['purpose']) ?><?php endif; ?></p>
    </div>
  <?php endforeach; ?>

  <?php foreach ($appts as $a): ?>
    <div class="card">
      <div class="card-row">
        <p class="who"><?= h($a['what']) ?></p>
        <p class="mono"><?= h(due_phrase($a['on_day'])) ?></p>
      </div>
      <p class="where"><?= h($a['provider'] ?? '') ?><?php if ($a['place']): ?> · <?= h($a['place']) ?><?php endif; ?></p>
    </div>
  <?php endforeach; ?>

  <p class="hint"><a href="health.php?person=<?= $id ?>">Full health record →</a></p>
</section>
<?php endif; ?>

<section>
  <?php eyebrow('History'); ?>
  <h2>Changes to this record</h2>

  <?php if (!$changes): ?>
    <div class="card"><?php empty_line('No changes recorded.'); ?></div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead><tr><th>When</th><th>Field</th><th>Before</th><th>After</th><th>Source</th></tr></thead>
        <tbody>
          <?php foreach ($changes as $c): ?>
            <tr>
              <td class="mono"><?= h(ago($c['at'])) ?></td>
              <td><?= h($c['field'] ?? $c['entity']) ?></td>
              <td class="old"><?= h($c['before_val'] ?? '') ?></td>
              <td class="new"><?= h($c['after_val'] ?? '') ?></td>
              <td class="mono"><?= h($c['source'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php page_footer(); ?>
