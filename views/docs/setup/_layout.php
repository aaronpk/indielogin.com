<?php $this->layout('layout', ['title' => $title]) ?>
<?php
/**
 * The setup docs: a sidebar listing every page, the page itself, and links
 * to the pages either side of it. See Controller::SETUP_PAGES.
 *
 * @var string      $page     This page's slug.
 * @var array       $pages    slug => [title, group], in order.
 * @var string|null $previous
 * @var string|null $next
 */
$url = fn($slug) => $slug === 'overview' ? '/setup' : '/setup/'.$slug;
?>

<div class="container setup-docs">
  <div class="row">

    <nav class="col-md-3 setup-sidebar" aria-label="Setup">
      <?php // On a phone the list folds away behind this, so the page comes first ?>
      <button class="btn btn-outline-secondary btn-sm btn-block d-md-none text-left" type="button"
              data-toggle="collapse" data-target="#setup-pages" aria-expanded="false" aria-controls="setup-pages">
        Setup: <?= e($pages[$page][0]) ?> &#9662;
      </button>
      <div class="collapse d-md-block" id="setup-pages">
      <?php $group = false; ?>
      <?php foreach($pages as $slug => [$label, $pageGroup]): ?>
        <?php if($pageGroup !== $group): ?>
          <?php if($group !== false): ?></ul><?php endif ?>
          <?php if($pageGroup): ?><h6 class="setup-sidebar-group"><?= e($pageGroup) ?></h6><?php endif ?>
          <ul class="nav flex-column">
          <?php $group = $pageGroup; ?>
        <?php endif ?>
        <li class="nav-item">
          <a class="nav-link<?= $slug === $page ? ' active' : '' ?>" href="<?= $url($slug) ?>"<?= $slug === $page ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
        </li>
      <?php endforeach ?>
      </ul>
      </div>
    </nav>

    <div class="col-md-9 setup">
      <?= $this->section('content') ?>

      <nav class="setup-pager d-flex justify-content-between" aria-label="Previous and next">
        <span><?php if($previous): ?><a href="<?= $url($previous) ?>">&larr; <?= e($pages[$previous][0]) ?></a><?php endif ?></span>
        <span><?php if($next): ?><a href="<?= $url($next) ?>"><?= e($pages[$next][0]) ?> &rarr;</a><?php endif ?></span>
      </nav>
    </div>

  </div>
</div>
