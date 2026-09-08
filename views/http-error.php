<?php $this->layout('layout', ['title' => $title]) ?>

<div class="position-relative overflow-hidden p-3 p-md-5 m-md-3 text-center bg-light">
  <div class="col-md-5 p-lg-5 mx-auto my-5">

    <h1 class="display-4 font-weight-normal"><?= e($status) ?></h1>

    <p class="lead font-weight-normal"><?= e($message) ?></p>

    <p><a href="/">Go to the home page</a></p>

  </div>
</div>
