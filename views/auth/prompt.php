<?php $this->layout('layout', ['title' => $title, 'nofooter' => true]) ?>

<div class="container container-narrow">

  <div class="login-details">
    <p>Log in as <a href="<?= $me ?>"><?= $me ?></a></p>

    <form action="/select" method="post">
      <input type="hidden" name="code" value="<?= $code ?>">
      <input type="submit" value="Continue" class="btn btn-primary">
    </form>
  </div>

  <div>
    <p>Logging in to <a href="<?= $client_id ?>"><?= $client_id ?></a></p>

    <p class="redirect_uri">You will be redirected to <?= $redirect_uri ?></p>

    <p style="margin-top: 1em;"><a href="<?= $switch_account ?>">Log in as a different user</a></p>
  </div>

</div>
