// Everything on the page happens here, in your browser: tabs, search, filters, "show more" and pins.
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
  var q = $('#q'), fTag = $('#fTag'), fCo = $('#fCo'), clearBtn = $('#clear');
  var countEl = $('#count'), emptyEl = $('#empty'), moreEl = $('#more');
  var view = 'all', shown = PER_PAGE;

  /* ---------- pins (saved in this browser with localStorage) ---------- */
  var KEY = 'dj_pins_v1', pins = [];
  try { pins = JSON.parse(localStorage.getItem(KEY) || '[]') || []; } catch (e) { pins = []; }
  function savePins() { try { localStorage.setItem(KEY, JSON.stringify(pins)); } catch (e) { /* storage blocked: pins just last until the page closes */ } }
  function isPinned(id) { return pins.some(function (p) { return p.id === id; }); }

  function renderPins() {
    var list = $('#pinList'); list.innerHTML = '';
    pins.forEach(function (p) {
      var row = $('tr[data-id="' + p.id + '"]');
      var gone = row && row.closest('table') === deadTable;
      var li = document.createElement('li'); if (gone) li.className = 'gone';
      var box = document.createElement('div');
      var a = document.createElement('a'); a.href = p.url; a.target = '_blank'; a.rel = 'nofollow noopener'; a.textContent = p.title;
      var s = document.createElement('small'); s.textContent = p.company + (gone ? ' · closed/inactive' : '');
      box.appendChild(a); box.appendChild(s);
      var x = document.createElement('button'); x.className = 'x'; x.type = 'button'; x.title = 'Unpin'; x.setAttribute('aria-label', 'Unpin'); x.textContent = '×';
      x.addEventListener('click', function () { togglePin(p.id); });
      li.appendChild(box); li.appendChild(x); list.appendChild(li);
    });
    $('#pinCount').textContent = pins.length;
    $('#pinHint').hidden = pins.length > 0;
    $('#pinbox').classList.toggle('empty', pins.length === 0);
    $$('tr[data-id]').forEach(function (r) {
      var b = $('.pinbtn', r), on = isPinned(r.dataset.id);
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
    if (!isList) { countEl.textContent = view === 'log' ? 'Update log' : 'About'; window.scrollTo(0, 0); return; }

    var isDead = view === 'dead';
    liveTable.hidden = isDead; deadTable.hidden = !isDead;
    $('#deadNote').hidden = !isDead;
    $('#intro').hidden = isDead;

    var table = isDead ? deadTable : liveTable;
    var words = q.value.toLowerCase().split(/\s+/).filter(Boolean);
    var tag = fTag.value, co = fCo.value, inView = JOB_VIEWS[view];
    var matches = 0, visible = 0;

    $$('tbody tr', table).forEach(function (r) {
      var ok = inView(r)
        && words.every(function (w) { return r.dataset.text.indexOf(w) !== -1; })
        && (!tag || r.dataset.tags.split('|').indexOf(tag) !== -1)
        && (!co || r.dataset.co === co);
      if (ok) matches++;
      var showIt = ok && matches <= shown;
      r.hidden = !showIt;
      if (showIt) {
        $('.rank', r).textContent = matches + '.';
        r.classList.toggle('z', visible % 2 === 1);
        visible++;
      }
    });
    $$('.tag').forEach(function (t) { t.classList.toggle('active', !!tag && t.dataset.tag === tag); });

    var filtered = words.length || tag || co;
    clearBtn.hidden = !filtered;
    countEl.textContent = matches + (matches === 1 ? ' job' : ' jobs');
    emptyEl.hidden = matches > 0;
    emptyEl.textContent = filtered ? 'No jobs match your search.' : (isDead ? 'Nothing in the Dead zone yet.' : 'No jobs here yet.');
    table.hidden = matches === 0;
    moreEl.hidden = matches <= visible;
  }

  function go(v) {
    if (!JOB_VIEWS.hasOwnProperty(v) && OTHER_VIEWS.indexOf(v) === -1) v = 'all';
    view = v; shown = PER_PAGE; render();
  }

  /* ---------- wiring ---------- */
  window.addEventListener('hashchange', function () { go(location.hash.slice(1)); });
  q.addEventListener('input', function () { shown = PER_PAGE; render(); });
  fTag.addEventListener('change', function () { shown = PER_PAGE; render(); });
  fCo.addEventListener('change', function () { shown = PER_PAGE; render(); });
  clearBtn.addEventListener('click', function (e) { e.preventDefault(); q.value = ''; fTag.value = ''; fCo.value = ''; shown = PER_PAGE; render(); });
  $('#moreBtn').addEventListener('click', function () { shown += PER_PAGE; render(); });

  document.addEventListener('click', function (e) {
    var pin = e.target.closest('.pinbtn');
    if (pin) { togglePin(pin.closest('tr').dataset.id); return; }
    var tag = e.target.closest('.tag');        // click a tag to filter by it (click again to undo)
    if (tag) { fTag.value = fTag.value === tag.dataset.tag ? '' : tag.dataset.tag; shown = PER_PAGE; render(); }
  });

  renderPins();
  go(location.hash.slice(1));
})();
