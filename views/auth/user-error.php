<? $this->layout('layout', ['title' => $title]) ?>

<div class="container container-narrow">

  <? if($error): ?>
    <div class="alert alert-warning"><?= $error ?></div>
  <? endif ?>

  <? if(isset($opts['found'])): ?>
    <p>The following links were found on your website, but are not supported authentication options.</p>
    <ul>
    <? foreach($opts['found'] as $f): ?>
      <li><a href="<?= e($f) ?>"><?= e($f) ?></a></li>
    <? endforeach ?>
    </ul>
  <? endif ?>

  <? if(isset($opts['me'])): ?>
    <p>We got an error trying to connect to <code><?= e($opts['me']) ?></code></p>
  <? endif ?>

  <? if(isset($opts['response'])): ?>
    <pre><?= e($opts['response']) ?></pre>
  <? endif ?>

  <p>View the <a href="/setup">setup instructions</a> for more information.</p>

</div>
