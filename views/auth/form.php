<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container">

  <form action="/auth" method="get">
    <div class="form-group">
      <input type="url" placeholder="example.com" name="me" class="form-control">
    </div>

    <input type="hidden" name="client_id" value="<?= $client_id ?>">
    <input type="hidden" name="redirect_uri" value="<?= $redirect_uri ?>">
    <input type="hidden" name="state" value="<?= $state ?>">

    <button class="btn btn-outline-secondary">Sign In</button>
  </form>

  <!-- TODO: add short docs like on https://indieauth.com/auth?redirect_uri=foo -->

</div>
