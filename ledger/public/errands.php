<?php
/**
 * Errands — the shopping list, grouped by where each thing is bought.
 *
 * Items tagged with a store are the ones the Now tab raises when a location
 * ping puts him inside that store.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();
require_login();

$error = null;

// Adding an item is the one write this page does directly.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrf_check();

    $item = trim((string) ($_POST['item'] ?? ''));

    if ($item === '') {
        $error = 'Give the item a name.';
    } else {
        qx(
            'INSERT INTO shopping_items (item, qty, store_tag, category, urgent, note)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                mb_substr($item, 0, 120),
                trim((string) ($_POST['qty'] ?? '')) ?: null,
                trim((string) ($_POST['store_tag'] ?? '')) ?: null,
                trim((string) ($_POST['category'] ?? '')) ?: null,
                isset($_POST['urgent']) ? 1 : 0,
                trim((string) ($_POST['note'] ?? '')) ?: null,
            ]
        );

        log_change('shopping_items', (int) db()->lastInsertId(), 'item', null, $item, 'create');

        // Redirect after a successful write so a reload cannot add it twice.
        header('Location: errands.php?added=1');
        exit;
    }
}

$open   = open_shopping();
$bought = q('SELECT * FROM shopping_items WHERE bought_at IS NOT NULL ORDER BY bought_at DESC LIMIT 12');
$stores = q('SELECT DISTINCT store_tag FROM places WHERE store_tag IS NOT NULL ORDER BY store_tag');

// Group the open list by store so it reads the way a trip is actually run.
$grouped = [];
foreach ($open as $item) {
    $grouped[$item['store_tag'] ?? ''][] = $item;
}
ksort($grouped);

page_head('Errands', 'errands');
page_header('errands', 'Errands', 'What still needs buying, and where');
?>

<?php if (isset($_GET['added'])): ?>
  <div class="notice info"><p>Added to the list.</p></div>
<?php endif; ?>

<?php if ($error !== null): ?>
  <div class="notice error" role="alert"><p><?= h($error) ?></p></div>
<?php endif; ?>

<section>
  <?php eyebrow('Open'); ?>
  <h2><?= count($open) ?> <?= count($open) === 1 ? 'thing' : 'things' ?> to buy</h2>

  <?php if (!$open): ?>
    <div class="card"><?php empty_line('The list is clear.'); ?></div>
  <?php else: ?>
    <?php foreach ($grouped as $store => $items): ?>
      <h3><?= h($store !== '' ? $store : 'Anywhere') ?></h3>

      <?php foreach ($items as $item): ?>
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
          <p class="where">
            <?php if ($item['category']): ?><?= h($item['category']) ?> · <?php endif; ?>
            added <?= h(ago($item['added_at'])) ?>
            <?php if ($item['note']): ?> · <?= h($item['note']) ?><?php endif; ?>
          </p>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<section>
  <?php eyebrow('Add'); ?>
  <h2>Put something on the list</h2>

  <form class="stack" method="post" action="errands.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">

    <div class="field">
      <label for="item">Item <span class="req">*</span></label>
      <input type="text" id="item" name="item" maxlength="120" required autocomplete="off">
    </div>

    <div class="field">
      <label for="qty">How much</label>
      <input type="text" id="qty" name="qty" maxlength="40" placeholder="2 lb, a dozen, one box">
    </div>

    <div class="field">
      <label for="store_tag">Where it's bought</label>
      <select id="store_tag" name="store_tag">
        <option value="">Anywhere</option>
        <?php foreach ($stores as $s): ?>
          <option value="<?= h($s['store_tag']) ?>"><?= h($s['store_tag']) ?></option>
        <?php endforeach; ?>
      </select>
      <p class="hint">Tagging a store is what makes this item surface on the Now tab when you walk into it.</p>
    </div>

    <div class="field">
      <label for="category">Category</label>
      <input type="text" id="category" name="category" maxlength="40" placeholder="Groceries, household, pharmacy">
    </div>

    <div class="field">
      <label for="note">Note</label>
      <input type="text" id="note" name="note" maxlength="200">
    </div>

    <label class="checkbox-row" for="urgent">
      <input type="checkbox" id="urgent" name="urgent" value="1">
      <span>Urgent — put it at the top</span>
    </label>

    <button type="submit" class="btn btn-primary">Add to list</button>
  </form>
</section>

<?php if ($bought): ?>
<section>
  <?php eyebrow('Done'); ?>
  <h2>Recently bought</h2>

  <div class="scroll">
    <table>
      <thead><tr><th>Item</th><th>Where</th><th>Bought</th></tr></thead>
      <tbody>
        <?php foreach ($bought as $item): ?>
          <tr>
            <td><?= h($item['item']) ?></td>
            <td><?= h($item['store_tag'] ?? 'anywhere') ?></td>
            <td class="mono"><?= h(ago($item['bought_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?php endif; ?>

<?php page_footer(); ?>
