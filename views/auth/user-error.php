<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container">

  <h3>Error</h3>

  <ul>
  <?php foreach($errors as $error): ?>
    <li><?= e($error) ?></li>
  <?php endforeach ?>
  </ul>

</div>
