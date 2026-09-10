<?php
/**
 * The Lacey Ledger — Shopping.
 *
 * The household list: add, tick off, restore, clear. Unlike Finance this tab
 * owns its data outright, so it works the moment schema.sql has been loaded.
 *
 * Writes go through POST + CSRF and finish with a redirect, so a refresh never
 * re-adds an item.
 */

declare(strict_types=1);

require __DIR__ . '/ledger-db.php';

$PAGE_TITLE = 'Shopping';

$fatal = null;
$ready = false;

try {
    $pdo   = ledger_db();
    $ready = ledger_has_table($pdo, 'shopping_items');
} catch (Throwable $ex) {
    $fatal = $ex->getMessage();
}

/* ---------------------------------------------------------------- writes -- */

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    ledger_csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $item = trim((string) ($_POST['item'] ?? ''));
        if ($item !== '') {
            $pdo->prepare(
                'INSERT INTO shopping_items (item, quantity, aisle, store, added_by, needed)
                 VALUES (:item, :qty, :aisle, :store, :by, 1)'
            )->execute([
                ':item'  => mb_substr($item, 0, 160),
                ':qty'   => mb_substr(trim((string) ($_POST['quantity'] ?? '')), 0, 40) ?: null,
                ':aisle' => mb_substr(trim((string) ($_POST['aisle'] ?? '')), 0, 60) ?: null,
                ':store' => mb_substr(trim((string) ($_POST['store'] ?? '')), 0, 80) ?: null,
                ':by'    => mb_substr(trim((string) ($_POST['added_by'] ?? '')), 0, 60) ?: null,
            ]);
        }
    } elseif ($action === 'check' || $action === 'uncheck') {
        $needed = $action === 'uncheck' ? 1 : 0;
        $pdo->prepare(
            'UPDATE shopping_items
                SET needed = :needed, checked_at = :at
              WHERE id = :id'
        )->execute([
            ':needed' => $needed,
            ':at'     => $needed ? null : date('Y-m-d H:i:s'),
            ':id'     => (int) ($_POST['id'] ?? 0),
        ]);
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM shopping_items WHERE id = :id')
            ->execute([':id' => (int) ($_POST['id'] ?? 0)]);
    } elseif ($action === 'clear_done') {
        $pdo->exec('DELETE FROM shopping_items WHERE needed = 0');
    }

    header('Location: shopping.php', true, 303);
    exit;
}

/* ---------------------------------------------------------------- reads --- */

$needed = [];
$done   = [];

if ($ready) {
    $all = $pdo->query(
        'SELECT id, item, quantity, aisle, store, added_by, needed, checked_at
           FROM shopping_items
          ORDER BY needed DESC, aisle IS NULL, aisle, item'
    )->fetchAll();

    foreach ($all as $row) {
        if ((int) $row['needed'] === 1) {
            $aisle = trim((string) ($row['aisle'] ?? '')) ?: 'Anywhere';
            $needed[$aisle][] = $row;
        } else {
            $done[] = $row;
        }
    }
    ksort($needed);
}

$token = $ready ? ledger_csrf_token() : '';
$count = array_sum(array_map('count', $needed));

require __DIR__ . '/header.php';
?>

<h1>Shopping</h1>
<p class="kicker">
  <?php if ($ready && $count): ?>
    <?= e((string) $count) ?> thing<?= $count === 1 ? '' : 's' ?> to pick up,
    grouped by where you’ll find them.
  <?php else: ?>
    The household list.
  <?php endif; ?>
</p>

<?php if ($fatal !== null): ?>
  <div class="card notice">
    <p class="who">The Shopping tab can’t reach the database</p>
    <p class="line"><?= e($fatal) ?></p>
  </div>
<?php elseif (!$ready): ?>
  <div class="card notice">
    <p class="who">No <code>shopping_items</code> table yet</p>
    <p class="line">Load <code>schema.sql</code> once and this tab is live.</p>
  </div>
<?php else: ?>

  <form class="card add-form" method="post" action="shopping.php">
    <input type="hidden" name="csrf" value="<?= e($token) ?>">
    <input type="hidden" name="action" value="add">
    <div class="add-grid">
      <div class="field add-item">
        <label for="item">Item</label>
        <input id="item" name="item" required maxlength="160" autocomplete="off"
               placeholder="Milk" autofocus>
      </div>
      <div class="field">
        <label for="quantity">How many</label>
        <input id="quantity" name="quantity" maxlength="40" autocomplete="off" placeholder="2">
      </div>
      <div class="field">
        <label for="aisle">Aisle</label>
        <input id="aisle" name="aisle" maxlength="60" autocomplete="off" list="aisles"
               placeholder="Dairy">
        <datalist id="aisles">
          <?php foreach (array_keys($needed) as $a): ?>
            <option value="<?= e($a) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <div class="field">
        <label for="added_by">Who</label>
        <input id="added_by" name="added_by" maxlength="60" autocomplete="off" placeholder="Chris">
      </div>
      <button class="btn btn-primary" type="submit">Add</button>
    </div>
  </form>

  <?php if (!$needed): ?>
    <div class="card">
      <p class="line empty">The list is clear. Nothing to pick up.</p>
    </div>
  <?php endif; ?>

  <?php foreach ($needed as $aisle => $items): ?>
    <h2><?= e($aisle) ?></h2>
    <ul class="list">
      <?php foreach ($items as $row): ?>
        <li class="item">
          <form method="post" action="shopping.php" class="item-check">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="action" value="check">
            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
            <button class="tick" type="submit"
                    aria-label="Tick off <?= e((string) $row['item']) ?>"><span></span></button>
          </form>
          <div class="item-body">
            <span class="item-name"><?= e((string) $row['item']) ?></span>
            <?php if (!empty($row['quantity'])): ?>
              <span class="tag"><?= e((string) $row['quantity']) ?></span>
            <?php endif; ?>
            <?php if (!empty($row['store']) || !empty($row['added_by'])): ?>
              <span class="where">
                <?= e(trim(
                    ($row['store'] ?? '')
                    . (!empty($row['store']) && !empty($row['added_by']) ? ' · ' : '')
                    . (!empty($row['added_by']) ? 'added by ' . $row['added_by'] : '')
                )) ?>
              </span>
            <?php endif; ?>
          </div>
          <form method="post" action="shopping.php">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
            <button class="btn btn-quiet" type="submit"
                    aria-label="Remove <?= e((string) $row['item']) ?>">Remove</button>
          </form>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endforeach; ?>

  <?php if ($done): ?>
    <h2>In the cart</h2>
    <ul class="list done">
      <?php foreach ($done as $row): ?>
        <li class="item">
          <form method="post" action="shopping.php" class="item-check">
            <input type="hidden" name="csrf" value="<?= e($token) ?>">
            <input type="hidden" name="action" value="uncheck">
            <input type="hidden" name="id" value="<?= e((string) $row['id']) ?>">
            <button class="tick is-on" type="submit"
                    aria-label="Put <?= e((string) $row['item']) ?> back on the list"><span></span></button>
          </form>
          <div class="item-body">
            <span class="item-name"><?= e((string) $row['item']) ?></span>
            <?php if (!empty($row['quantity'])): ?>
              <span class="tag"><?= e((string) $row['quantity']) ?></span>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <form method="post" action="shopping.php" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="clear_done">
      <button class="btn btn-ghost" type="submit">
        Clear <?= e((string) count($done)) ?> ticked item<?= count($done) === 1 ? '' : 's' ?>
      </button>
    </form>
  <?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
