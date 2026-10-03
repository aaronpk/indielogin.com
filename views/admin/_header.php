<?php
/**
 * The heading, tab strip and flash messages every admin page starts with.
 *
 * @var string      $tab     Which tab is current: overview, activity, clients, users, logins.
 * @var object      $admin   The signed-in admin's users row.
 * @var string|bool $error
 * @var string|bool $success
 */
$tabs = [
  'overview' => ['/admin', 'Overview'],
  'activity' => ['/admin/activity', 'Activity'],
  'clients' => ['/admin/clients', 'Clients'],
  'users' => ['/admin/users', 'Users'],
  'logins' => ['/admin/logins', 'Sign-ins'],
];
?>
<div class="d-flex justify-content-between align-items-baseline">
  <h1>Admin</h1>
  <div class="text-muted small">
    Signed in as <b><?= e(\p3k\url\display_url($admin->url)) ?></b> &middot;
    <a href="/developers/clients">Your applications</a>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <?php foreach($tabs as $key => [$href, $label]): ?>
    <li class="nav-item">
      <a class="nav-link<?= $key === $tab ? ' active' : '' ?>" href="<?= $href ?>"<?= $key === $tab ? ' aria-current="page"' : '' ?>><?= $label ?></a>
    </li>
  <?php endforeach ?>
</ul>

<?php if($error): ?>
  <div class="alert alert-warning"><?= $error ?></div>
<?php endif ?>

<?php if($success): ?>
  <div class="alert alert-success"><?= $success ?></div>
<?php endif ?>
