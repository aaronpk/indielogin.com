<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * How the service is doing. See Admin::_overviewStats().
 *
 * @var int    $days
 * @var array  $users       total, recent
 * @var array  $clients     total, inactive, recent, no_pkce
 * @var array  $windows     label, total, completed
 * @var array  $per_day     Y-m-d => total, completed
 * @var array  $providers   provider, total, completed
 * @var array  $top_clients client_id, id, total, completed
 * @var string $counted_at
 */
$percent = fn($part, $whole) => $whole ? round(100 * $part / $whole).'%' : '—';
$busiest = max(1, max(array_column($per_day, 'total')));
?>

<div class="container admin">

  <?php $this->insert('admin/_header', compact('tab', 'admin', 'error', 'success')) ?>

  <h3>Sign-ins</h3>
  <div class="row admin-tiles">
    <?php foreach($windows as $w): ?>
      <div class="col-sm-4 mb-3">
        <div class="admin-tile">
          <div class="admin-tile-count"><?= number_format($w['total']) ?></div>
          <div class="admin-tile-label"><?= e($w['label']) ?></div>
          <div class="small text-muted"><?= $percent($w['completed'], $w['total']) ?> completed</div>
        </div>
      </div>
    <?php endforeach ?>
  </div>

  <h3>Accounts</h3>
  <div class="row admin-tiles">
    <div class="col-sm-4 mb-3">
      <a class="admin-tile" href="/admin/users">
        <div class="admin-tile-count"><?= number_format($users['total']) ?></div>
        <div class="admin-tile-label">Developers</div>
        <div class="small text-muted"><?= number_format($users['recent']) ?> signed in over <?= $days ?> days</div>
      </a>
    </div>
    <div class="col-sm-4 mb-3">
      <a class="admin-tile" href="/admin/clients">
        <div class="admin-tile-count"><?= number_format($clients['total']) ?></div>
        <div class="admin-tile-label">Clients</div>
        <div class="small text-muted"><?= number_format($clients['recent']) ?> used over <?= $days ?> days</div>
      </a>
    </div>
    <div class="col-sm-4 mb-3">
      <div class="admin-tile">
        <div class="admin-tile-count"><?= number_format($clients['inactive']) ?></div>
        <div class="admin-tile-label">Deactivated clients</div>
        <div class="small text-muted"><?= number_format($clients['no_pkce']) ?> not requiring PKCE</div>
      </div>
    </div>
  </div>

  <h3>The last <?= $days ?> days</h3>
  <div class="table-responsive">
    <table class="table table-sm admin-table">
      <thead>
        <tr><th>Day</th><th class="text-right">Sign-ins</th><th class="text-right">Completed</th><th class="w-50"></th></tr>
      </thead>
      <tbody>
        <?php foreach(array_reverse($per_day, true) as $day => $d): ?>
          <tr>
            <td class="text-nowrap"><?= e($day) ?></td>
            <td class="text-right"><?= number_format($d['total']) ?></td>
            <td class="text-right"><?= $percent($d['completed'], $d['total']) ?></td>
            <td>
              <div class="admin-bar" style="width: <?= round(100 * $d['total'] / $busiest, 1) ?>%"
                   title="<?= number_format($d['total']) ?> sign-ins"></div>
            </td>
          </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>

  <div class="row">
    <div class="col-md-5">
      <h3>Providers</h3>
      <?php if(!$providers): ?>
        <p class="text-muted">No sign-ins in the last <?= $days ?> days.</p>
      <?php else: ?>
        <table class="table table-sm admin-table">
          <thead><tr><th>Provider</th><th class="text-right">Sign-ins</th><th class="text-right">Completed</th></tr></thead>
          <tbody>
            <?php foreach($providers as $p): ?>
              <tr>
                <td><a href="/admin/logins?<?= e(http_build_query(['provider' => $p['provider']])) ?>"><?= e($p['provider'] ?: '(none)') ?></a></td>
                <td class="text-right"><?= number_format($p['total']) ?></td>
                <td class="text-right"><?= $percent($p['completed'], $p['total']) ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </div>

    <div class="col-md-7">
      <h3>Busiest clients</h3>
      <?php if(!$top_clients): ?>
        <p class="text-muted">No sign-ins in the last <?= $days ?> days.</p>
      <?php else: ?>
        <table class="table table-sm admin-table">
          <thead><tr><th>Client</th><th class="text-right">Sign-ins</th><th class="text-right">Completed</th></tr></thead>
          <tbody>
            <?php foreach($top_clients as $c): ?>
              <tr>
                <td class="admin-url">
                  <?php if($c['id']): ?>
                    <a href="/admin/clients/<?= e($c['id']) ?>"><?= e($c['client_id']) ?></a>
                  <?php else: ?>
                    <?= e($c['client_id'] ?: '(none)') ?>
                  <?php endif ?>
                </td>
                <td class="text-right"><a href="/admin/logins?<?= e(http_build_query(['client_id' => $c['client_id']])) ?>"><?= number_format($c['total']) ?></a></td>
                <td class="text-right"><?= $percent($c['completed'], $c['total']) ?></td>
              </tr>
            <?php endforeach ?>
          </tbody>
        </table>
      <?php endif ?>
    </div>
  </div>

  <p class="small text-muted">"Completed" means the application exchanged the authorization code. Counted at <?= e($counted_at) ?> UTC, and recounted at most once a minute.</p>

</div>
