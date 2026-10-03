<?php
/**
 * Each month's sign-ins split by provider, as shares of that month. Every
 * provider keeps its color whatever range is shown; the rest are "other".
 *
 * @var array  $months    Y-m => month row with 'signins' and 'providers'.
 * @var array  $providers key => label, in color order.
 * @var string $current
 */
require_once __DIR__.'/_chart_helpers.php';

$series = $providers + ['other' => 'Other'];
$keys = array_keys($series);

// Leave out providers nobody used in this range, without moving anyone's color
$used = array_filter($keys, function($k) use($months) {
  foreach($months as $row)
    if(($row['providers'][$k] ?? 0) > 0) return true;
  return false;
});
$slotOf = array_flip($keys);

$W = 900; $H = 220;
$left = 52; $right = 8; $top = 10; $bottom = 26;
$plotW = $W - $left - $right;
$plotH = $H - $top - $bottom;
$n = max(1, count($months));
$slot = $plotW / $n;
$barW = max(1, min(24, $slot - 2));
$y = fn($share) => $top + $plotH - $share * $plotH;
?>
<figure class="admin-chart" id="chart-providers">
  <figcaption>
    <span class="admin-chart-title">Sign-ins by provider</span>
    <span class="admin-chart-note">Share of each month's sign-ins; the lighter column is the month in progress. "Remembered" is someone continuing as the account this browser last signed in with.</span>
  </figcaption>
  <ul class="admin-legend">
    <?php foreach($used as $k): ?>
      <li><span class="swatch series-<?= $k === 'other' ? 'other' : $slotOf[$k] + 1 ?>"></span><?= e($series[$k]) ?></li>
    <?php endforeach ?>
  </ul>
  <div class="admin-chart-plot">
    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Share of sign-ins by provider, per month. The same figures are in the table below.">
      <?php foreach([0, 0.25, 0.5, 0.75, 1] as $t): ?>
        <line class="<?= $t == 0 ? 'axis' : 'grid' ?>" x1="<?= $left ?>" x2="<?= $W - $right ?>" y1="<?= round($y($t), 1) ?>" y2="<?= round($y($t), 1) ?>"/>
        <text class="tick" x="<?= $left - 8 ?>" y="<?= round($y($t), 1) ?>" text-anchor="end" dominant-baseline="middle"><?= round($t * 100) ?>%</text>
      <?php endforeach ?>

      <?php $i = 0; foreach($months as $month => $row): ?>
        <?php
          $x = $left + $i * $slot + ($slot - $barW) / 2;
          $total = $row['signins'];
          $label = admin_chart_axis_label($month, $slot);
          $parts = [];
          foreach($used as $k)
            if(($row['providers'][$k] ?? 0) > 0) $parts[$k] = $row['providers'][$k];
          $topKey = array_key_last($parts);
          $base = 0;
          $tip = [];
        ?>
        <g<?= $month === $current ? ' class="partial"' : '' ?>>
        <?php foreach($parts as $k => $count): ?>
          <?php
            $share = $count / $total;
            $y0 = $y($base); $y1 = $y($base + $share);
            $base += $share;
            $tip[] = $series[$k].': '.number_format($count).' ('.round(100 * $share).'%)';
            // A 2px gap in the surface color between segments; a sliver too
            // thin to survive it is left to the tooltip and the table
            $h = $y0 - $y1 - ($k === $topKey ? 0 : 2);
            if($h < 0.5) continue;
          ?>
          <?php if($k === $topKey): ?>
            <path class="series-<?= $k === 'other' ? 'other' : $slotOf[$k] + 1 ?>" d="<?= admin_chart_column($x, $y1, $barW, $h) ?>"/>
          <?php else: ?>
            <rect class="series-<?= $k === 'other' ? 'other' : $slotOf[$k] + 1 ?>" x="<?= round($x, 2) ?>" y="<?= round($y1 + 2, 2) ?>" width="<?= round($barW, 2) ?>" height="<?= round($h, 2) ?>"/>
          <?php endif ?>
        <?php endforeach ?>
        </g>
        <?php if($label !== null): ?>
          <text class="tick" x="<?= round($left + ($i + 0.5) * $slot, 1) ?>" y="<?= $H - 8 ?>" text-anchor="middle"><?= e($label) ?></text>
        <?php endif ?>
        <rect class="hit" x="<?= round($left + $i * $slot, 2) ?>" y="<?= $top ?>" width="<?= round($slot, 2) ?>" height="<?= $plotH ?>" tabindex="0"
              data-label="<?= e(admin_chart_month_label($month, true).($month === $current ? ', so far' : '')) ?>"
              data-value="<?= e(number_format($total).' sign-ins') ?>"
              data-extra="<?= e(implode("\n", array_reverse($tip))) ?>"
              aria-label="<?= e(admin_chart_month_label($month, true).': '.implode(', ', array_reverse($tip))) ?>"/>
      <?php $i++; endforeach ?>
    </svg>
  </div>
</figure>
