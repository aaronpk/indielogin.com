<?php $this->layout('layout', ['title' => $title]) ?>

<div class="cover-container">

    <main>
      <h2 class="cover-heading">Congrats!</h2>
      <p class="lead">You successfully authenticated as <b><?= $me ?></b></p>
      <p><a href="/">Back</a></p>
    </main>

</div>

<style>
html,
body {
  height: 100%;
}

nav.mb-4 {
  margin-bottom: 0 !important;
}

.cover-container {
  height: calc(100% - 56px);
  width: 100%;
  text-align: center;
  display: -ms-flexbox;
  display: flex;
  align-items: center;
  color: #222;
  box-shadow: inset 0 0 5rem rgba(0, 0, 0, .1);
}

main {
  width: 100%;
}

</style>
