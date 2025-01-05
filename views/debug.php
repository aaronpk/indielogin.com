<?php $this->layout('layout', ['title' => $title]) ?>

<div class="cover-container">

    <main>
      <p class="lead">
        <h2 class="cover-heading">Debug</h2>
        <?php if(isset($github_data)): ?>
          <pre><?= json_encode($github_data, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES) ?></pre>
          <pre><?= json_encode($social_accounts, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES) ?></pre>
          <a href="/debug/github" class="btn btn-outline-secondary">Debug GitHub profile data</a>
        <?php else: ?>
          <a href="/debug/github" class="btn btn-outline-secondary">Debug GitHub profile data</a>
        <?php endif ?>
      </p>
    </main>

</div>

<style>

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

pre {
  text-align: left;
}

</style>
