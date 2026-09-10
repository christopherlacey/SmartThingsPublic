<?php
/**
 * The Lacey Ledger — Finance.
 *
 * Every number on this page is computed from rows in `transactions`. Nothing
 * is illustrative: with an empty table the page says so rather than showing
 * plausible-looking figures.
 *
 * Convention (see schema.sql): amount_cents is signed and stored in minor
 * units. Negative is money out, positive is money in.
 */

declare(strict_types=1);

require __DIR__ . '/ledger-db.php';
require __DIR__ . '/charts.php';

$PAGE_TITLE = 'Finance';

/** Window in days, from ?days= — clamped to a sane set. */
$WINDOWS = [30 => '30 days', 90 => '90 days', 365 => '12 months'];
$days    = (int) ($_GET['days'] ?? 30);
if (!isset($WINDOWS[$days])) {
    $days = 30;
}

$fatal = null;
$ready = false;
$currency = '$';

try {
    $pdo   = ledger_db();
    $cfg   = require __DIR__ . '/ledger-config.php';
    $currency = ($cfg['currency'] ?? 'USD') === 'USD' ? '$' : '';
    $ready = ledger_has_table($pdo, 'transactions');
} catch (Throwable $ex) {
    $fatal = $ex->getMessage();
}

$since = (new DateTimeImmutable("-{$days} days"))->format('Y-m-d');
$today = (new DateTimeImmutable('today'))->format('Y-m-d');

$rows = [];
$out = $in = 0;
$dayTotals = [];
$byCategory = [];
$byMerchant = [];
$monthly = [];
$busiest = null;

if ($ready) {
    // Everything in the window, newest first. One query; the page slices it.
    $stmt = $pdo->prepare(
        'SELECT posted_on, amount_cents, description, merchant, category, account
           FROM transactions
          WHERE posted_on >= :since AND posted_on <= :today
          ORDER BY posted_on DESC, id DESC'
    );
    $stmt->execute([':since' => $since, ':today' => $today]);
    $rows = $stmt->fetchAll();

    foreach ($rows as $r) {
        $cents = (int) $r['amount_cents'];
        $d     = (string) $r['posted_on'];

        if ($cents < 0) {
            $out += -$cents;
            $dayTotals[$d] = ($dayTotals[$d] ?? 0) + (-$cents);

            $cat = trim((string) ($r['category'] ?? '')) ?: 'Uncategorised';
            $byCategory[$cat] = ($byCategory[$cat] ?? 0) + (-$cents);

            $m = trim((string) ($r['merchant'] ?? '')) ?: trim((string) $r['description']);
            if ($m !== '') {
                $byMerchant[$m] = ($byMerchant[$m] ?? 0) + (-$cents);
            }
        } else {
            $in += $cents;
        }

        $mon = substr($d, 0, 7);
        $monthly[$mon] = ($monthly[$mon] ?? 0) + ($cents < 0 ? -$cents : 0);
    }

    arsort($byCategory);
    arsort($byMerchant);
    ksort($monthly);

    if ($dayTotals) {
        $busiest = array_keys($dayTotals, max($dayTotals))[0];
    }
}

/** Every date in the window, so quiet days appear as gaps rather than vanish. */
$series = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = (new DateTimeImmutable("-{$i} days"))->format('Y-m-d');
    $series[] = ['date' => $d, 'cents' => $dayTotals[$d] ?? 0];
}

$net       = $in - $out;
$activeDays = count($dayTotals);
$perDay    = $days > 0 ? (int) round($out / $days) : 0;

require __DIR__ . '/header.php';
?>

<h1>Finance</h1>
<p class="kicker">
  Every transaction, what each day came to, and where it drifts over time.
</p>

<?php if ($fatal !== null): ?>
  <div class="card notice">
    <p class="who">The Finance tab can’t reach the database</p>
    <p class="line"><?= e($fatal) ?></p>
  </div>
<?php elseif (!$ready): ?>
  <div class="card notice">
    <p class="who">No <code>transactions</code> table yet</p>
    <p class="line">
      Load <code>schema.sql</code> once, then bring in a bank export with
      <code>php import-transactions.php statement.csv</code>. This page stays
      empty until there is real data — it will never show sample figures.
    </p>
  </div>
<?php else: ?>

  <div class="periods">
    <?php foreach ($WINDOWS as $d => $label): ?>
      <a class="period<?= $d === $days ? ' on' : '' ?>" href="?days=<?= $d ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if (!$rows): ?>
    <div class="card notice">
      <p class="who">Nothing in the last <?= e((string) $days) ?> days</p>
      <p class="line">
        The table is reachable but holds no transactions in this window. Try a
        longer period, or import a statement.
      </p>
    </div>
  <?php else: ?>

    <div class="stats">
      <div class="stat">
        <div class="stat-label">Money out</div>
        <div class="stat-value"><?= e(money($out, false, $currency)) ?></div>
        <div class="stat-note"><?= e(number_format(count($rows))) ?> transactions</div>
      </div>
      <div class="stat">
        <div class="stat-label">Money in</div>
        <div class="stat-value"><?= e(money($in, false, $currency)) ?></div>
        <div class="stat-note">over <?= e((string) $days) ?> days</div>
      </div>
      <div class="stat">
        <div class="stat-label">Net</div>
        <div class="stat-value <?= $net < 0 ? 'is-down' : 'is-up' ?>">
          <?= e(money($net, true, $currency)) ?>
        </div>
        <div class="stat-note"><?= $net < 0 ? 'more out than in' : 'more in than out' ?></div>
      </div>
      <div class="stat">
        <div class="stat-label">Typical day</div>
        <div class="stat-value"><?= e(money($perDay, false, $currency)) ?></div>
        <div class="stat-note">
          spent on <?= e((string) $activeDays) ?> of <?= e((string) $days) ?> days
        </div>
      </div>
    </div>

    <h2>Money out, day by day</h2>
    <div class="card chart-card">
      <?= ledger_day_chart($series, $currency) ?>
      <p class="where chart-note">
        One column per day. Hover a column for the date and total; a hairline
        marks a day nothing was spent.
        <?php if ($busiest !== null): ?>
          Busiest was <strong><?= e(date('l j F', strtotime($busiest))) ?></strong>
          at <?= e(money($dayTotals[$busiest], false, $currency)) ?>.
        <?php endif; ?>
      </p>
    </div>

    <h2>Where it goes</h2>
    <div class="two-up">
      <div class="card">
        <h3>By category</h3>
        <?= ledger_rank_chart(
            array_map(
                fn($k, $v) => ['label' => $k, 'cents' => $v],
                array_keys(array_slice($byCategory, 0, 8, true)),
                array_slice($byCategory, 0, 8, true)
            ),
            $currency
        ) ?>
      </div>
      <div class="card">
        <h3>By merchant</h3>
        <?= ledger_rank_chart(
            array_map(
                fn($k, $v) => ['label' => $k, 'cents' => $v],
                array_keys(array_slice($byMerchant, 0, 8, true)),
                array_slice($byMerchant, 0, 8, true)
            ),
            $currency
        ) ?>
      </div>
    </div>

    <?php if (count($monthly) > 1): ?>
      <h2>Trend</h2>
      <div class="card">
        <?php
          $vals   = array_values($monthly);
          $labels = array_keys($monthly);
          $last   = end($vals);
          $prev   = $vals[count($vals) - 2];
          $delta  = $prev > 0 ? (($last - $prev) / $prev) * 100 : 0.0;
        ?>
        <div class="trend-row">
          <?= ledger_sparkline($vals) ?>
          <div>
            <p class="who" style="margin:0">
              <?= e(date('F', strtotime($labels[count($labels) - 1] . '-01'))) ?>
              is <?= $delta >= 0 ? 'up' : 'down' ?>
              <?= e(number_format(abs($delta), 1)) ?>%
              on <?= e(date('F', strtotime($labels[count($labels) - 2] . '-01'))) ?>
            </p>
            <p class="where" style="margin:2px 0 0">
              <?= e(money((int) $last, false, $currency)) ?> against
              <?= e(money((int) $prev, false, $currency)) ?>
            </p>
          </div>
        </div>
        <div class="scroll" style="margin-top:14px">
          <table>
            <thead><tr><th>Month</th><th class="num">Money out</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($monthly, true) as $mon => $cents): ?>
              <tr>
                <td><?= e(date('F Y', strtotime($mon . '-01'))) ?></td>
                <td class="num"><?= e(money((int) $cents, false, $currency)) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <h2>Each day</h2>
    <?php
      $byDay = [];
      foreach ($rows as $r) {
          $byDay[(string) $r['posted_on']][] = $r;
      }
    ?>
    <?php foreach ($byDay as $date => $dayRows): ?>
      <?php
        $dOut = 0; $dIn = 0;
        foreach ($dayRows as $r) {
            $c = (int) $r['amount_cents'];
            $c < 0 ? $dOut += -$c : $dIn += $c;
        }
      ?>
      <details class="card day"<?= $date === $today ? ' open' : '' ?>>
        <summary>
          <span class="day-date"><?= e(date('D j M', strtotime($date))) ?></span>
          <span class="day-count mono"><?= e((string) count($dayRows)) ?></span>
          <span class="day-total mono"><?= e(money($dOut, false, $currency)) ?></span>
        </summary>
        <div class="scroll">
          <table>
            <thead>
              <tr><th>Description</th><th>Category</th><th>Account</th><th class="num">Amount</th></tr>
            </thead>
            <tbody>
            <?php foreach ($dayRows as $r): ?>
              <tr>
                <td>
                  <?= e((string) $r['description']) ?>
                  <?php if (!empty($r['merchant'])): ?>
                    <span class="where"><?= e((string) $r['merchant']) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= e((string) ($r['category'] ?? '—')) ?></td>
                <td><?= e((string) ($r['account'] ?? '—')) ?></td>
                <td class="num <?= (int) $r['amount_cents'] < 0 ? 'is-down' : 'is-up' ?>">
                  <?= e(money((int) $r['amount_cents'], true, $currency)) ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endforeach; ?>

  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
