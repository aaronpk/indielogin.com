<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * The service month by month. See AdminActivity::report().
 *
 * @var int|string $range         12, 24, 60 or 'all'
 * @var array      $ranges
 * @var string     $first_month   Of the oldest sign-in on record.
 * @var array      $months        Y-m => signins, completed, people, new_people,
 *                                clients, providers, new_developers, new_clients
 * @var array      $providers     key => label
 * @var string     $current_month
 * @var int        $current_ttl
 * @var int        $pending       Months that have ended and are not counted yet.
 * @var int        $counted
 */
require_once __DIR__.'/_chart_helpers.php';

$percent = fn($part, $whole) => $whole ? round(100 * $part / $whole).'%' : '—';
$column = fn($key) => array_map(fn($row) => $row[$key], $months);

// The tiles compare the last month that has ended with the one before it, so
// that a month in progress is never set against a whole one
$keys = array_keys($months);
$last = count($keys) >= 2 ? $keys[count($keys) - 2] : null;
$before = count($keys) >= 3 ? $keys[count($keys) - 3] : null;

// Provider columns in the table, leaving out any nobody used in this range
$providerColumns = array_filter($providers + ['other' => 'Other'], function($label, $k) use($months) {
  foreach($months as $row)
    if(($row['providers'][$k] ?? 0) > 0) return true;
  return false;
}, ARRAY_FILTER_USE_BOTH);

$tiles = [
  'signins' => 'Sign-ins',
  'people' => 'Active people',
  'new_people' => 'New people',
  'clients' => 'Active clients',
];
?>

<div class="container admin">

  <?php $this->insert('admin/_header', compact('tab', 'admin', 'error', 'success')) ?>

  <div class="d-flex flex-wrap align-items-baseline mb-2">
    <span class="mr-2 text-muted">Show</span>
    <div class="btn-group btn-group-sm" role="group" aria-label="Range">
      <?php foreach(array_merge($ranges, ['all']) as $r): ?>
        <a href="/admin/activity?months=<?= $r ?>" class="btn btn-outline-secondary<?= $r === $range ? ' active' : '' ?>"
           <?= $r === $range ? 'aria-current="page"' : '' ?>><?= $r === 'all' ? 'All, since '.e(admin_chart_month_label($first_month)) : $r.' months' ?></a>
      <?php endforeach ?>
    </div>
  </div>

  <?php if($pending): ?>
    <div class="alert alert-info">
      <b>Still counting.</b> <?= number_format($counted) ?> of <?= number_format($counted + $pending) ?> months since
      <?= e(admin_chart_month_label($first_month, true)) ?> are counted so far. Each month is counted once, oldest first,
      and every month has to be counted before the recent ones can be shown.
      <a href="">Reload</a> to count more, or run <code>php bin/count-activity</code> on the server to count them all at once.
    </div>
  <?php else: ?>

  <?php if($last): ?>
    <h3><?= e(admin_chart_month_label($last, true)) ?></h3>
    <div class="row admin-tiles">
      <?php foreach($tiles as $key => $label): ?>
        <?php
          $now = $months[$last][$key];
          $then = $before ? $months[$before][$key] : null;
          $delta = ($then !== null && $then > 0) ? round(100 * ($now - $then) / $then) : null;
        ?>
        <div class="col-sm-6 col-lg-3 mb-3">
          <div class="admin-tile">
            <div class="admin-tile-count"><?= number_format($now) ?></div>
            <div class="admin-tile-label"><?= e($label) ?></div>
            <div class="small">
              <?php if($delta === null): ?>
                <span class="text-muted">no earlier month to compare</span>
              <?php elseif($delta == 0): ?>
                <span class="text-muted">no change from <?= e(gmdate('F', strtotime($before.'-01'))) ?></span>
              <?php else: ?>
                <span class="<?= $delta > 0 ? 'admin-up' : 'admin-down' ?>"><?= $delta > 0 ? '▲' : '▼' ?> <?= abs($delta) ?>%</span>
                <span class="text-muted">from <?= e(gmdate('F', strtotime($before.'-01'))) ?></span>
              <?php endif ?>
            </div>
          </div>
        </div>
      <?php endforeach ?>
    </div>
  <?php endif ?>

  <?php
    $completion = array_map(fn($r) => $percent($r['completed'], $r['signins']).' completed', $months);
    $returning = array_map(fn($r) => number_format($r['people'] - $r['new_people']).' returning', $months);
  ?>

  <?php $this->insert('admin/_month_chart', [
    'id' => 'chart-signins', 'title' => 'Sign-ins', 'unit' => 'sign-ins',
    'note' => 'Every time someone authenticated and was sent back to an application. The lighter column is the month in progress.',
    'values' => $column('signins'), 'extra' => $completion, 'current' => $current_month,
  ]) ?>

  <?php $this->insert('admin/_month_chart', [
    'id' => 'chart-people', 'title' => 'Active people', 'unit' => 'people',
    'note' => 'Different URLs that signed in to anything that month.',
    'values' => $column('people'), 'extra' => $returning, 'current' => $current_month,
  ]) ?>

  <?php $this->insert('admin/_month_chart', [
    'id' => 'chart-new-people', 'title' => 'New people', 'unit' => 'new people',
    'note' => 'URLs signing in for the first time on record.',
    'values' => $column('new_people'), 'current' => $current_month,
  ]) ?>

  <?php $this->insert('admin/_month_chart', [
    'id' => 'chart-clients', 'title' => 'Active clients', 'unit' => 'clients',
    'note' => 'Different applications that someone signed in to.',
    'values' => $column('clients'), 'current' => $current_month,
  ]) ?>

  <?php $this->insert('admin/_provider_chart', compact('months', 'providers') + ['current' => $current_month]) ?>

  <div class="row">
    <div class="col-md-6">
      <?php $this->insert('admin/_month_chart', [
        'id' => 'chart-new-developers', 'width' => 440, 'title' => 'New developer accounts', 'unit' => 'accounts',
        'note' => 'Accounts that existed before self-service registration were all created the month it launched.',
        'values' => $column('new_developers'), 'current' => $current_month,
      ]) ?>
    </div>
    <div class="col-md-6">
      <?php $this->insert('admin/_month_chart', [
        'id' => 'chart-new-clients', 'width' => 440, 'title' => 'Clients registered', 'unit' => 'clients',
        'note' => 'Clients registered by hand before that have no date, and are not counted.',
        'values' => $column('new_clients'), 'current' => $current_month,
      ]) ?>
    </div>
  </div>

  <h3 id="table">Month by month</h3>
  <div class="table-responsive">
    <table class="table table-sm admin-table">
      <thead>
        <tr>
          <th>Month</th>
          <th class="text-right">Sign-ins</th>
          <th class="text-right">Completed</th>
          <th class="text-right">Active people</th>
          <th class="text-right">New people</th>
          <th class="text-right">Active clients</th>
          <th class="text-right">New developers</th>
          <th class="text-right">Clients registered</th>
          <?php foreach($providerColumns as $label): ?>
            <th class="text-right"><?= e($label) ?></th>
          <?php endforeach ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach(array_reverse($months, true) as $month => $r): ?>
          <tr>
            <td class="text-nowrap">
              <?= e(admin_chart_month_label($month)) ?>
              <?php if($month === $current_month): ?><span class="small text-muted">so far</span><?php endif ?>
            </td>
            <td class="text-right"><?= number_format($r['signins']) ?></td>
            <td class="text-right"><?= $percent($r['completed'], $r['signins']) ?></td>
            <td class="text-right"><?= number_format($r['people']) ?></td>
            <td class="text-right"><?= number_format($r['new_people']) ?></td>
            <td class="text-right"><?= number_format($r['clients']) ?></td>
            <td class="text-right"><?= number_format($r['new_developers']) ?></td>
            <td class="text-right"><?= number_format($r['new_clients']) ?></td>
            <?php foreach(array_keys($providerColumns) as $k): ?>
              <td class="text-right"><?= number_format($r['providers'][$k] ?? 0) ?></td>
            <?php endforeach ?>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <p class="small text-muted">
    Months are UTC. A month that has ended is counted once and kept; the month in progress is recounted at most every <?= round($current_ttl / 60) ?> minutes.
    People are counted by the URL they signed in as, exactly as recorded, so the same site signing in under two spellings counts twice.
  </p>

  <?php endif ?>

</div>

<div class="admin-tooltip" role="tooltip" hidden></div>
<script src="<?= e(asset('/assets/admin-charts.js')) ?>"></script>
