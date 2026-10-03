<?php
/**
 * Geometry shared by the month charts. Included with require_once, since a
 * page draws several charts.
 */

// A clean upper bound for a y axis, and the step between its ticks, so that
// the ticks land on round numbers: 0 / 250 / 500 / 750 / 1,000.
function chart_scale($max, $ticks = 4) {
  if($max <= 0)
    return [$ticks, 1];

  $raw = $max / $ticks;
  $magnitude = pow(10, floor(log10($raw)));
  // 2.5 only once it makes whole numbers (25, 250...), never 2.5 itself
  foreach($magnitude >= 10 ? [1, 2, 2.5, 5, 10] : [1, 2, 5, 10] as $m) {
    $step = $m * $magnitude;
    if($step >= $raw)
      break;
  }

  // Counts are whole, so a step below 1 would only repeat tick labels
  $step = max(1, $step);

  return [$step * $ticks, $step];
}

// A column rising from the baseline at $y + $h, with its top corners rounded
// and its base square.
function chart_column($x, $y, $w, $h, $r = 4) {
  $r = min($r, $w / 2, $h);
  $f = fn($n) => round($n, 2);

  return 'M'.$f($x).','.$f($y + $h)
    .' V'.$f($y + $r)
    .' Q'.$f($x).','.$f($y).' '.$f($x + $r).','.$f($y)
    .' H'.$f($x + $w - $r)
    .' Q'.$f($x + $w).','.$f($y).' '.$f($x + $w).','.$f($y + $r)
    .' V'.$f($y + $h).' Z';
}

function chart_month_label($month, $long = false) {
  $t = strtotime($month.'-01 00:00:00 UTC');
  return gmdate($long ? 'F Y' : 'M Y', $t);
}

// Which months get a label under the axis: every quarter when there is room
// between them for "Jan 2025", otherwise each January. With more than about
// ten years even that crowds, so then every other year.
function chart_axis_label($month, $slot) {
  $m = (int)substr($month, 5, 2);
  $year = substr($month, 0, 4);

  if($slot * 12 < 70)
    return ($m === 1 && $year % 2 == 0) ? $year : null;

  if($slot * 3 >= 60)
    return in_array($m, [1, 4, 7, 10], true) ? gmdate('M', strtotime($month.'-01')).($m === 1 ? ' '.$year : '') : null;

  return $m === 1 ? $year : null;
}
