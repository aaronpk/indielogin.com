<?php $this->layout('layout', ['title' => $title]) ?>

<div class="cover-container">

    <main>
      <p class="lead">
        <h2 class="cover-heading">Debug</h2>
        <?php if(isset($github_data)): ?>
          <pre><?= json_encode($github_data, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES) ?></pre>
          <pre><?= json_encode($social_accounts, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES) ?></pre>
        <?php elseif(isset($gitlab_data)): ?>
          <pre><?= json_encode($gitlab_data, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES) ?></pre>
        <?php elseif(isset($codeberg_data)): ?>
          <pre><?= json_encode($codeberg_data, JSON_PRETTY_PRINT+JSON_UNESCAPED_SLASHES) ?></pre>
        <?php endif ?>
        <a href="/debug/github" class="btn btn-outline-secondary">Debug GitHub profile data</a>
        <a href="/debug/gitlab" class="btn btn-outline-secondary">Debug GitLab profile data</a>
        <a href="/debug/codeberg" class="btn btn-outline-secondary">Debug Codeberg profile data</a>
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
