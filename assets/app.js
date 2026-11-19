// Everything on the page happens here, in your browser: tabs, page numbers and pins.
// Loader: the welcome card on the dotted background. It is just an animation (not real progress): the 10 boxes fill one after another,
// 0 to 100% in about 0.9 seconds, then the page is revealed. Meanwhile the first page of Portfolios and Tools pictures is fetched
// quietly in the background, so those tabs open instantly.
(function () {
  var L = document.getElementById('loader');
  if (!L) return;
  var mainEl = document.querySelector('main');
  var cells = Array.prototype.slice.call(L.querySelectorAll('.lbar i')), pctEl = L.querySelector('.lpct');
  var FILL = 900, start = Date.now(), finished = false;

  function paint(frac) {
    var on = Math.floor(frac * 10 + 1e-6);
    cells.forEach(function (c, i) { c.classList.toggle('on', i < on); });
    pctEl.textContent = Math.floor(frac * 100) + '%';
  }
  function reveal() {
    if (finished) return; finished = true;
    paint(1);
    setTimeout(function () {                                     // a moment on 100% so the full pill is seen
      L.classList.add('out');
      var quiet = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (!quiet) {
        var top = document.getElementById('top'); if (top) top.classList.add('rv');
        if (mainEl) mainEl.classList.add('tx-in', 'tx-first');
        setTimeout(function () { if (top) top.classList.remove('rv'); if (mainEl) mainEl.classList.remove('tx-in', 'tx-first'); }, 250);
      }
      setTimeout(function () { L.remove(); }, 160);
    }, 50);
  }
  function loop() {
    if (finished) return;
    var frac = Math.min(1, (Date.now() - start) / FILL);
    paint(frac);
    if (frac >= 1) reveal(); else requestAnimationFrame(loop);
  }

  ['#mainP', '#mainT'].forEach(function (sel) {                  // warm the browser cache with the first page (15 cards) of each gallery
    var m = document.querySelector(sel);
    if (m) Array.prototype.slice.call(m.querySelectorAll('.gimg img'), 0, 15).forEach(function (el) { new Image().src = el.currentSrc || el.src; });
  });

  setTimeout(reveal, FILL + 100);                                // safety net if animation frames are paused (background tab)
  paint(0); requestAnimationFrame(loop);
})();

(function () {
  var PER_PAGE = (window.DJ && window.DJ.perPage) || 30;
  var GALLERY_PER_PAGE = 15;                                     // cards on each page of the Portfolios and Tools galleries
  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var JOB_VIEWS = {
    'all':       function () { return true; },
    'highlighted': function (r) { return r.dataset.hl === '1'; },
    'india':     function (r) { return r.dataset.region === 'india'; },
    'abroad':    function (r) { return r.dataset.region === 'abroad'; },
    'remote':    function (r) { return r.dataset.mode === 'remote'; },
    'dead':      function () { return true; }
  };
  var OTHER_VIEWS = ['log', 'about', 'portfolios', 'tools', 'companies', 'pinned'];   // 'pinned' = My Pinned, phones only

  var listView = $('#listView'), logView = $('#logView'), aboutView = $('#aboutView');
  var PINNABLE = 'tr[data-id], .gitem[data-id]';                 // a job row, or a card in the Portfolios / Tools gallery
  var liveTable = $('#liveTable'), deadTable = $('#deadTable');
  var emptyEl = $('#empty'), pagerEl = $('#pager');
  var view = 'all', page = 1, pendingScroll = false;
  // Several roles at one company: a small "+N more" chip in the company cell. Click it to see only that company's roles
  // (a bar above the table says so, with a "Show all" link to come back). The row order never changes.
  var coFilter = '', coBar, coBarText;
  function coKey(r) { return (r.dataset.co || '').trim().toLowerCase(); }
  function showCompany(k) { coFilter = k; page = 1; render(); window.scrollTo(0, 0); }
  (function () {
    var rows = $$('#liveTable tbody tr'), count = {};
    rows.forEach(function (r) { var k = coKey(r); count[k] = (count[k] || 0) + 1; });
    rows.forEach(function (r) {
      var k = coKey(r), n = count[k], cell = $('td.company', r);
      if (n < 2 || !cell) return;
      var el = document.createElement('button');
      el.type = 'button'; el.className = 'cochip'; el.textContent = '+' + (n - 1); el.dataset.key = k;
      el.dataset.tip = 'View all ' + n + ' roles'; el.setAttribute('aria-label', 'View all ' + n + ' roles at ' + r.dataset.co);
      cell.appendChild(el);                                       // after the location: "Figma (San Francisco, United States) +9"
    });
    coBar = document.createElement('div'); coBar.id = 'coBar'; coBar.hidden = true;
    coBarText = document.createElement('span'); var all = document.createElement('button'); all.type = 'button'; all.className = 'cobtn'; all.textContent = 'Show all';
    coBar.appendChild(coBarText); coBar.appendChild(all);
    liveTable.parentNode.insertBefore(coBar, liveTable);   // above the first row
  })();

  /* ---------- pins (saved in this browser with localStorage) ---------- */
  // RULES that keep a visitor's pins safe when the site changes (deploys, new features, database edits never touch this):
  //  1. The storage key 'dj_pins_v1' and the shape of a pin {id, title, company, url} never change. If one ever has to, read the OLD key first and copy its pins over.
  //  2. A pin remembers its own title, company and link, so it still shows (marked closed) even when the entry is gone from the page.
  //  3. A pin is matched to an entry by its id OR its link, so a changed id scheme (or a table that was emptied and refilled) cannot orphan it.
  //  4. Damaged saved data is cleaned up here, never allowed to break the page, and never deleted wholesale.
  var KEY = 'dj_pins_v1', pins = [];
  try { pins = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { pins = []; }
  if (!Array.isArray(pins)) pins = [];
  pins = pins.filter(function (p) { return p && typeof p === 'object' && typeof p.id === 'string' && typeof p.url === 'string'; });
  function savePins() { try { localStorage.setItem(KEY, JSON.stringify(pins)); } catch (e) { /* storage blocked: pins just last until the page closes */ } }
  function samePin(p, id, url) { return p.id === id || (!!url && p.url === url); }
  function isPinned(id, url) { return pins.some(function (p) { return samePin(p, id, url); }); }
  function findEntry(p) {                                   // the entry on the page that a pin belongs to: by id, else by link
    var el = $('[data-id="' + p.id + '"]');
    return el || $$('[data-url]').filter(function (e) { return e.dataset.url === p.url; })[0] || null;
  }

  function renderPins() {
    var list = $('#pinList'); list.innerHTML = '';
    pins.forEach(function (p) {
      var row = findEntry(p);
      var gone = !row || row.closest('table') === deadTable;   // not on the page any more (or in the Dead Zone) = no longer a live job
      var li = document.createElement('li'); if (gone) li.className = 'gone';
      var box = document.createElement('div');
      var a = document.createElement('a'); a.href = p.url; a.target = '_blank'; a.rel = 'nofollow noopener'; a.textContent = p.title;
      var s = document.createElement('small'); s.textContent = p.company + (gone ? ' · closed/inactive' : '');
      box.appendChild(a); box.appendChild(s);
      var x = document.createElement('button'); x.className = 'x'; x.type = 'button'; x.title = 'Unpin'; x.setAttribute('aria-label', 'Unpin'); x.textContent = '×';
      x.addEventListener('click', function () { togglePin(p.id); });
      li.appendChild(box); li.appendChild(x); list.appendChild(li);
    });
    var pn = $('#pinTabN'); if (pn) pn.textContent = pins.length;
    var hint = $('#pinHint');
    if (isAdmin()) { hint.textContent = 'Admin mode: the star highlights a job for everyone (see the Highlighted tab). Pinning for yourself is paused on this page. Portfolios and tools have no highlight yet.'; hint.hidden = false; }
    else { hint.textContent = 'Tap the ☆ on any job to pin it here. Pins are saved in this browser only.'; hint.hidden = pins.length > 0; }
    $('#pinbox').classList.toggle('nopins', pins.length === 0 && !isAdmin());
    if (isAdmin()) $$('#deadTable tbody tr').forEach(function (r) { if (!$('.delbtn', r)) addDelBtn(r, $('td.posted', r)); });
    $$(PINNABLE).forEach(function (r) {
      var b = $('.pinbtn', r);
      if (!b) return;                                   // Dead Zone rows have no star
      var isRow = r.matches('tr:not(.crow)');
      var admin = isAdmin() && isRow, on = admin ? r.dataset.hl === '1' : isPinned(r.dataset.id, r.dataset.url);   // on /meow the star on a JOB means "highlight for everyone"; gallery cards always pin for you
      if (!isRow) { b.hidden = isAdmin(); b.classList.toggle('on', on); b.innerHTML = on ? '&#9733;' : '&#9734;'; b.title = on ? 'Unpin this' : 'Pin this'; }
      if (isAdmin() && !$('.delbtn', r)) addDelBtn(r, b.parentNode);   // admin mode: a small delete button (a cross) beside the star
      if (!isRow) return;
      b.classList.toggle('on', on); b.innerHTML = on ? '&#9733;' : '&#9734;';
      b.title = admin ? (on ? 'Remove this highlight' : 'Highlight this job for everyone') : (on ? 'Unpin this job' : 'Pin this job');
    });
  }

  // Admin mode (the /meow card is on the page): the star highlights the job for EVERYONE, saved in the database.
  function isAdmin() { return !!$('#adminCard'); }
  function updateHighlightTab() {
    var tab = $('#pills a[data-view="highlighted"]'); if (!tab) return;
    $('.n', tab).textContent = '(' + $$('#liveTable tbody tr').filter(function (r) { return r.dataset.hl === '1'; }).length + ')';
    if (isAdmin()) tab.hidden = false;
  }
  // The numbers on the tabs (and "N jobs" at the top right), counted again from the rows that are on the page.
  function recountTabs() {
    var rows = $$('#liveTable tbody tr');
    $$('#pills a').forEach(function (a) {
      var v = a.dataset.view; if (v === 'dead' || !JOB_VIEWS.hasOwnProperty(v)) return;
      $('.n', a).textContent = '(' + rows.filter(JOB_VIEWS[v]).length + ')';
    });
    var topJobs = $('#tabs a[data-top="jobs"] .n'); if (topJobs) topJobs.textContent = rows.length;   // the number beside Jobs in the top bar
    [['portfolios', '#mainP .gitem'], ['tools', '#mainT .gitem'], ['companies', '#companyTable tbody tr'], ['dead', '#deadTable tbody tr']].forEach(function (t) {
      var n = $('#tabs a[data-top="' + t[0] + '"] .n'); if (n) n.textContent = $$(t[1]).length;
    });
    var jn = $('.jobsn'); if (jn) jn.textContent = (rows.length + (parseInt(jn.dataset.extra, 10) || 0)) + ' Oddities';   // every job, portfolio, tool and company
  }
  function adminSay(text, kind) { var m = $('#adminMsg'); if (m) { m.textContent = text; m.className = 'admin-msg ' + kind; } }
  function addDelBtn(row, where) {
    var d = document.createElement('button'); d.type = 'button'; d.className = 'delbtn'; d.title = 'Delete this'; d.setAttribute('aria-label', 'Delete this'); d.textContent = '×';
    where.appendChild(d);
  }
  // Admin mode: delete anything (a job, portfolio, tool, company or a Dead Zone entry). The thing disappears AT ONCE; if saving fails it comes back.
  // Jobs are only hidden in the database (the collector never brings them back); portfolios, tools and companies are removed from their table.
  function deleteEntry(row) {
    var title = row.dataset.title, co = row.dataset.co || '', id = row.dataset.id || '';
    var isSite = /^(tool|port|co)-\d+$/.test(id);
    if (!isSite && !row.dataset.url) return;
    var parent = row.parentNode, next = row.nextSibling, y = window.scrollY;
    function refresh() { recountTabs(); render(); renderPins(); window.scrollTo(0, y); }
    var extra = $('.jobsn'), ex = extra ? parseInt(extra.dataset.extra, 10) || 0 : 0;
    parent.removeChild(row);
    if (extra && isSite) extra.dataset.extra = ex - 1;
    refresh();
    adminSay('Deleted: ' + title + (co ? ' at ' + co : '') + '.', 'good');
    fetch('/api/index.php?add=1', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(isSite ? { action: 'delete_site', id: id } : { action: 'delete', url: row.dataset.url }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.status !== 'ok' && !/not in the list|not in the list any more/i.test(d.message || '')) throw new Error(d.message || 'Could not delete.'); })
      .catch(function (e) {
        parent.insertBefore(row, next);                                // put it back where it was
        if (extra && isSite) extra.dataset.extra = ex;
        refresh();
        adminSay('Not deleted (' + title + '): ' + e.message, 'bad');
      });
  }
  function toggleHighlight(row) {
    var on = row.dataset.hl !== '1';
    function set(v) { row.dataset.hl = v ? '1' : ''; renderPins(); updateHighlightTab(); if (view === 'highlighted') render(); }
    set(on);                                                       // shown at once; undone below if saving fails
    fetch('/api/index.php?add=1', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'highlight', url: row.dataset.url, on: on }) })
      .then(function (r) { return r.json(); })
      .then(function (d) { if (d.status !== 'ok') throw new Error(d.message || 'Could not save.'); })
      .catch(function (e) { set(!on); var m = $('#adminMsg'); if (m) { m.textContent = 'Highlight not saved: ' + e.message; m.className = 'admin-msg bad'; } });
  }
  // Small pop-up at the bottom that confirms a pin or unpin in a few words. Fades away by itself after about 2.2 seconds.
  var toastEl, toastTimer;
  function toast(text) {
    if (!toastEl) {
      toastEl = document.createElement('div'); toastEl.id = 'toast'; toastEl.setAttribute('role', 'status'); toastEl.setAttribute('aria-live', 'polite');
      document.body.appendChild(toastEl);
    }
    toastEl.textContent = text;
    toastEl.classList.add('show');
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 2200);
  }
  function togglePin(id) {
    var r = $('[data-id="' + id + '"]'), url = r ? r.dataset.url : '', added = false;
    if (isPinned(id, url)) pins = pins.filter(function (p) { return !samePin(p, id, url); });
    else if (r) { pins.unshift({ id: id, title: r.dataset.title, company: r.dataset.co, url: r.dataset.url }); added = true; }
    savePins(); renderPins();
    if (added) toast('Added to your pins');
    else toast('Removed from your pins');
  }

  /* ---------- showing the right rows ---------- */
  function render() {
    var isList = JOB_VIEWS.hasOwnProperty(view);
    var isDead = view === 'dead', isP = view === 'portfolios', isT = view === 'tools', isC = view === 'companies', isPin = view === 'pinned';
    listView.classList.toggle('pinpage', isPin);
    listView.hidden  = !(isList || isP || isT || isC || isPin);          // one layout (with the Pinned card) for Jobs, Dead Zone, Portfolios, Tools and Companies
    logView.hidden   = view !== 'log';
    aboutView.hidden = view !== 'about';
    $('#deadNote').hidden = !isDead;
    $('#intro').hidden = !isList || isDead;
    $('#pills').hidden = !isList || isDead;                      // the pills filter the live list, not the Dead Zone
    $('#jobsMain').hidden = !isList;
    $('#introP').hidden = !isP; $('#mainP').hidden = !isP;
    $('#introC').hidden = !isC; $('#mainC').hidden = !isC;
    $('#introT').hidden = !isT; $('#mainT').hidden = !isT;
    var top = (isList && !isDead) ? 'jobs' : view;               // which section is lit in the top bar
    $$('#tabs a').forEach(function (a) { a.classList.toggle('on', a.dataset.top === top); });
    $$('#pills a').forEach(function (a) { a.classList.toggle('on', a.dataset.view === view); });
    if (isC) { renderCompanies(); return; }
    if (isP || isT) { renderGallery(isP ? '#mainP' : '#mainT', isP ? '#pagerP' : '#pagerT'); return; }
    if (!isList) { window.scrollTo(0, 0); return; }

    liveTable.hidden = isDead; deadTable.hidden = !isDead;

    var table = isDead ? deadTable : liveTable;
    var inView = JOB_VIEWS[view];
    var rows = $$('tbody tr', table).filter(function (r) { return inView(r) && (isDead || !coFilter || coKey(r) === coFilter); });   // the rows that belong to this tab (and company, when one was picked)
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
    if (coBar) {
      coBar.hidden = isDead || !coFilter;
      liveTable.classList.toggle('cofiltered', !!coFilter);        // the "+N more" chips are not needed once you are looking at one company
      if (coFilter && rows.length) coBarText.textContent = 'Showing ' + rows.length + (rows.length === 1 ? ' role' : ' roles') + ' at ' + rows[0].dataset.co + '. ';
    }

    emptyEl.hidden = total > 0;
    emptyEl.textContent = isDead ? 'Nothing in the Dead Zone yet.' : (view === 'highlighted' ? 'Nothing is highlighted yet.' : 'No jobs here yet.');
    table.hidden = total === 0;
    renderPager(pagerEl, pages);
  }

  // Portfolios and Tools galleries: 15 cards per page, with their own page buttons.
  function renderGallery(mainSel, pagerSel) {
    var cards = $$('.gitem', $(mainSel)), pages = Math.max(1, Math.ceil(cards.length / GALLERY_PER_PAGE));
    if (page > pages) page = pages;
    var from = (page - 1) * GALLERY_PER_PAGE, to = from + GALLERY_PER_PAGE;
    cards.forEach(function (c, i) { c.hidden = i < from || i >= to; });
    renderPager($(pagerSel), pages);
    if (pendingScroll) { pendingScroll = false; $(mainSel).previousElementSibling.scrollIntoView(); }   // page button clicked: bring the welcome card into view
    else window.scrollTo(0, 0);
  }

  // Companies: same page size as the jobs (20), numbering carries on across pages.
  function renderCompanies() {
    var rows = $$('#companyTable tbody tr'), pages = Math.max(1, Math.ceil(rows.length / PER_PAGE));
    if (page > pages) page = pages;
    var from = (page - 1) * PER_PAGE, to = from + PER_PAGE;
    rows.forEach(function (r, i) {
      r.hidden = i < from || i >= to;
      if (!r.hidden) { $('.rank', r).textContent = (i + 1) + '.'; r.classList.toggle('z', (i - from) % 2 === 1); }
    });
    renderPager($('#pagerC'), pages);
    if (pendingScroll) { pendingScroll = false; $('#mainC').previousElementSibling.scrollIntoView(); }
    else window.scrollTo(0, 0);
  }

  // Page buttons: Prev, 1 2 3 ... , Next. With many pages: first, last and the pages around the current one, with ... between.
  function renderPager(pagerEl, pages) {
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

  // The web address shows where you are: ?tab=portfolios, or ?tab=india&page=2. Opening the plain domain goes to ?tab=jobs (the Jobs section, page 1). Page 1 never shows a page number; later pages are ?tab=jobs&page=2.
  // Opening such a link shows that tab. An old #india/2 style link still works. The Back button steps through tabs and pages.
  function parse(h) { var parts = String(h || '').split('/'); return { v: parts[0], p: parseInt(parts[1], 10) }; }
  function fromAddress() {
    var q = new URLSearchParams(location.search), t = q.get('tab');
    return t ? { v: t === 'jobs' ? 'all' : t, p: parseInt(q.get('page'), 10) } : parse(location.hash.slice(1));   // (the Jobs section is ?tab=jobs, inside the code it is 'all')
  }
  function addressFor(v, p) { return location.pathname + '?tab=' + encodeURIComponent(v === 'all' ? 'jobs' : v) + (p > 1 ? '&page=' + p : '') + ''; }

  var mainEl = $('main'), txTimer = 0;
  var QUIET = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function swap(fn) {                                            // old content fades out (70ms), new content fades in (140ms)
    clearTimeout(txTimer);
    mainEl.classList.remove('tx-in', 'tx-first'); mainEl.classList.add('tx-out');
    txTimer = setTimeout(function () {
      fn();
      mainEl.classList.remove('tx-out'); void mainEl.offsetWidth; mainEl.classList.add('tx-in');
      txTimer = setTimeout(function () { mainEl.classList.remove('tx-in'); }, 200);
    }, 70);
  }
  function show(v, pg, animate) {                                // show a tab and page (a bad name or too-big page number is tidied up)
    if (!JOB_VIEWS.hasOwnProperty(v) && OTHER_VIEWS.indexOf(v) === -1) v = 'all';
    if (v === 'pinned' && window.innerWidth > 760) v = 'all';       // My Pinned only exists on phones (desktop has the Pinned card beside the list)
    pg = pg > 0 ? pg : 1;
    var same = v === view && pg === page;
    if (v !== view) coFilter = '';                                  // a picked company is forgotten when you change tab
    view = v; page = pg;
    function draw() {
      render();
      if (pendingScroll) { pendingScroll = false; var card = $('#listView .card'); if (card && !listView.hidden) card.scrollIntoView(); }
    }
    if (animate && !same && !QUIET && mainEl) swap(draw);
    else { clearTimeout(txTimer); mainEl.classList.remove('tx-out'); draw(); }
  }

  function go(h) {                                               // a tab or page button was clicked
    var before = view + '/' + page, t = parse(h);
    show(t.v, t.p, true);
    if (view + '/' + page !== before) history.pushState({ view: view, page: page }, '', addressFor(view, page));   // new Back-button step, and the address shows the tab
  }

  /* ---------- wiring ---------- */
  window.addEventListener('popstate', function (e) {             // Back / Forward button
    if (e.state) { show(e.state.view, e.state.page, true); return; }
    var t = fromAddress(); show(t.v, t.p, true);                       // someone typed an address by hand: honour it, then tidy it
    history.replaceState({ view: view, page: page }, '', addressFor(view, page));
  });
  document.addEventListener('click', function (e) {
    var pin = e.target.closest('.pinbtn');
    var del = e.target.closest('.delbtn');
    var chip = e.target.closest('.cochip'); if (chip) { showCompany(chip.dataset.key); return; }
    if (e.target.closest('.cobtn')) { showCompany(''); return; }
    if (del) { deleteEntry(del.closest('tr, .gitem')); return; }
    if (pin) { var prow = pin.closest(PINNABLE); if (isAdmin() && prow.matches('tr:not(.crow)')) toggleHighlight(prow); else togglePin(prow.dataset.id); }
    var link = e.target.closest('a[href^="#"]');                 // our tab and page buttons
    if (link && e.button === 0 && !(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey)) {
      e.preventDefault();
      if (link.closest('.pager')) pendingScroll = true;          // after changing page, bring the top of the table into view
      go(link.getAttribute('href').slice(1));
    }
  });

  // admin card (only present on /meow): send the pasted link to the server, show what the AI decided
  (function () {
    var form = $('#adminForm');
    if (!form && /^\/meow\/?$/.test(location.pathname)) {       // backup: the address is /meow but the server did not add the card
      var tpl = $('#adminTpl'), pin = $('#pinbox');
      if (tpl && pin) { pin.parentNode.insertBefore(tpl.content.cloneNode(true), pin); form = $('#adminForm'); }
    }
    if (!form) return;
    var urlBox = $('#adminUrl'), btn = $('#adminBtn'), msg = $('#adminMsg');
    function say(text, kind) { msg.textContent = text; msg.className = 'admin-msg ' + kind; }
    var logBox = $('#adminLog');
    // What are we adding? Job (the AI checks it against our roles), or a Portfolio / a Tool (the AI just fills in the entry: name, and the tag for a tool).
    var KINDS = {
      job:        { button: 'Add job',       help: 'Paste a job link, or a page that lists many jobs. The AI checks every job against our roles and lists the ones that fit.' },
      portfolios: { button: 'Add portfolio', help: 'Paste a link to a portfolio or personal site, or several separated by commas. The AI names each entry and a screenshot is taken. Nothing is judged: whatever you paste is added.' },
      companies:  { button: 'Add company',    help: 'Paste a link to a company website, or several separated by commas. The AI names each one, picks its type (Tech, Design, Studio...) and writes a short description. Nothing is judged: whatever you paste is added.' },
      tools:      { button: 'Add tool',      help: 'Paste a link to a tool, or several separated by commas. The AI names each one and picks its tag (the kind of tool), and a screenshot is taken. Nothing is judged: whatever you paste is added.' }
    };
    function currentKind() { var r = $('input[name="adminKind"]:checked'); return r ? r.value : 'job'; }
    function applyKind() { var mb = $('#manualBox'); if (mb) { mb.hidden = currentKind() !== 'job'; if (mb.hidden) mb.open = false; }
      var k = KINDS[currentKind()]; btn.textContent = k.button; $('#adminHelp').textContent = k.help; msg.textContent = ''; logBox.innerHTML = ''; }
    $$('input[name="adminKind"]').forEach(function (r) { r.addEventListener('change', applyKind); });
    function post(url, single) {
      return fetch('/api/index.php?add=1', {
        method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ url: url, single: !!single, kind: currentKind() })
      }).then(function (r) {
        return r.json().catch(function () { return { status: 'error', message: 'The server gave an answer we could not read. Try again in a moment.' }; });
      });
    }
    function wait(ms) { return new Promise(function (res) { setTimeout(res, ms); }); }
    // one job from a list; if Groq says it is busy, wait and try again (twice at most)
    function checkOne(url, tries) {
      return post(url, true).then(function (d) {
        if (d.status === 'error' && /rate limit/i.test(d.message || '') && (tries || 0) < 2) return wait(20000).then(function () { return checkOne(url, (tries || 0) + 1); });
        return d;
      });
    }
    function addLine(text, kind) { var li = document.createElement('li'); li.className = kind; li.textContent = text; logBox.appendChild(li); logBox.scrollTop = logBox.scrollHeight; }
    function short(t) { t = String(t || ''); return t.length > 70 ? t.slice(0, 67) + '...' : t; }

    // Portfolio / Tools / Company: one link, or several separated by commas (spaces and new lines work too). Each is added on its own, one after the other.
    // A link that could not be added stays in the box, so you can fix it or press the button again.
    function addMany(raw) {
      var seen = {};
      var links = raw.split(/[\s,]+/).filter(Boolean).map(function (t) { return /^https?:\/\//i.test(t) ? t : 'https://' + t; })
        .filter(function (t) { var k = t.toLowerCase(); if (seen[k]) return false; seen[k] = 1; return true; });
      if (!links.length) { say('Paste a link first.', 'bad'); return; }
      if (links.length > 30) { say('That is ' + links.length + ' links. Please add at most 30 at a time.', 'bad'); return; }
      btn.disabled = true; logBox.innerHTML = '';
      var added = 0, skipped = 0, failed = 0, i = 0, keep = [], last = null;
      function next() {
        if (i >= links.length) return Promise.resolve();
        var l = links[i++];
        say(links.length > 1 ? 'Adding ' + i + ' of ' + links.length + ': ' + short(l) : 'Reading the page and asking the AI. This takes a few seconds...', 'wait');
        return checkOne(l, 0).then(function (r) {
          last = r;
          var msg = String(r.message || '').replace(/ The site shows it within a minute\.$/, '');
          if (r.status === 'added') { added++; addLine(msg, 'ok'); }
          else if (r.status === 'duplicate') { skipped++; addLine(short(l) + ': ' + msg, 'skip'); }
          else { failed++; keep.push(l); addLine(short(l) + ': ' + msg, 'skip'); }
          return next();
        });
      }
      next()
        .then(function () {
          urlBox.value = keep.join(', ');
          if (links.length === 1) { logBox.innerHTML = ''; say(last.message || 'Done.', last.status === 'added' ? 'good' : (last.status === 'duplicate' ? 'wait' : 'bad')); return; }
          say('Done. Added ' + added + ', already in the list ' + skipped + (failed ? ', not added ' + failed + ' (left in the box)' : '') + '.' + (added ? ' The site shows them within a minute.' : ''), failed ? 'bad' : (added ? 'good' : 'wait'));
        })
        .catch(function () { say('Could not reach the server. Check your internet and try again.', 'bad'); })
        .then(function () { btn.disabled = false; });
    }

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var url = urlBox.value.trim();
      if (!url) { say('Paste a link first.', 'bad'); return; }
      if (currentKind() !== 'job') { addMany(url); return; }
      btn.disabled = true; logBox.innerHTML = '';
      say('Reading the page and asking the AI. This takes a few seconds...', 'wait');
      post(url, false)
        .then(function (d) {
          if (d.status === 'added') { say(d.message, 'good'); urlBox.value = ''; return; }
          if (d.status === 'duplicate') { say(d.message, 'wait'); return; }
          if (d.status !== 'list') { say(d.message || 'Something went wrong.', 'bad'); return; }
          // a page that lists many jobs: check each job on its own, one after the other
          var links = d.links || [], added = 0, skipped = 0, failed = 0, i = 0;
          say(d.message, 'wait');
          function next() {
            if (i >= links.length) {
              say('Done. Added ' + added + ', not added ' + skipped + (failed ? ', could not check ' + failed : '') + '.' + (added ? ' The site shows them within a minute.' : ''), added ? 'good' : 'wait');
              if (added) urlBox.value = '';
              return Promise.resolve();
            }
            var l = links[i++];
            say('Checking ' + i + ' of ' + links.length + ': ' + short(l.text), 'wait');
            return checkOne(l.url, 0).then(function (r) {
              if (r.status === 'added') { added++; addLine(r.message.replace(/ It is saved\..*$/, ''), 'ok'); }
              else if (r.status === 'error') { failed++; addLine(short(l.text) + ': ' + r.message, 'skip'); }
              else { skipped++; addLine(short(l.text) + ': ' + (r.message || '').replace(/^Not added: /, ''), 'skip'); }
              return next();
            });
          }
          return next();
        })
        .catch(function () { say('Could not reach the server. Check your internet and try again.', 'bad'); })
        .then(function () { btn.disabled = false; });
    });

    // "Add Manually" (jobs only): the details are typed in, nothing is read or judged by the AI
    var mForm = $('#manualForm');
    if (mForm) mForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var f = mForm.elements, mBtn = $('#manualBtn');
      var body = { kind: 'manual_job', intern: f.intern.checked };
      ['title', 'company', 'url', 'location', 'role', 'region', 'mode', 'posted_at'].forEach(function (k) { body[k] = f[k].value.trim(); });
      if (body.url && !/^https?:\/\//i.test(body.url)) body.url = 'https://' + body.url;
      mBtn.disabled = true; logBox.innerHTML = ''; say('Saving...', 'wait');
      fetch('/api/index.php?add=1', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
        .then(function (r) { return r.json().catch(function () { return { status: 'error', message: 'The server gave an answer we could not read. Try again in a moment.' }; }); })
        .then(function (d) {
          say(d.message || 'Done.', d.status === 'added' ? 'good' : (d.status === 'duplicate' ? 'wait' : 'bad'));
          if (d.status === 'added') mForm.reset();
        })
        .catch(function () { say('Could not reach the server. Check your internet and try again.', 'bad'); })
        .then(function () { mBtn.disabled = false; });
    });

    // "Check open jobs": is every listed job still open? No AI: just web requests. Closed jobs move to the Dead Zone.
    var liveBtn = $('#liveBtn'), colStop = $('#colStop'), colMsg = $('#colMsg'), colLog = $('#colLog'), stopNow = false;
    function colSay(text, kind) { colMsg.textContent = text; colMsg.className = 'admin-msg ' + kind; }
    function colLine(text, kind) { var li = document.createElement('li'); li.className = kind; li.textContent = text; colLog.appendChild(li); colLog.scrollTop = colLog.scrollHeight; }
    function act(action, extra) {
      var body = JSON.stringify(Object.assign({ action: action }, extra || {}));
      function once() {
        return fetch('/api/index.php?add=1', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body })
          .then(function (r) {
            return r.text().then(function (t) {
              try { return JSON.parse(t); } catch (e) {
                var snippet = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 120);
                return { status: 'unreadable', message: 'The server gave an answer we could not read (HTTP ' + r.status + (snippet ? ': ' + snippet : '') + ').' };
              }
            });
          });
      }
      // a hiccup (the server was busy or timed out) gets one more try before we give up
      return once().then(function (d) { return d.status === 'unreadable' ? wait(3000).then(once) : d; })
        .then(function (d) { if (d.status === 'unreadable') d.status = 'stop'; return d; });
    }
    async function checkOpen() {
      stopNow = false; liveBtn.disabled = true; colStop.hidden = false; colLog.innerHTML = '';
      var checked = 0, open = 0, closed = 0, unknown = 0;
      try {
        var plan = await act('live_plan');
        if (plan.status !== 'ok') { colSay(plan.message || 'Could not start.', 'bad'); return; }
        var due = plan.due;
        if (due === 0) { colSay('Every listed job was checked in the last day. Nothing to do.', 'wait'); return; }
        while (!stopNow) {
          var r = await act('live_batch', { size: 40 });
          if (r.status !== 'ok') { colSay(r.message || 'Problem while checking.', 'bad'); return; }
          checked += r.checked; open += r.alive; closed += r.closed; unknown += r.unknown;
          (r.closed_jobs || []).forEach(function (c) { colLine('Closed: ' + c.title + ' at ' + c.company + ' (' + c.reason + ')', 'skip'); });
          colSay('Checking that jobs are still open: ' + checked + ' of ' + due + ' (' + closed + ' closed so far)...', 'wait');
          if (!r.checked || !r.remaining) break;
        }
        colSay((stopNow ? 'Stopped. ' : 'Done. ') + checked + ' jobs checked: ' + open + ' still open, ' + closed + ' closed, ' + unknown + ' could not be reached (tried again next time). A job only closes when it is gone two nights in a row.', closed ? 'good' : 'wait');
      } catch (e) {
        colSay('Lost the connection to the server. Press the button again to continue.', 'bad');
      } finally {
        liveBtn.disabled = false; colStop.hidden = true;
      }
    }
    // "Take missing screenshots": a few per press on the server, repeated here until none are left
    var shotBtn = $('#shotBtn'), shotMsg = $('#shotMsg');
    function shotSay(text, kind) { shotMsg.textContent = text; shotMsg.className = 'admin-msg ' + kind; }
    async function takeShots() {
      shotBtn.disabled = true; var done = 0, failed = 0;
      try {
        while (true) {
          shotSay('Taking screenshots... ' + done + ' saved' + (failed ? ', ' + failed + ' failed' : '') + '.', 'wait');
          var r = await act('shots');
          if (r.status !== 'ok') { shotSay(r.message || 'Problem while taking screenshots.', 'bad'); return; }
          done += r.done; failed += r.failed;
          if (!r.left || (!r.done && !r.failed)) break;
        }
        shotSay(done || failed ? 'Done. ' + done + ' screenshots saved' + (failed ? ', ' + failed + ' failed (try again later)' : '') + '.' : 'Every portfolio and tool already has a screenshot.', failed ? 'bad' : 'good');
      } catch (e) { shotSay('Lost the connection to the server. Press the button again to continue.', 'bad'); }
      finally { shotBtn.disabled = false; }
    }
    if (shotBtn) shotBtn.addEventListener('click', takeShots);
    if (liveBtn) { liveBtn.addEventListener('click', checkOpen); colStop.addEventListener('click', function () { stopNow = true; colSay('Stopping after this round...', 'wait'); }); }
  })();

  // Phone menu: the icon slides the menu frame down. It closes when you pick a tab, tap outside, or press Escape.
  (function () {
    var top = $('#top'), btn = $('#menuBtn');
    if (!top || !btn) return;
    function set(open) { top.classList.toggle('menu-open', open); btn.setAttribute('aria-expanded', open ? 'true' : 'false'); }
    btn.addEventListener('click', function (e) { e.stopPropagation(); set(!top.classList.contains('menu-open')); });
    document.addEventListener('click', function (e) {
      if (top.classList.contains('menu-open') && (e.target.closest('#tabs a') || e.target.closest('.brand') || !e.target.closest('#top'))) set(false);
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') set(false); });
    window.addEventListener('resize', function () { if (window.innerWidth > 760) set(false); });
  })();

  // Views: the counter service answers slowly, so show the last number it gave us (kept in this browser) straight away.
  // When the service answers, the new number replaces it and is kept for next time.
  (function () {
    var vc = $('[data-view-counter]'); if (!vc) return;
    var KEY = 'dj_views';
    try { var last = localStorage.getItem(KEY); if (last && !/\d/.test(vc.textContent)) vc.textContent = last; } catch (e) {}
    new MutationObserver(function () {
      var t = vc.textContent.trim();
      if (/\d/.test(t)) { try { localStorage.setItem(KEY, t); } catch (e) {} }
    }).observe(vc, { childList: true, characterData: true, subtree: true });
  })();

  renderPins();
  var first = fromAddress();                                     // opening the site: honour ?tab=... (or an old #link), then show the tidy address
  show(first.v, first.p);
  history.replaceState({ view: view, page: page }, '', addressFor(view, page));
})();
