// Tooltips for the admin charts. Each month's column carries its figures in
// data attributes on a hit area the full height of the plot, so the pointer
// only has to be over the month, not on the bar. The same figures are in the
// table under the charts, so nothing here is the only way to read a value.
(function() {
  var tip = document.querySelector('.admin-tooltip');
  if(!tip) return;

  function show(hit) {
    tip.textContent = '';

    var value = document.createElement('strong');
    value.textContent = hit.getAttribute('data-value');
    tip.appendChild(value);

    var label = document.createElement('div');
    label.className = 'admin-tooltip-label';
    label.textContent = hit.getAttribute('data-label');
    tip.appendChild(label);

    // On a chart with several series, data-keys names each line's color
    // class, in the same order, so every line gets a swatch matching its
    // segment and the legend
    var extra = hit.getAttribute('data-extra');
    var keys = (hit.getAttribute('data-keys') || '').split(' ');
    if(extra) {
      extra.split('\n').forEach(function(line, i) {
        var row = document.createElement('div');
        if(keys[i]) {
          var swatch = document.createElement('span');
          swatch.className = 'swatch ' + keys[i];
          row.appendChild(swatch);
        }
        row.appendChild(document.createTextNode(line));
        tip.appendChild(row);
      });
    }

    tip.hidden = false;

    var box = hit.getBoundingClientRect();
    var left = box.left + box.width / 2 + window.scrollX - tip.offsetWidth / 2;
    left = Math.max(window.scrollX + 8, Math.min(left, window.scrollX + document.documentElement.clientWidth - tip.offsetWidth - 8));
    var plot = hit.closest('svg').getBoundingClientRect();
    tip.style.left = left + 'px';
    tip.style.top = (plot.top + window.scrollY - tip.offsetHeight - 6) + 'px';

    document.querySelectorAll('.hit.active').forEach(function(el) { el.classList.remove('active'); });
    hit.classList.add('active');
  }

  function hide() {
    tip.hidden = true;
    document.querySelectorAll('.hit.active').forEach(function(el) { el.classList.remove('active'); });
  }

  document.querySelectorAll('.admin-chart .hit').forEach(function(hit) {
    hit.addEventListener('pointerenter', function() { show(hit); });
    hit.addEventListener('focus', function() { show(hit); });
    hit.addEventListener('pointerleave', hide);
    hit.addEventListener('blur', hide);
  });
})();
