<?php
/**
 * Previous and next links that keep the rest of the query string.
 *
 * @var int  $page
 * @var bool $more  Whether there is a page after this one.
 */
$link = function($n) {
  $query = $_GET;
  $query['page'] = $n;
  return '?'.http_build_query($query);
};
?>
<?php if($page > 1 || $more): ?>
  <nav aria-label="Pages">
    <ul class="pagination">
      <li class="page-item<?= $page > 1 ? '' : ' disabled' ?>">
        <a class="page-link" href="<?= $page > 1 ? e($link($page - 1)) : '#' ?>">&larr; Newer</a>
      </li>
      <li class="page-item disabled"><span class="page-link">Page <?= $page ?></span></li>
      <li class="page-item<?= $more ? '' : ' disabled' ?>">
        <a class="page-link" href="<?= $more ? e($link($page + 1)) : '#' ?>">Older &rarr;</a>
      </li>
    </ul>
  </nav>
<?php endif ?>
