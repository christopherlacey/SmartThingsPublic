<?php
/**
 * Money.
 *
 * Balances, net worth, where it went, and what is about to leave the account.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
ledger_boot();
require_login();

$accounts   = account_balances();
$worth      = net_worth();
$worthSeries = net_worth_series(18);
$byCategory = spend_by_category(30, 8);
$cashflow   = monthly_cashflow(6);
$bills      = bills_due(30);
$recent     = recent_transactions(15);

$assets = array_sum(array_map(
    static fn(array $a): float => $a['is_liability'] ? 0.0 : (float) ($a['amount'] ?? 0),
    $accounts
));
$debts = array_sum(array_map(
    static fn(array $a): float => $a['is_liability'] ? abs((float) ($a['amount'] ?? 0)) : 0.0,
    $accounts
));

page_head('Money', 'money');
page_header('money', 'Money', 'Balances, spending and what&rsquo;s due');
?>

<?php /* -------------------------------------------------------- headline -- */ ?>
<section>
  <?php eyebrow('Net worth'); ?>
  <div class="card">
    <p class="hero"><?= h(money0($worth)) ?></p>
    <p class="hero-sub">
      <?= h(money0($assets)) ?> in assets
      <?php if ($debts > 0): ?> · <?= h(money0($debts)) ?> owed<?php endif; ?>
      <?php if ($accounts): ?> · across <?= count($accounts) ?> accounts<?php endif; ?>
    </p>
  </div>
</section>

<?php if (count($worthSeries) > 1): ?>
<section>
  <?php eyebrow('Trend'); ?>
  <h2>Net worth over time</h2>

  <div class="chart-card">
    <div class="chart-head">
      <p class="chart-title">Assets minus liabilities, at each month end</p>
      <p class="chart-note"><?= count($worthSeries) ?> months</p>
    </div>
    <div class="chart" data-chart="line" data-source="worth-data"></div>
  </div>

  <?= json_block('worth-data', [
      'title'  => 'Net worth',
      'format' => 'money0',
      'xLabel' => 'Month',
      'labels' => array_map(
          static fn(array $r): string => date('M y', strtotime($r['month'] . '-01')),
          $worthSeries
      ),
      'series' => [[
          'name'   => 'Net worth',
          'values' => array_map(static fn(array $r): float => (float) $r['value'], $worthSeries),
      ]],
  ]) ?>
</section>
<?php endif; ?>

<?php /* -------------------------------------------------------- accounts -- */ ?>
<section>
  <?php eyebrow('Accounts'); ?>
  <h2>Where it sits</h2>

  <?php if (!$accounts): ?>
    <div class="card"><?php empty_line('No accounts on file.'); ?></div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Account</th><th>Institution</th><th>Type</th><th class="num">Balance</th><th>As of</th></tr>
        </thead>
        <tbody>
          <?php foreach ($accounts as $a): ?>
            <tr>
              <td>
                <?= h($a['name']) ?>
                <?php if ($a['last4']): ?><span class="mono"> ••<?= h($a['last4']) ?></span><?php endif; ?>
              </td>
              <td><?= h($a['institution'] ?? '—') ?></td>
              <td><?= h($a['kind']) ?><?= $a['is_liability'] ? tag('urgent', 'owed') : '' ?></td>
              <td class="num <?= $a['is_liability'] ? 'out' : '' ?>">
                <?= $a['amount'] === null ? '—' : h(money((float) $a['amount'])) ?>
              </td>
              <td class="mono"><?= h($a['on_day'] ?? 'never') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php /* ------------------------------------------------------- spending -- */ ?>
<?php if ($byCategory): ?>
<section>
  <?php eyebrow('Last 30 days'); ?>
  <h2>Where the money went</h2>

  <div class="chart-card">
    <div class="chart-head">
      <p class="chart-title">Spending by category</p>
      <p class="chart-note">30 days · <?= h(money0(array_sum(array_column($byCategory, 'total')))) ?> total</p>
    </div>
    <div class="chart" data-chart="bars" data-source="category-data"></div>
  </div>

  <?= json_block('category-data', [
      'title'      => 'Spending by category',
      'format'     => 'money0',
      'xLabel'     => 'Category',
      'valueLabel' => 'Spent',
      'items'      => array_map(static fn(array $r): array => [
          'label' => $r['category'],
          'value' => (float) $r['total'],
          'note'  => $r['n'] . ($r['n'] === 1 ? ' transaction' : ' transactions'),
      ], $byCategory),
  ]) ?>
</section>
<?php endif; ?>

<?php /* ------------------------------------------------------ cash flow -- */ ?>
<?php if (count($cashflow) > 1): ?>
<section>
  <?php eyebrow('Cash flow'); ?>
  <h2>In and out by month</h2>

  <div class="chart-card">
    <div class="chart-head">
      <p class="chart-title">Money in against money out</p>
      <p class="chart-note">both on one scale, in dollars</p>
    </div>
    <div class="chart" data-chart="grouped" data-source="cashflow-data"></div>
  </div>

  <?= json_block('cashflow-data', [
      'title'  => 'Cash flow',
      'format' => 'money',
      'xLabel' => 'Month',
      'labels' => array_map(
          static fn(array $r): string => date('M y', strtotime($r['month'] . '-01')),
          $cashflow
      ),
      'series' => [
          ['name' => 'In',  'values' => array_map(static fn(array $r): float => (float) $r['money_in'], $cashflow)],
          ['name' => 'Out', 'values' => array_map(static fn(array $r): float => (float) $r['money_out'], $cashflow)],
      ],
  ]) ?>
</section>
<?php endif; ?>

<?php /* ---------------------------------------------------------- bills -- */ ?>
<section>
  <?php eyebrow('Coming out'); ?>
  <h2>Bills due in the next 30 days</h2>

  <?php if (!$bills): ?>
    <div class="card"><?php empty_line('Nothing due in the next 30 days.'); ?></div>
  <?php else: ?>
    <?php foreach ($bills as $b): ?>
      <?php $due = days_until($b['next_due']); ?>
      <div class="card<?= ($due !== null && $due < 0) ? ' urgent' : '' ?>">
        <div class="card-row">
          <p class="who">
            <?= h($b['name']) ?>
            <?= $b['autopay'] ? tag('confirmed', 'autopay') : '' ?>
          </p>
          <p class="mono"><?= h($b['amount'] === null ? '—' : money((float) $b['amount'])) ?></p>
        </div>
        <p class="where">
          <?= h(due_phrase($b['next_due'])) ?>
          <?php if ($b['account_name']): ?> · from <?= h($b['account_name']) ?><?php endif; ?>
          · <?= h($b['cadence']) ?>
        </p>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</section>

<?php /* --------------------------------------------------- transactions -- */ ?>
<section>
  <?php eyebrow('Recent'); ?>
  <h2>Latest transactions</h2>

  <?php if (!$recent): ?>
    <div class="card"><?php empty_line('No transactions recorded.'); ?></div>
  <?php else: ?>
    <div class="scroll">
      <table>
        <thead>
          <tr><th>Date</th><th>Merchant</th><th>Category</th><th>Account</th><th class="num">Amount</th></tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $t): ?>
            <tr>
              <td class="mono"><?= h(date('M j', strtotime($t['on_day']))) ?></td>
              <td><?= h($t['merchant'] ?? '—') ?></td>
              <td><?= h($t['category'] ?? '—') ?></td>
              <td><?= h($t['account_name'] ?? '—') ?></td>
              <td class="num <?= (float) $t['amount'] < 0 ? 'out' : 'in' ?>">
                <?= h(money((float) $t['amount'], true)) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

<?php page_footer(); ?>
