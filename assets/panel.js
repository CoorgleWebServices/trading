/* Binance Micro-Trader panel — plain JS, no libraries, no inline handlers (CSP default-src 'self').
 * - refreshes dashboard values from index.php?api=status every 20 s (data-field / data-level / data-table / data-sparkline)
 * - confirm dialogs for forms with data-confirm (optionally only when data-confirm-field has data-confirm-value)
 */
(function () {
  'use strict';

  var SVG_NS = 'http://www.w3.org/2000/svg';
  var LEVELS = ['ok', 'warn', 'danger', 'muted', 'info'];

  function setLevelClass(el, level, prefix) {
    var i;
    for (i = 0; i < LEVELS.length; i++) {
      el.classList.remove(prefix + LEVELS[i]);
    }
    if (LEVELS.indexOf(level) === -1) {
      level = 'muted';
    }
    el.classList.add(prefix + level);
  }

  function applyText(map) {
    var els = document.querySelectorAll('[data-field]');
    var i, key;
    for (i = 0; i < els.length; i++) {
      key = els[i].getAttribute('data-field');
      if (map && Object.prototype.hasOwnProperty.call(map, key)) {
        els[i].textContent = map[key] === null || map[key] === undefined ? '' : String(map[key]);
      }
    }
  }

  function applyLevels(map) {
    var els = document.querySelectorAll('[data-level]');
    var i, key, prefix;
    for (i = 0; i < els.length; i++) {
      key = els[i].getAttribute('data-level');
      prefix = els[i].getAttribute('data-level-prefix') || 'pill-';
      if (map && Object.prototype.hasOwnProperty.call(map, key)) {
        setLevelClass(els[i], String(map[key]), prefix);
      }
    }
  }

  function applyShow(map) {
    var els = document.querySelectorAll('[data-show]');
    var i, key, on;
    if (!map) { return; }
    for (i = 0; i < els.length; i++) {
      key = els[i].getAttribute('data-show');
      if (key === 'no_position') {
        on = !map.position;
      } else if (Object.prototype.hasOwnProperty.call(map, key)) {
        on = !!map[key];
      } else {
        continue;
      }
      els[i].hidden = !on;
    }
  }

  /* The CSRF token of this page: every rendered page carries at least the logout form. */
  function csrfToken() {
    var el = document.querySelector('input[name="csrf"]');
    return el && el.value ? el.value : '';
  }

  function hiddenInput(name, value) {
    var i = document.createElement('input');
    i.type = 'hidden';
    i.name = name;
    i.value = value === null || value === undefined ? '' : String(value);
    return i;
  }

  /* Per-row action button: the same markup Panel::actionButton() renders server-side. */
  function buildActionForm(btn, label) {
    var form = document.createElement('form');
    var fields = btn.fields && typeof btn.fields === 'object' ? btn.fields : {};
    var button, k;
    form.setAttribute('method', 'post');
    form.setAttribute('action', 'index.php');
    form.className = 'inline';
    form.appendChild(hiddenInput('csrf', csrfToken()));
    form.appendChild(hiddenInput('action', btn.action));
    for (k in fields) {
      if (Object.prototype.hasOwnProperty.call(fields, k)) {
        form.appendChild(hiddenInput(k, fields[k]));
      }
    }
    button = document.createElement('button');
    button.type = 'submit';
    button.className = btn['class'] ? String(btn['class']) : 'btn btn-mini';
    button.textContent = label || 'Go';
    form.appendChild(button);
    return form;
  }

  /* Per-row "Assign to…" control: the same markup Panel::assignControl() renders server-side.
   * A select plus a submit button, never an inline onchange handler (CSP). */
  function buildAssignForm(spec, label) {
    var options = Array.isArray(spec.options) ? spec.options : [];
    var fields = spec.fields && typeof spec.fields === 'object' ? spec.fields : {};
    var form, select, opt, button, i, k, v, t, span;
    if (!spec.action || options.length === 0) {
      span = document.createElement('span');
      span.className = 'muted';
      span.textContent = spec.empty ? String(spec.empty) : '-';
      return span;
    }
    form = document.createElement('form');
    form.setAttribute('method', 'post');
    form.setAttribute('action', 'index.php');
    form.className = 'inline assign';
    form.appendChild(hiddenInput('csrf', csrfToken()));
    form.appendChild(hiddenInput('action', spec.action));
    for (k in fields) {
      if (Object.prototype.hasOwnProperty.call(fields, k)) {
        form.appendChild(hiddenInput(k, fields[k]));
      }
    }
    select = document.createElement('select');
    select.name = spec.name ? String(spec.name) : 'engine';
    select.className = 'assign-select';
    select.required = true;
    opt = document.createElement('option');
    opt.value = '';
    opt.selected = true;
    opt.disabled = true;
    opt.textContent = spec.placeholder ? String(spec.placeholder) : 'Assign to…';
    select.appendChild(opt);
    for (i = 0; i < options.length; i++) {
      v = options[i] && typeof options[i] === 'object' ? options[i].v : options[i];
      t = options[i] && typeof options[i] === 'object' && options[i].t ? options[i].t : v;
      if (v === null || v === undefined || String(v) === '') { continue; }
      opt = document.createElement('option');
      opt.value = String(v);
      opt.textContent = String(t);
      select.appendChild(opt);
    }
    form.appendChild(select);
    button = document.createElement('button');
    button.type = 'submit';
    button.className = 'btn btn-mini';
    button.textContent = label || 'Assign';
    form.appendChild(button);
    return form;
  }

  function buildCell(cell) {
    var td = document.createElement('td');
    var text, cls, svg, rect, wrap, span, pill;
    if (cell === null || cell === undefined) {
      return td;
    }
    if (typeof cell !== 'object') {
      td.textContent = String(cell);
      return td;
    }
    text = cell.t === null || cell.t === undefined ? '' : String(cell.t);
    cls = cell.c ? String(cell.c) : '';
    if (cls) {
      td.className = cls;
    }
    if (cell.bar !== undefined && cell.bar !== null) {
      wrap = document.createElement('span');
      wrap.className = 'bar-wrap';
      svg = document.createElementNS(SVG_NS, 'svg');
      svg.setAttribute('class', 'bar');
      svg.setAttribute('viewBox', '0 0 100 10');
      svg.setAttribute('preserveAspectRatio', 'none');
      svg.setAttribute('aria-hidden', 'true');
      rect = document.createElementNS(SVG_NS, 'rect');
      rect.setAttribute('x', '0');
      rect.setAttribute('y', '0');
      rect.setAttribute('width', String(Math.max(0, Math.min(100, Number(cell.bar) || 0))));
      rect.setAttribute('height', '10');
      svg.appendChild(rect);
      wrap.appendChild(svg);
      td.appendChild(wrap);
      span = document.createElement('span');
      span.className = 'bar-text';
      span.textContent = text;
      td.appendChild(span);
    } else if (cell.pill !== undefined && cell.pill !== null) {
      pill = document.createElement('span');
      pill.className = 'pill';
      setLevelClass(pill, String(cell.pill), 'pill-');
      pill.textContent = text;
      td.appendChild(pill);
    } else if (cell.btn && typeof cell.btn === 'object') {
      td.appendChild(buildActionForm(cell.btn, text));
    } else if (cell.assign && typeof cell.assign === 'object') {
      td.appendChild(buildAssignForm(cell.assign, text));
    } else {
      td.textContent = text;
    }
    return td;
  }

  function applyTables(tables) {
    var bodies = document.querySelectorAll('tbody[data-table]');
    var i, j, k, name, table, rows, tr, td, cols, cells, rowClass;
    if (!tables) { return; }
    for (i = 0; i < bodies.length; i++) {
      name = bodies[i].getAttribute('data-table');
      table = tables[name];
      if (!table || !Array.isArray(table.rows)) {
        continue;
      }
      rows = table.rows;
      while (bodies[i].firstChild) {
        bodies[i].removeChild(bodies[i].firstChild);
      }
      if (rows.length === 0) {
        cols = parseInt(bodies[i].getAttribute('data-cols') || table.cols || '1', 10);
        tr = document.createElement('tr');
        tr.className = 'empty';
        td = document.createElement('td');
        td.setAttribute('colspan', String(cols > 0 ? cols : 1));
        td.textContent = table.empty ? String(table.empty) : 'No data yet';
        tr.appendChild(td);
        bodies[i].appendChild(tr);
        continue;
      }
      for (j = 0; j < rows.length; j++) {
        cells = rows[j];
        rowClass = '';
        if (cells && !Array.isArray(cells) && typeof cells === 'object' && Array.isArray(cells.cells)) {
          rowClass = cells['class'] ? String(cells['class']) : '';
          cells = cells.cells;
        }
        if (!Array.isArray(cells)) { continue; }
        tr = document.createElement('tr');
        if (rowClass) {
          tr.className = rowClass;
        }
        for (k = 0; k < cells.length; k++) {
          tr.appendChild(buildCell(cells[k]));
        }
        bodies[i].appendChild(tr);
      }
    }
  }

  function applySparkline(spark) {
    var line = document.querySelector('[data-sparkline="points"]');
    var area = document.querySelector('[data-sparkline="area"]');
    if (!spark) { return; }
    if (line && typeof spark.points === 'string') {
      line.setAttribute('points', spark.points);
    }
    if (area && typeof spark.area === 'string') {
      area.setAttribute('points', spark.area);
    }
  }

  /* Overlaid per-sleeve sparkline: one polyline per series, matched by data-sparkline-series. */
  function applyMultiSparkline(spark) {
    var lines = document.querySelectorAll('[data-sparkline-series]');
    var i, key, series;
    if (!spark || !spark.series) { return; }
    series = spark.series;
    for (i = 0; i < lines.length; i++) {
      key = lines[i].getAttribute('data-sparkline-series');
      if (Object.prototype.hasOwnProperty.call(series, key) && series[key] && typeof series[key].points === 'string') {
        lines[i].setAttribute('points', series[key].points);
      }
    }
  }

  function setRefreshInfo(text, cls) {
    var el = document.querySelector('[data-refresh-info]');
    if (!el) { return; }
    el.textContent = text;
    el.className = 'refresh-info' + (cls ? ' ' + cls : '');
  }

  function applyStatus(s) {
    if (!s || !s.ok) {
      setRefreshInfo('status unavailable', 'error');
      return;
    }
    applyText(s.text);
    applyLevels(s.levels);
    applyShow(s.show);
    applyTables(s.tables);
    applySparkline(s.sparkline);
    applyMultiSparkline(s.sleeve_sparkline);
    setRefreshInfo('updated ' + new Date().toLocaleTimeString() + ' · auto-refresh every 20 s', '');
  }

  var inFlight = false;

  function refresh() {
    if (inFlight || document.hidden) {
      return;
    }
    if (typeof window.fetch !== 'function') {
      return;
    }
    inFlight = true;
    window.fetch('index.php?api=status', { credentials: 'same-origin', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (r) {
        if (r.status === 401) {
          setRefreshInfo('session expired - reload to log in again', 'error');
          return null;
        }
        if (!r.ok) {
          throw new Error('HTTP ' + r.status);
        }
        return r.json();
      })
      .then(function (data) {
        inFlight = false;
        if (data) {
          applyStatus(data);
        }
      })
      .catch(function (err) {
        inFlight = false;
        setRefreshInfo('refresh failed (' + (err && err.message ? err.message : 'error') + ') - retrying', 'stale');
      });
  }

  function startAutoRefresh() {
    var body = document.body;
    var secs = parseInt(body.getAttribute('data-autorefresh') || '0', 10);
    if (!secs || secs < 5) {
      return;
    }
    window.setInterval(refresh, secs * 1000);
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden) {
        refresh();
      }
    });
  }

  function needsConfirm(form, submitter) {
    var field, value, el;
    if (submitter && submitter.hasAttribute('data-skip-confirm')) {
      return false;
    }
    field = form.getAttribute('data-confirm-field');
    if (!field) {
      return true;
    }
    value = form.getAttribute('data-confirm-value') || '';
    el = form.elements[field];
    if (!el) {
      return false;
    }
    return String(el.value) === value;
  }

  function installConfirms() {
    var forms = document.querySelectorAll('form[data-confirm]');
    var i;
    function onSubmit(ev) {
      var form = ev.target;
      var submitter = ev.submitter || form.lastSubmitter || null;
      var msg = form.getAttribute('data-confirm') || 'Are you sure?';
      if (needsConfirm(form, submitter) && !window.confirm(msg)) {
        ev.preventDefault();
      }
    }
    function onClick(ev) {
      // remember which button was pressed for browsers without ev.submitter
      var t = ev.target;
      while (t && t !== this) {
        if (t.tagName === 'BUTTON' || (t.tagName === 'INPUT' && t.type === 'submit')) {
          this.lastSubmitter = t;
          return;
        }
        t = t.parentNode;
      }
    }
    for (i = 0; i < forms.length; i++) {
      forms[i].addEventListener('click', onClick);
      forms[i].addEventListener('submit', onSubmit);
    }
  }

  /* Settings → Engine: reveal only the selected engine's field group. The CSP forbids inline
   * scripts and inline style attributes, so visibility is the `is-hidden` CLASS from panel.css
   * and this is the only place that touches it. */
  function installEngineGroups() {
    var select = document.querySelector('[data-engine-select]');
    if (!select) {
      return;
    }
    function apply() {
      var groups = document.querySelectorAll('[data-engine-group]');
      var i;
      for (i = 0; i < groups.length; i++) {
        if (groups[i].getAttribute('data-engine-group') === String(select.value)) {
          groups[i].classList.remove('is-hidden');
        } else {
          groups[i].classList.add('is-hidden');
        }
      }
    }
    select.addEventListener('change', apply);
    apply();
  }

  /* Settings → Portfolio: the per-sleeve fields follow the portfolio_enabled checkbox. Same
   * rule as the engine groups - visibility is the `is-hidden` CLASS from panel.css, never an
   * inline style attribute, and never an inline handler. */
  function installPortfolioToggle() {
    var box = document.querySelector('input[type="checkbox"][name="portfolio_enabled"]');
    var fields = document.querySelectorAll('[data-portfolio-fields]');
    var i;
    if (!box || fields.length === 0) {
      return;
    }
    function apply() {
      for (i = 0; i < fields.length; i++) {
        if (box.checked) {
          fields[i].classList.remove('is-hidden');
        } else {
          fields[i].classList.add('is-hidden');
        }
      }
    }
    box.addEventListener('change', apply);
    apply();
  }

  function init() {
    installConfirms();
    installEngineGroups();
    installPortfolioToggle();
    startAutoRefresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
