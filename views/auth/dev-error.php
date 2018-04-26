<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow">

  <div class="alert alert-warning">If you're seeing this, the developer of the application you're using did something wrong. Below are more details you can provide to the developer.</div>

  <h3>Request Error</h3>

  <p><b>There was a problem with the parameters of this request.</b></p>

  <ul>
  <?php foreach($errors as $error): ?>
    <li><?= e($error) ?></li>
  <?php endforeach ?>
  </ul>

</div>
