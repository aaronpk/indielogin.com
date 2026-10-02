<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * @var string $q
 * @var array  $clients clients rows plus owner_url and recent_logins.
 * @var int    $page
 * @var bool   $more
 * @var int    $days
 */
?>

<div class="container admin">

  <?php $this->insert('admin/_header', compact('tab', 'admin', 'error', 'success')) ?>

  <form action="/admin/clients" method="get" class="form-inline mb-3">
    <label class="sr-only" for="q">Search</label>
    <input id="q" type="search" name="q" class="form-control mr-2 admin-search" value="<?= e($q) ?>"
           placeholder="Client ID, owner URL or email">
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if($q !== ''): ?>
      <a href="/admin/clients" class="btn btn-link">Clear</a>
    <?php endif ?>
  </form>

  <?php if(!$clients): ?>
    <p class="text-muted"><?= $q === '' ? 'No clients are registered.' : 'No client matches <code>'.e($q).'</code>.' ?></p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm admin-table">
        <thead>
          <tr>
            <th>Client ID</th>
            <th>Owner</th>
            <th>Registered</th>
            <th>Last used</th>
            <th class="text-right">Sign-ins, <?= $days ?> days</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($clients as $c): ?>
            <tr>
              <td class="admin-url">
                <a href="/admin/clients/<?= e($c['id']) ?>"><?= e($c['client_id'] ?? '') ?></a>
                <?php if(!$c['active']): ?><span class="badge badge-danger">inactive</span><?php endif ?>
                <?php if(!$c['pkce_required']): ?><span class="badge badge-secondary">PKCE optional</span><?php endif ?>
              </td>
              <td class="admin-url">
                <?php if($c['owner_url']): ?>
                  <a href="/admin/users/<?= e($c['user_id']) ?>"><?= e(\p3k\url\display_url($c['owner_url'])) ?></a>
                <?php elseif($c['email']): ?>
                  <span class="text-muted"><?= e($c['email']) ?></span>
                <?php else: ?>
                  <span class="text-muted">&mdash;</span>
                <?php endif ?>
              </td>
              <td class="text-nowrap"><?= $c['date_created'] ? e(display_date('Y-m-d', $c['date_created'])) : '<span class="text-muted">&mdash;</span>' ?></td>
              <td class="text-nowrap"><?= $c['date_last_used'] ? e(display_date('Y-m-d', $c['date_last_used'])) : '<span class="text-muted">never</span>' ?></td>
              <td class="text-right"><?= number_format($c['recent_logins']) ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endif ?>

  <?php $this->insert('admin/_pager', compact('page', 'more')) ?>

</div>
