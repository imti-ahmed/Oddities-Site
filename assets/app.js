// Everything on the page happens here, in your browser: tabs, page numbers and pins.
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
  var OTHER_VIEWS = ['log', 'about', 'portfolios', 'tools', 'companies'];

  var listView = $('#listView'), logView = $('#logView'), aboutView = $('#aboutView');
  var PINNABLE = 'tr[data-id], .gitem[data-id]';                 // a job row, or a card in the Portfolios / Tools gallery
  var liveTable = $('#liveTable'), deadTable = $('#deadTable');
  var emptyEl = $('#empty'), pagerEl = $('#pager');
  var view = 'all', page = 1, pendingScroll = false;

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
    var hint = $('#pinHint');
    if (isAdmin()) { hint.textContent = 'Admin mode: the star highlights a job for everyone (see the Highlighted tab). Pinning for yourself is paused on this page. Portfolios and tools have no highlight yet.'; hint.hidden = false; }
    else { hint.textContent = 'Tap the ☆ on any job to pin it here. Pins are saved in this browser only.'; hint.hidden = pins.length > 0; }
    $('#pinbox').classList.toggle('nopins', pins.length === 0 && !isAdmin());
    if (isAdmin()) $$('#deadTable tbody tr').forEach(function (r) { if (!$('.delbtn', r)) addDelBtn(r, $('td.posted', r)); });
    $$(PINNABLE).forEach(function (r) {
      var b = $('.pinbtn', r);
      if (!b) return;                                   // Dead Zone rows have no star
      var isRow = r.matches('tr:not(.crow)');
      var admin = isAdmin() && isRow, on = admin ? r.dataset.hl === '1' : isPinned(r.dataset.id, r.dataset.url);   // on /admin the star on a JOB means "highlight for everyone"; gallery cards always pin for you
      if (!isRow) { b.hidden = isAdmin(); b.classList.toggle('on', on); b.innerHTML = on ? '&#9733;' : '&#9734;'; b.title = on ? 'Unpin this' : 'Pin this'; }
      if (isAdmin() && !$('.delbtn', r)) addDelBtn(r, b.parentNode);   // admin mode: a small delete button (a cross) beside the star
      if (!isRow) return;
      b.classList.toggle('on', on); b.innerHTML = on ? '&#9733;' : '&#9734;';
      b.title = admin ? (on ? 'Remove this highlight' : 'Highlight this job for everyone') : (on ? 'Unpin this job' : 'Pin this job');
    });
  }

  // Admin mode (the /admin card is on the page): the star highlights the job for EVERYONE, saved in the database.
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
  function togglePin(id) {
    var r = $('[data-id="' + id + '"]'), url = r ? r.dataset.url : '';
    if (isPinned(id, url)) pins = pins.filter(function (p) { return !samePin(p, id, url); });
    else if (r) pins.unshift({ id: id, title: r.dataset.title, company: r.dataset.co, url: r.dataset.url });
    savePins(); renderPins();
  }

  /* ---------- showing the right rows ---------- */
  function render() {
    var isList = JOB_VIEWS.hasOwnProperty(view);
    var isDead = view === 'dead', isP = view === 'portfolios', isT = view === 'tools', isC = view === 'companies';
    listView.hidden  = !(isList || isP || isT || isC);          // one layout (with the Pinned card) for Jobs, Dead Zone, Portfolios, Tools and Companies
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

  // The web address always stays the plain domain: it never shows #india or ?page=2.
  // Where you are (which tab, which page) is kept in the browser's history instead, so the Back button still works.
  function parse(h) { var parts = String(h || '').split('/'); return { v: parts[0], p: parseInt(parts[1], 10) }; }

  function show(v, pg) {                                         // show a tab and page (a bad name or too-big page number is tidied up)
    if (!JOB_VIEWS.hasOwnProperty(v) && OTHER_VIEWS.indexOf(v) === -1) v = 'all';
    view = v; page = pg > 0 ? pg : 1;
    render();
    if (pendingScroll) { pendingScroll = false; var card = $('#listView .card'); if (card && !listView.hidden) card.scrollIntoView(); }
  }

  function go(h) {                                               // a tab or page button was clicked
    var before = view + '/' + page, t = parse(h);
    show(t.v, t.p);
    if (view + '/' + page !== before) history.pushState({ view: view, page: page }, '', location.pathname);   // new Back-button step, same plain address
  }

  /* ---------- wiring ---------- */
  window.addEventListener('popstate', function (e) {             // Back / Forward button
    if (e.state) { show(e.state.view, e.state.page); return; }
    var t = parse(location.hash.slice(1)); show(t.v, t.p);       // someone typed a #address by hand: honour it once, then tidy the address
    history.replaceState({ view: view, page: page }, '', location.pathname);
  });
  document.addEventListener('click', function (e) {
    var pin = e.target.closest('.pinbtn');
    var del = e.target.closest('.delbtn');
    if (del) { deleteEntry(del.closest('tr, .gitem')); return; }
    if (pin) { var prow = pin.closest(PINNABLE); if (isAdmin() && prow.matches('tr:not(.crow)')) toggleHighlight(prow); else togglePin(prow.dataset.id); }
    var link = e.target.closest('a[href^="#"]');                 // our tab and page buttons
    if (link && e.button === 0 && !(e.metaKey || e.ctrlKey || e.shiftKey || e.altKey)) {
      e.preventDefault();
      if (link.closest('.pager')) pendingScroll = true;          // after changing page, bring the top of the table into view
      go(link.getAttribute('href').slice(1));
    }
  });

  // little clock in the menu bar (the visitor's own time)
  function tick() { var c = $('#clock'); if (c) c.textContent = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
  tick(); setInterval(tick, 20000);

  // admin card (only present on /admin): send the pasted link to the server, show what the AI decided
  (function () {
    var form = $('#adminForm');
    if (!form && /^\/admin\/?$/.test(location.pathname)) {       // backup: the address is /admin but the server did not add the card
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
    function applyKind() { var k = KINDS[currentKind()]; btn.textContent = k.button; $('#adminHelp').textContent = k.help; msg.textContent = ''; logBox.innerHTML = ''; }
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

  renderPins();
  var first = parse(location.hash.slice(1));                     // opening the site: honour an old #link once, then show the plain address
  show(first.v, first.p);
  history.replaceState({ view: view, page: page }, '', location.pathname);
})();
