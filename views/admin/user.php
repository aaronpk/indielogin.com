<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * One developer account.
 *
 * @var object $user
 * @var bool   $is_admin_user
 * @var array  $clients clients rows plus recent_logins.
 * @var array  $logins  This person's own recent sign-ins, to any client.
 * @var int    $days
 */
?>

<div class="container admin">

  <?php $this->insert('admin/_header', compact('tab', 'admin', 'error', 'success')) ?>

  <h2 class="admin-url">
    <a href="<?= e($user->url) ?>"><?= e($user->url) ?></a>
    <?php if($is_admin_user): ?><span class="badge badge-dark">admin</span><?php endif ?>
  </h2>

  <dl class="row">
    <dt class="col-sm-3">Email</dt>
    <dd class="col-sm-9"><?= $user->email ? '<a href="mailto:'.e($user->email).'">'.e($user->email).'</a>' : '<span class="text-muted">none given</span>' ?></dd>

    <dt class="col-sm-3">Created</dt>
    <dd class="col-sm-9"><?= $user->date_created ? e($user->date_created) : '<span class="text-muted">unknown</span>' ?></dd>

    <dt class="col-sm-3">Last sign-in</dt>
    <dd class="col-sm-9"><?= $user->date_last_login ? e($user->date_last_login) : '<span class="text-muted">never, account migrated from the old clients table</span>' ?></dd>
  </dl>

  <h3>Clients</h3>
  <?php if(!$clients): ?>
    <p class="text-muted">No clients registered.</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm admin-table">
        <thead>
          <tr><th>Client ID</th><th>Registered</th><th>Last used</th><th class="text-right">Sign-ins, <?= $days ?> days</th></tr>
        </thead>
        <tbody>
          <?php foreach($clients as $c): ?>
            <tr>
              <td class="admin-url">
                <a href="/admin/clients/<?= e($c['id']) ?>"><?= e($c['client_id'] ?? '') ?></a>
                <?php if(!$c['active']): ?><span class="badge badge-danger">inactive</span><?php endif ?>
                <?php if(!$c['pkce_required']): ?><span class="badge badge-secondary">PKCE optional</span><?php endif ?>
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

  <h3>Their recent sign-ins</h3>
  <p class="small text-muted">Signing in to any application as <code><?= e($user->url) ?></code>, not only to the developer area.</p>
  <?php $this->insert('admin/_logins', ['logins' => $logins]) ?>
  <?php if(count($logins)): ?>
    <p><a href="/admin/logins?<?= e(http_build_query(['me' => $user->url])) ?>">All of their sign-ins</a></p>
  <?php endif ?>

</div>
