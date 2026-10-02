<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * @var string $q
 * @var array  $users users rows plus a count of clients.
 * @var int    $page
 * @var bool   $more
 */
?>

<div class="container admin">

  <?php $this->insert('admin/_header', compact('tab', 'admin', 'error', 'success')) ?>

  <form action="/admin/users" method="get" class="form-inline mb-3">
    <label class="sr-only" for="q">Search</label>
    <input id="q" type="search" name="q" class="form-control mr-2 admin-search" value="<?= e($q) ?>"
           placeholder="URL or email">
    <button type="submit" class="btn btn-secondary">Search</button>
    <?php if($q !== ''): ?>
      <a href="/admin/users" class="btn btn-link">Clear</a>
    <?php endif ?>
  </form>

  <?php if(!$users): ?>
    <p class="text-muted"><?= $q === '' ? 'No developer accounts yet.' : 'No developer matches <code>'.e($q).'</code>.' ?></p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm admin-table">
        <thead>
          <tr>
            <th>URL</th>
            <th>Email</th>
            <th class="text-right">Clients</th>
            <th>Created</th>
            <th>Last sign-in</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($users as $u): ?>
            <tr>
              <td class="admin-url"><a href="/admin/users/<?= e($u['id']) ?>"><?= e(\p3k\url\display_url($u['url'])) ?></a></td>
              <td class="admin-url"><?= $u['email'] ? e($u['email']) : '<span class="text-muted">&mdash;</span>' ?></td>
              <td class="text-right"><?= number_format($u['clients']) ?></td>
              <td class="text-nowrap"><?= $u['date_created'] ? e(display_date('Y-m-d', $u['date_created'])) : '<span class="text-muted">&mdash;</span>' ?></td>
              <td class="text-nowrap"><?= $u['date_last_login'] ? e(display_date('Y-m-d', $u['date_last_login'])) : '<span class="text-muted">never</span>' ?></td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
  <?php endif ?>

  <?php $this->insert('admin/_pager', compact('page', 'more')) ?>

</div>
