<?php
/**
 * People — everyone on file and where they are.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();
require_login();

$people = all_people();

page_head('People', 'people');
page_header('people', 'People', count($people) . ' on file');
?>

<section>
  <?php if (!$people): ?>
    <div class="card"><?php empty_line('Nobody on file yet.'); ?></div>
  <?php else: ?>
    <?php foreach ($people as $p): ?>
      <a class="card" href="person.php?id=<?= (int) $p['id'] ?>">
        <div class="card-row">
          <p class="who">
            <?= h($p['name']) ?>
            <?= $p['is_self'] ? tag('confirmed', 'you') : '' ?>
          </p>
          <?php if ($p['relationship'] && !$p['is_self']): ?>
            <p class="mono"><?= h($p['relationship']) ?></p>
          <?php endif; ?>
        </div>
        <p class="where">
          <?= h(trim(($p['city'] ?? '') . ($p['region'] ? ', ' . $p['region'] : '')) ?: 'Location unknown') ?>
          <?= tag($p['location_confidence']) ?>
        </p>
      </a>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php page_footer(); ?>
