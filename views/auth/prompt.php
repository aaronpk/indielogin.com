<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container">

  <p>Log in as <?= $me ?></p>

  <form action="/select" method="post">
    <input type="hidden" name="code" value="<?= $code ?>">
    <input type="submit" value="Continue" class="btn btn-primary">
  </form>

  <p>Logging in to <?= $client_id ?></p>

  <p>You will be redirected to <?= $redirect_uri ?></p>

  <p><a href="<?= $switch_account ?>">Log in as a different user</a></p>

</div>
