<?php
require_once __DIR__ . '/lib/helpers.php';

$settings = require __DIR__ . '/config/settings.php';

$jobsFile = json_decode((string)@file_get_contents(__DIR__ . '/data/jobs.json'), true);
$all = array_values(array_filter(is_array($jobsFile) ? $jobsFile : [], fn($j) => !empty($j['title']) && is_safe_url($j['url'] ?? '')));
usort($all, fn($a, $b) => strcmp($b['posted_at'] ?? '', $a['posted_at'] ?? ''));   // newest first

// Split into the two tabs
$tabs = ['india' => [], 'abroad' => []];
foreach ($all as $j) $tabs[region_of($j)][] = $j;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($settings['site_name']) ?></title>
  <style><?= file_get_contents(__DIR__ . '/style.css') ?></style>
</head>
<body>
<div id="page">
  <div id="topbar">
    <span class="logo">D</span><span class="name"><?= h($settings['site_name']) ?></span>
    <a href="#india" data-tab="india" class="on">india <span class="count">(<?= count($tabs['india']) ?>)</span></a>
    |
    <a href="#abroad" data-tab="abroad">abroad <span class="count">(<?= count($tabs['abroad']) ?>)</span></a>
  </div>

  <div id="intro">Creative jobs <span id="where">in India</span>.</div>
  <form id="search" onsubmit="return false">
    <input type="text" id="q" placeholder="Search title, company, city" autocomplete="off">
    <a class="clear" id="clear" href="#" hidden>clear</a>
  </form>

  <?php foreach ($tabs as $tab => $list): ?>
    <ol class="jobs" data-tab="<?= $tab ?>">
      <?php foreach ($list as $j): ?>
        <li data-text="<?= h(mb_strtolower(($j['title'] ?? '') . ' ' . ($j['company'] ?? '') . ' ' . ($j['location'] ?? ''))) ?>">
          <span class="rank"></span>
          <div>
            <div class="title">
              <a href="<?= h($j['url']) ?>" target="_blank" rel="nofollow noopener"><?= h($j['company']) ?> is hiring: <?= h($j['title']) ?></a>
              <span class="domain">(<a href="<?= h($j['url']) ?>" target="_blank" rel="nofollow noopener"><?= h(domain_of($j['url'])) ?></a>)</span>
            </div>
            <div class="subtext">
              <?= h(time_ago(strtotime($j['posted_at'] ?? '') ?: null)) ?>
              <?php if (!empty($j['location'])): ?> | <?= h($j['location']) ?><?php endif; ?>
            </div>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endforeach; ?>

  <div class="empty" id="empty" hidden>No jobs match your search.</div>
  <div class="more" id="more" hidden><a href="#" id="moreLink">More</a></div>

  <div id="footer"><?= h($settings['site_name']) ?></div>
</div>

<script>
// Tabs, search and "More" all happen right here on the page, with no reloading.
(function () {
  var PER_PAGE = <?= (int)$settings['jobs_per_page'] ?>;
  var tabLinks = document.querySelectorAll('#topbar a[data-tab]');
  var lists    = document.querySelectorAll('ol.jobs');
  var q        = document.getElementById('q');
  var clear    = document.getElementById('clear');
  var more     = document.getElementById('more');
  var empty    = document.getElementById('empty');
  var where    = document.getElementById('where');
  var tab = 'india', shown = PER_PAGE;

  function show() {
    var words = q.value.toLowerCase().split(/\s+/).filter(Boolean);
    var matches = 0, visible = 0;

    lists.forEach(function (ol) {
      var active = ol.dataset.tab === tab;
      ol.hidden = !active;
      if (!active) return;
      ol.querySelectorAll('li').forEach(function (li) {
        var ok = words.every(function (w) { return li.dataset.text.indexOf(w) !== -1; });
        if (ok) matches++;
        var showIt = ok && matches <= shown;
        li.hidden = !showIt;
        if (showIt) { visible++; li.querySelector('.rank').textContent = matches + '.'; }
      });
    });

    tabLinks.forEach(function (a) { a.classList.toggle('on', a.dataset.tab === tab); });
    where.textContent = tab === 'india' ? 'in India' : 'abroad';
    empty.hidden = matches > 0;
    more.hidden  = matches <= visible;
    clear.hidden = !q.value;
  }

  function setTab(t, updateHash) {
    tab = t; shown = PER_PAGE;
    if (updateHash) history.replaceState(null, '', '#' + t);
    show();
  }

  tabLinks.forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); setTab(a.dataset.tab, true); });
  });
  q.addEventListener('input', function () { shown = PER_PAGE; show(); });
  clear.addEventListener('click', function (e) { e.preventDefault(); q.value = ''; shown = PER_PAGE; show(); q.focus(); });
  document.getElementById('moreLink').addEventListener('click', function (e) { e.preventDefault(); shown += PER_PAGE; show(); });

  // Open on the tab in the address (e.g. yoursite.com/#abroad), otherwise India.
  // Also follows the address if it changes (browser back button, typing a new #address).
  function fromAddress() { setTab(location.hash === '#abroad' ? 'abroad' : 'india', false); }
  window.addEventListener('hashchange', fromAddress);
  fromAddress();
})();
</script>
</body>
</html>
