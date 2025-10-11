<?php $this->layout('layout', ['title' => $title, 'nofooter' => true]) ?>

<div class="container container-narrow choose-profile">

  <p>Log in as <a href="<?= $me ?>"><?= $me ?></a></p>

  <p>Choose an authentication provider to continue logging in.</p>

  <ul>
  <?php foreach($choices as $choice): ?>
    <li>
      <a class="btn btn-info" href="/select?code=<?= $choice['code'] ?>" role="button">
        <i class="<?= $choice['provider']['icon'] ?>"></i>
        <?= $choice['provider']['display'] ?>
      </a>
    </li>
  <?php endforeach ?>
  </ul>

  <div class="login-details">
    <p>Logging in to <a href="<?= $client_id ?>"><?= $client_id ?></a></p>

    <p class="redirect_uri">You will be redirected to <?= $redirect_uri ?></p>
  </div>

</div>
