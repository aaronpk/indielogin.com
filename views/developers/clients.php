<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow setup">

  <h1>Your Applications</h1>

  <div class="mb-3">
    Signed in as <b><?= e(\p3k\url\display_url($user->url)) ?></b>.
    <form action="/developers/logout" method="post" class="d-inline">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <button type="submit" class="btn btn-link p-0 align-baseline">Sign out</button>
    </form>
  </div>

  <?php if($error): ?>
    <div class="alert alert-warning"><?= $error ?></div>
  <?php endif ?>

  <?php if($success): ?>
    <div class="alert alert-success"><?= $success ?></div>
  <?php endif ?>

  <section id="email">
    <h3>Contact Email</h3>

    <?php if(!$user->email): ?>
      <p>Add an email address so we have a way to reach you about your applications. This is required before you can register one.</p>
    <?php endif ?>

    <form action="/developers/profile" method="post" class="form-inline">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label class="sr-only" for="email">Email address</label>
      <input id="email" type="email" name="email" class="form-control mr-2" placeholder="you@example.com"
             value="<?= e($user->email ?? '') ?>" required>
      <button type="submit" class="btn btn-secondary">Save</button>
    </form>
  </section>

  <section id="register">
    <h3>Register an Application</h3>

    <?php if(!$user->email): ?>
      <p class="text-muted">Add your email address above to register an application.</p>
    <?php else: ?>
      <p>Enter the URL of your application. This is the <code>client_id</code> it will send when signing people in, and its <code>redirect_uri</code> will have to be on the same domain or a subdomain of it.</p>

      <form action="/developers/clients" method="post" class="form-inline">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <label class="sr-only" for="client_id">Application URL</label>
        <input id="client_id" type="url" name="client_id" class="form-control mr-2" placeholder="https://example.com/" required>
        <button type="submit" class="btn btn-primary">Register</button>
      </form>
    <?php endif ?>
  </section>

  <section id="clients">
    <h3>Registered Applications</h3>

    <?php if(!count($clients)): ?>
      <p class="text-muted">You haven't registered any applications yet.</p>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>Client ID</th>
            <th>Registered</th>
            <th>Last used</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($clients as $client): ?>
            <tr>
              <td>
                <code><?= e($client->client_id) ?></code>
                <?php if(!$client->active): ?>
                  <div class="alert alert-warning mt-2 mb-0">
                    <b>This application has been deactivated</b> and can no longer sign people in.
                    Please <a href="https://github.com/aaronpk/IndieLogin.com/issues">get in touch</a> if you think this is a mistake.
                  </div>
                <?php endif ?>
              </td>
              <td><?= $client->date_created ? e(display_date('Y-m-d', $client->date_created)) : '<span class="text-muted">&mdash;</span>' ?></td>
              <td><?= $client->date_last_used ? e(display_date('Y-m-d', $client->date_last_used)) : '<span class="text-muted">never</span>' ?></td>
              <td class="text-right">
                <?php if(!$client->date_last_used && $client->client_id !== getenv('BASE_URL')): ?>
                  <form action="/developers/clients/delete" method="post"
                        onsubmit="return confirm('Delete <?= e($client->client_id) ?>?')">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="id" value="<?= e($client->id) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete this application"
                            aria-label="Delete <?= e($client->client_id) ?>">&times;</button>
                  </form>
                <?php endif ?>
              </td>
            </tr>
          <?php endforeach ?>
        </tbody>
      </table>

      <p class="text-muted">
        Your application must send its <code>client_id</code> exactly as shown above, including the trailing slash.
        An application can be deleted until the first time it signs someone in.
      </p>
    <?php endif ?>
  </section>

  <p><a href="/developers">Back to the developer docs</a></p>

</div>
