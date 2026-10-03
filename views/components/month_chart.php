<?php
/**
 * One measure per month as columns. A single series, so no legend: the title
 * says what is plotted.
 *
 * @var string $id      Element id, for the chart's table link.
 * @var string $title
 * @var string $note    Optional, under the title.
 * @var array  $values  Y-m => int, oldest first.
 * @var string $unit    What one of them is called, e.g. "sign-ins".
 * @var array  $extra   Optional Y-m => string, a second line in the tooltip.
 * @var string $current The month in progress, drawn lighter.
 * @var int    $width   Optional: the drawing's width, smaller for a chart in
 *                      a half-width column so its text is not shrunk.
 */
require_once __DIR__.'/chart_helpers.php';

$extra = $extra ?? [];
$note = $note ?? '';

$W = $width ?? 900; $H = 200;
$left = 52; $right = 8; $top = 10; $bottom = 26;
$plotW = $W - $left - $right;
$plotH = $H - $top - $bottom;

$n = max(1, count($values));
[$yMax, $yStep] = chart_scale(max($values ?: [0]));
$slot = $plotW / $n;
$barW = max(1, min(24, $slot - 2));
$y = fn($v) => $top + $plotH - ($v / $yMax) * $plotH;
?>
<figure class="chart" id="<?= e($id) ?>">
  <figcaption>
    <span class="chart-title"><?= e($title) ?></span>
    <?php if($note): ?><span class="chart-note"><?= e($note) ?></span><?php endif ?>
  </figcaption>
  <div class="chart-plot">
    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="<?= e($title) ?>, per month. The same figures are in the table below.">
      <?php for($t = 0; $t <= $yMax; $t += $yStep): ?>
        <line class="<?= $t === 0 ? 'axis' : 'grid' ?>" x1="<?= $left ?>" x2="<?= $W - $right ?>" y1="<?= round($y($t), 1) ?>" y2="<?= round($y($t), 1) ?>"/>
        <text class="tick" x="<?= $left - 8 ?>" y="<?= round($y($t), 1) ?>" text-anchor="end" dominant-baseline="middle"><?= number_format($t) ?></text>
      <?php endfor ?>

      <?php $i = 0; foreach($values as $month => $v): ?>
        <?php
          $x = $left + $i * $slot + ($slot - $barW) / 2;
          $label = chart_axis_label($month, $slot);
          $is_current = $month === $current;
        ?>
        <?php if($v > 0): ?>
          <path class="mark<?= $is_current ? ' mark-partial' : '' ?>" d="<?= chart_column($x, $y($v), $barW, $top + $plotH - $y($v)) ?>"/>
        <?php endif ?>
        <?php if($label !== null): ?>
          <text class="tick" x="<?= round($left + ($i + 0.5) * $slot, 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= e($label) ?></text>
        <?php endif ?>
        <rect class="hit" x="<?= round($left + $i * $slot, 2) ?>" y="<?= $top ?>" width="<?= round($slot, 2) ?>" height="<?= $plotH ?>" tabindex="0"
              data-label="<?= e(chart_month_label($month, true).($is_current ? ', so far' : '')) ?>"
              data-value="<?= e(number_format($v).' '.$unit) ?>"
              <?php if(isset($extra[$month])): ?>data-extra="<?= e($extra[$month]) ?>"<?php endif ?>
              aria-label="<?= e(chart_month_label($month, true).': '.number_format($v).' '.$unit) ?>"/>
      <?php $i++; endforeach ?>
    </svg>
  </div>
</figure>
