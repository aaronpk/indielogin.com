<?php $this->layout('layout', ['title' => $title, 'nofooter' => true]) ?>

<div class="container container-narrow">

  <div class="alert alert-warning" role="alert">
    <strong><?= e(display_url_host($me)) ?> signs in through indieauth.com, which is being replaced.</strong>
  </div>

  <p>
    Your website's <code>authorization_endpoint</code> points at indieauth.com. It still works, and
    this sign-in will go through as usual — but that service is being retired, so at some point you
    will want to move your site to another IndieAuth server.
  </p>

  <?php if($replacement): ?>
    <p>
      <a href="<?= e($replacement['url']) ?>"><?= e($replacement['name']) ?></a> is its replacement for
      websites like yours. Moving is one edit to your home page, and you can keep your existing tags
      while you do it.
    </p>
  <?php endif ?>

  <p>
    You will be given plenty of notice before indieauth.com shuts down.
  </p>

  <form method="post" action="/continue">
    <input type="hidden" name="code" value="<?= e($code) ?>">
    <button type="submit" class="btn btn-primary">Continue signing in</button>
  </form>

  <div class="login-details">
    <p>Logging in to <a href="<?= e($client_id) ?>"><?= e($client_id) ?></a></p>

    <p class="redirect_uri">You will be redirected to <?= e($redirect_uri) ?></p>
  </div>

</div>
