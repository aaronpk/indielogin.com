<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container">

  <p>Log in as <?= $me ?></p>

  <ul>
  <?php foreach($choices as $choice): ?>
    <li><a class="btn btn-info" href="/select?code=<?= $choice['code'] ?>" role="button"><?= $choice['provider']['display'] ?></a></li>
  <?php endforeach ?>
  </ul>

  <p>Logging in to <?= $client_id ?></p>

  <p>You will be redirected to <?= $redirect_uri ?></p>

</div>
