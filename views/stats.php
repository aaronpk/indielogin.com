<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * Public stats: sign-ins by provider, and sign-ins per month. See
 * Activity::report(); only these two figures are shown here.
 *
 * @var int|string $range
 * @var array      $ranges
 * @var string     $first_month
 * @var array      $months        Y-m => signins, providers, ...
 * @var array      $providers     key => label
 * @var string     $current_month
 * @var int        $pending
 */
require_once __DIR__.'/components/chart_helpers.php';

$signins = array_map(fn($row) => $row['signins'], $months);
?>

<div class="container">

  <h1>Stats</h1>

  <p>How people have been signing in with <?= e(getenv('APP_NAME')) ?>, month by month. Months are UTC, and the current month is updated every few minutes.</p>

  <?php if($pending): ?>
    <div class="alert alert-info">These stats are still being counted. Check back in a minute.</div>
  <?php else: ?>

    <div class="d-flex flex-wrap align-items-baseline mb-2">
      <span class="mr-2 text-muted">Show</span>
      <div class="btn-group btn-group-sm" role="group" aria-label="Range">
        <?php foreach(array_merge($ranges, ['all']) as $r): ?>
          <a href="/stats?months=<?= $r ?>" class="btn btn-outline-secondary<?= $r === $range ? ' active' : '' ?>"
             <?= $r === $range ? 'aria-current="page"' : '' ?>><?= $r === 'all' ? 'All, since '.e(chart_month_label($first_month)) : $r.' months' ?></a>
        <?php endforeach ?>
      </div>
    </div>

    <?php $this->insert('components/provider_chart', compact('months', 'providers') + ['current' => $current_month]) ?>

    <?php $this->insert('components/month_chart', [
      'id' => 'chart-signins', 'title' => 'Sign-ins', 'unit' => 'sign-ins',
      'note' => 'Every time someone signed in to an application. The lighter column is the month in progress.',
      'values' => $signins, 'current' => $current_month,
    ]) ?>

    <h3 class="mt-4">Month by month</h3>
    <?php
      $columns = array_filter($providers + ['other' => 'Other'], function($label, $k) use($months) {
        foreach($months as $row)
          if(($row['providers'][$k] ?? 0) > 0) return true;
        return false;
      }, ARRAY_FILTER_USE_BOTH);
    ?>
    <div class="table-responsive">
      <table class="table table-sm chart-table">
        <thead>
          <tr>
            <th>Month</th>
            <th class="text-right">Sign-ins</th>
            <?php foreach($columns as $label): ?>
              <th class="text-right"><?= e($label) ?></th>
            <?php endforeach ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach(array_reverse($months, true) as $month => $row): ?>
            <tr>
              <td class="text-nowrap">
                <?= e(chart_month_label($month)) ?>
                <?php if($month === $current_month): ?><span class="small text-muted">so far</span><?php endif ?>
              </td>
              <td class="text-right"><?= number_format($row['signins']) ?></td>
              <?php foreach(array_keys($columns) as $k): ?>
                <td class="text-right"><?= number_format($row['providers'][$k] ?? 0) ?></td>
              <?php endforeach ?>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>

    <p class="small text-muted">"Other" is any way of signing in not listed separately.</p>

  <?php endif ?>

</div>

<div class="chart-tooltip" role="tooltip" hidden></div>
<script src="<?= e(asset('/assets/charts.js')) ?>"></script>
