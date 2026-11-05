// Everything on the page happens here, in your browser: tabs, page numbers and pins.
(function () {
  var PER_PAGE = (window.DJ && window.DJ.perPage) || 30;
  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var JOB_VIEWS = {
    'all':       function () { return true; },
    'india':     function (r) { return r.dataset.region === 'india'; },
    'abroad':    function (r) { return r.dataset.region === 'abroad'; },
    'remote':    function (r) { return r.dataset.mode === 'remote'; },
    'hybrid':    function (r) { return r.dataset.mode === 'hybrid'; },
    'in-office': function (r) { return r.dataset.mode === 'in-office'; },
    'dead':      function () { return true; }
  };
  var OTHER_VIEWS = ['log', 'about'];

  var listView = $('#listView'), logView = $('#logView'), aboutView = $('#aboutView');
  var liveTable = $('#liveTable'), deadTable = $('#deadTable');
  var emptyEl = $('#empty'), pagerEl = $('#pager');
  var view = 'all', page = 1, pendingScroll = false;

  /* ---------- pins (saved in this browser with localStorage) ---------- */
  var KEY = 'dj_pins_v1', pins = [];
  try { pins = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { pins = []; }
  function savePins() { try { localStorage.setItem(KEY, JSON.stringify(pins)); } catch (e) { /* storage blocked: pins just last until the page closes */ } }
  function isPinned(id) { return pins.some(function (p) { return p.id === id; }); }

  function renderPins() {
    var list = $('#pinList'); list.innerHTML = '';
    pins.forEach(function (p) {
      var row = $('tr[data-id="' + p.id + '"]');
      var gone = !row || row.closest('table') === deadTable;   // not on the page any more (or in the Dead zone) = no longer a live job
      var li = document.createElement('li'); if (gone) li.className = 'gone';
      var box = document.createElement('div');
      var a = document.createElement('a'); a.href = p.url; a.target = '_blank'; a.rel = 'nofollow noopener'; a.textContent = p.title;
      var s = document.createElement('small'); s.textContent = p.company + (gone ? ' · closed/inactive' : '');
      box.appendChild(a); box.appendChild(s);
      var x = document.createElement('button'); x.className = 'x'; x.type = 'button'; x.title = 'Unpin'; x.setAttribute('aria-label', 'Unpin'); x.textContent = '×';
      x.addEventListener('click', function () { togglePin(p.id); });
      li.appendChild(box); li.appendChild(x); list.appendChild(li);
    });
    $('#pinHint').hidden = pins.length > 0;
    $('#pinbox').classList.toggle('nopins', pins.length === 0);
    $$('tr[data-id]').forEach(function (r) {
      var b = $('.pinbtn', r), on = isPinned(r.dataset.id);
      if (!b) return;                                   // Dead zone rows have no star
      b.classList.toggle('on', on); b.innerHTML = on ? '&#9733;' : '&#9734;';
      b.title = on ? 'Unpin this job' : 'Pin this job';
    });
  }
  function togglePin(id) {
    if (isPinned(id)) pins = pins.filter(function (p) { return p.id !== id; });
    else {
      var r = $('tr[data-id="' + id + '"]');
      if (r) pins.unshift({ id: id, title: r.dataset.title, company: r.dataset.co, url: r.dataset.url });
    }
    savePins(); renderPins();
  }

  /* ---------- showing the right rows ---------- */
  function render() {
    var isList = JOB_VIEWS.hasOwnProperty(view);
    listView.hidden  = !isList;
    logView.hidden   = view !== 'log';
    aboutView.hidden = view !== 'about';
    $$('#tabs a').forEach(function (a) { a.classList.toggle('on', a.dataset.view === view); });
    if (!isList) { window.scrollTo(0, 0); return; }

    var isDead = view === 'dead';
    liveTable.hidden = isDead; deadTable.hidden = !isDead;
    $('#deadNote').hidden = !isDead;
    $('#intro').hidden = isDead;

    var table = isDead ? deadTable : liveTable;
    var inView = JOB_VIEWS[view];
    var rows = $$('tbody tr', table).filter(function (r) { return inView(r); });   // the rows that belong to this tab, in order
    var total = rows.length, pages = Math.max(1, Math.ceil(total / PER_PAGE));
    if (page > pages) page = pages;
    var from = (page - 1) * PER_PAGE, to = from + PER_PAGE;

    $$('tbody tr', table).forEach(function (r) { r.hidden = true; });
    rows.forEach(function (r, i) {
      if (i < from || i >= to) return;
      r.hidden = false;
      $('.rank', r).textContent = (i + 1) + '.';             // numbering carries on across pages (page 2 starts at 31)
      r.classList.toggle('z', (i - from) % 2 === 1);
    });

    emptyEl.hidden = total > 0;
    emptyEl.textContent = isDead ? 'Nothing in the Dead zone yet.' : 'No jobs here yet.';
    table.hidden = total === 0;
    renderPager(pages);
  }

  // Page buttons: Prev, 1 2 3 ... , Next. With many pages: first, last and the pages around the current one, with ... between.
  function renderPager(pages) {
    pagerEl.innerHTML = '';
    pagerEl.hidden = pages < 2;
    if (pages < 2) return;
    function add(label, target, extra) {                      // target = page number to link to, or 0 for a plain (non-clickable) label
      var el = document.createElement(target ? 'a' : 'span');
      el.className = 'pg' + (extra ? ' ' + extra : '');
      el.textContent = label;
      if (target) el.href = '#' + view + (target > 1 ? '/' + target : '');
      pagerEl.appendChild(el);
      return el;
    }
    add('‹ Prev', page > 1 ? page - 1 : 0, page > 1 ? '' : 'off');
    var last = 0;
    for (var n = 1; n <= pages; n++) {
      var keep = n === 1 || n === pages || Math.abs(n - page) <= 1 || (page <= 3 && n <= 4) || (page >= pages - 2 && n >= pages - 3);
      if (!keep) continue;
      if (n - last > 1) add('…', 0, 'gap');
      var el = add(String(n), n === page ? 0 : n, n === page ? 'on' : '');
      if (n === page) el.setAttribute('aria-current', 'page');
      last = n;
    }
    add('Next ›', page < pages ? page + 1 : 0, page < pages ? '' : 'off');
  }

  // The address looks like  #india  or  #india/2  (view, then page). A bad or too-big page number is tidied up.
  function go(h) {
    var parts = String(h || '').split('/'), v = parts[0], asked = parseInt(parts[1], 10);
    if (!JOB_VIEWS.hasOwnProperty(v) && OTHER_VIEWS.indexOf(v) === -1) v = 'all';
    view = v; page = asked > 0 ? asked : 1;
    render();
    var tidy = '#' + view + (page > 1 && JOB_VIEWS.hasOwnProperty(view) ? '/' + page : '');
    if (h && ('#' + h) !== tidy && history.replaceState) history.replaceState(null, '', tidy);
    if (pendingScroll) { pendingScroll = false; var card = $('#listView .card'); if (card && !listView.hidden) card.scrollIntoView(); }
  }

  /* ---------- wiring ---------- */
  window.addEventListener('hashchange', function () { go(location.hash.slice(1)); });
  document.addEventListener('click', function (e) {
    var pin = e.target.closest('.pinbtn');
    if (pin) togglePin(pin.closest('tr').dataset.id);
    if (e.target.closest('#pager a')) pendingScroll = true;       // after changing page, bring the top of the table into view
  });

  // little clock in the menu bar (the visitor's own time)
  function tick() { var c = $('#clock'); if (c) c.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
  tick(); setInterval(tick, 20000);

  // page views, from a free public counter service. Each visitor counts once per browser session
  // (refreshing or clicking tabs does not add more), and visits from a computer's own test address are not counted.
  (function () {
    var el = $('#viewsNum'), cfg = window.DJ && window.DJ.views;
    if (!el || !cfg) return;
    var local = /^(localhost|127\.|\[::1\])/.test(location.hostname);
    var counted = false;
    try { counted = sessionStorage.getItem('dj_counted') === '1'; } catch (e) { /* storage blocked */ }
    var action = (counted || local) ? 'get' : 'hit';          // 'hit' adds one and returns the new total, 'get' only reads it
    fetch('https://abacus.jasoncameron.dev/' + action + '/' + encodeURIComponent(cfg.ns) + '/' + encodeURIComponent(cfg.key))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (typeof d.value !== 'number') return;
        el.textContent = d.value.toLocaleString();
        if (action === 'hit') { try { sessionStorage.setItem('dj_counted', '1'); } catch (e) { /* ignore */ } }
      })
      .catch(function () { /* the counter service is down: leave the dash, nothing else is affected */ });
  })();

  renderPins();
  go(location.hash.slice(1));
})();
