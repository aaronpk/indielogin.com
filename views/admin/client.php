<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * One client, and the settings that used to be changed by hand.
 *
 * @var object       $client
 * @var object|false $owner
 * @var bool         $is_self       Whether this is the site's own client.
 * @var array        $redirect_uris
 * @var array        $logins
 * @var int          $recent_logins
 * @var int          $days
 * @var string       $csrf
 */
$action = '/admin/clients/'.e($client->id);
$client_host = parse_url($client->client_id ?? '', PHP_URL_HOST);
?>

<div class="container admin">

  <?php $this->insert('admin/_header', compact('tab', 'admin', 'error', 'success')) ?>

  <h2 class="admin-url">
    <?= e($client->client_id ?? '') ?>
    <?php if(!$client->active): ?><span class="badge badge-danger">inactive</span><?php endif ?>
  </h2>

  <dl class="row">
    <dt class="col-sm-3">Owner</dt>
    <dd class="col-sm-9">
      <?php if($owner): ?>
        <a href="/admin/users/<?= e($owner->id) ?>"><?= e($owner->url) ?></a>
        <?php if($owner->email): ?><span class="text-muted">&middot; <?= e($owner->email) ?></span><?php endif ?>
      <?php else: ?>
        <span class="text-muted">No developer account</span>
      <?php endif ?>
    </dd>

    <?php if($client->email): ?>
      <dt class="col-sm-3">Legacy email</dt>
      <dd class="col-sm-9"><?= e($client->email) ?> <span class="small text-muted">(from before developer accounts existed)</span></dd>
    <?php endif ?>

    <dt class="col-sm-3">Registered</dt>
    <dd class="col-sm-9"><?= $client->date_created ? e($client->date_created) : '<span class="text-muted">unknown</span>' ?></dd>

    <dt class="col-sm-3">Last used</dt>
    <dd class="col-sm-9"><?= $client->date_last_used ? e($client->date_last_used) : '<span class="text-muted">never</span>' ?></dd>

    <dt class="col-sm-3">Sign-ins, <?= $days ?> days</dt>
    <dd class="col-sm-9"><a href="/admin/logins?<?= e(http_build_query(['client_id' => $client->client_id])) ?>"><?= number_format($recent_logins) ?></a></dd>
  </dl>

  <section class="admin-section">
    <h3>Status</h3>

    <form action="<?= $action ?>/active" method="post" class="mb-3"
          <?php if($client->active): ?>onsubmit="return confirm(<?= e(json_encode('Deactivate '.$client->client_id.'? It will no longer be able to sign anyone in.')) ?>)"<?php endif ?>>
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <?php if($client->active): ?>
        <p>Active: this client can sign people in.</p>
        <?php if($is_self): ?>
          <p class="text-muted mb-0">This is the site's own client, used by the developer area and the demo, so it cannot be deactivated here.</p>
        <?php else: ?>
          <input type="hidden" name="active" value="0">
          <button type="submit" class="btn btn-outline-danger">Deactivate</button>
        <?php endif ?>
      <?php else: ?>
        <p>Deactivated: sign-in requests from this client are refused, and its owner sees a warning on their applications page.</p>
        <input type="hidden" name="active" value="1">
        <button type="submit" class="btn btn-success">Reactivate</button>
      <?php endif ?>
    </form>

    <form action="<?= $action ?>/pkce" method="post">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <?php if($client->pkce_required): ?>
        <p>PKCE is required: requests without a <code>code_challenge</code> are refused.</p>
        <input type="hidden" name="pkce_required" value="0">
        <button type="submit" class="btn btn-outline-secondary">Stop requiring PKCE</button>
      <?php else: ?>
        <p>PKCE is optional for this client. Clients registered before PKCE was required were grandfathered in this way.</p>
        <input type="hidden" name="pkce_required" value="1">
        <button type="submit" class="btn btn-outline-primary">Require PKCE</button>
      <?php endif ?>
    </form>
  </section>

  <section class="admin-section">
    <h3>Redirect URIs</h3>

    <p>
      A <code>redirect_uri</code> on <code><?= e($client_host ?: '?') ?></code> or any subdomain of it is always allowed.
      One on any other host has to be listed here, and must match exactly, character for character.
    </p>

    <?php if(count($redirect_uris)): ?>
      <ul class="list-group mb-3">
        <?php foreach($redirect_uris as $uri): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <code class="admin-url"><?= e($uri->redirect_uri ?? '') ?></code>
            <form action="<?= $action ?>/redirect_uris/delete" method="post"
                  onsubmit="return confirm(<?= e(json_encode('Remove '.$uri->redirect_uri.'?')) ?>)">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="id" value="<?= e($uri->id) ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="Remove <?= e($uri->redirect_uri ?? '') ?>">&times;</button>
            </form>
          </li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <p class="text-muted">None registered.</p>
    <?php endif ?>

    <form action="<?= $action ?>/redirect_uris" method="post" class="form-inline">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label class="sr-only" for="redirect_uri">Redirect URI</label>
      <input id="redirect_uri" type="url" name="redirect_uri" class="form-control mr-2 admin-search"
             placeholder="https://other.example/callback" required>
      <button type="submit" class="btn btn-primary">Add</button>
    </form>
  </section>

  <h3>Recent sign-ins</h3>
  <?php $this->insert('admin/_logins', ['logins' => $logins]) ?>
  <?php if(count($logins)): ?>
    <p><a href="/admin/logins?<?= e(http_build_query(['client_id' => $client->client_id])) ?>">All sign-ins for this client</a></p>
  <?php endif ?>

</div>
