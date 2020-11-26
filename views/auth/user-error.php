<?php $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow">

  <?php if($error): ?>
    <div class="alert alert-warning"><?= $error ?></div>
  <?php endif ?>

  <?php if(isset($opts['found'])): ?>
    <p>The following links were found on your website, but are not supported authentication options.</p>
    <ul>
    <?php foreach($opts['found'] as $f): ?>
      <li><a href="<?= e($f) ?>"><?= e($f) ?></a></li>
    <?php endforeach ?>
    </ul>
  <?php endif ?>

  <?php if(isset($opts['me'])): ?>
    <p>We got an error trying to connect to <code><?= e($opts['me']) ?></code></p>
  <?php endif ?>

  <?php if(isset($opts['response_code']) && $opts['response_code']): ?>
    <p>Response Code: <code><?= $opts['response_code'] ?></code></p>
  <?php endif ?>

  <?php if(isset($opts['response']) && $opts['response']): ?>
    <p>Response Body</p>
    <pre><?= e($opts['response']) ?></pre>
  <?php endif ?>

  <?php if(isset($opts['error_description'])): ?>
    <p>Error Details</p>
    <pre><?= e($opts['error_description']) ?></pre>
  <?php endif ?>

  <p>View the <a href="/setup">setup instructions</a> for more information.</p>

</div>
