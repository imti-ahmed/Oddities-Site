<?php
require_once __DIR__ . '/lib/helpers.php';

$settings = require __DIR__ . '/config/settings.php';
$perPage  = (int)$settings['jobs_per_page'];

// Which tab (India or Abroad) and what was searched for
$tab    = ($_GET['tab'] ?? 'india') === 'abroad' ? 'abroad' : 'india';
$q      = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['p'] ?? 1));
$offset = ($page - 1) * $perPage;

$jobsFile = json_decode((string)@file_get_contents(__DIR__ . '/data/jobs.json'), true);
$all = array_values(array_filter(is_array($jobsFile) ? $jobsFile : [], fn($j) => !empty($j['title']) && is_safe_url($j['url'] ?? '')));

// Count jobs in each tab (for the numbers in the top bar)
$counts = ['india' => 0, 'abroad' => 0];
foreach ($all as $j) $counts[region_of($j)]++;

// Only this tab's jobs, optionally narrowed by the search words
$list = array_values(array_filter($all, function ($j) use ($tab, $q) {
    if (region_of($j) !== $tab) return false;
    if ($q === '') return true;
    foreach (preg_split('/\s+/', $q) as $word) {   // every search word must appear somewhere
        if (mb_stripos(($j['title'] ?? '') . ' ' . ($j['company'] ?? '') . ' ' . ($j['location'] ?? ''), $word) === false) return false;
    }
    return true;
}));
usort($list, fn($a, $b) => strcmp($b['posted_at'] ?? '', $a['posted_at'] ?? ''));   // newest first

$total = count($list);
$jobs  = array_slice($list, $offset, $perPage);

// Builds a link that keeps the current tab and search
function link_to(array $extra, string $tab, string $q): string
{
    $params = array_filter(['tab' => $tab, 'q' => $q] + $extra, fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($params);
}
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
    <a href="<?= h(link_to([], 'india', $q)) ?>" class="<?= $tab === 'india' ? 'on' : '' ?>">india <span class="count">(<?= $counts['india'] ?>)</span></a>
    |
    <a href="<?= h(link_to([], 'abroad', $q)) ?>" class="<?= $tab === 'abroad' ? 'on' : '' ?>">abroad <span class="count">(<?= $counts['abroad'] ?>)</span></a>
  </div>

  <div id="intro">Creative jobs <?= $tab === 'india' ? 'in India' : 'abroad' ?>.</div>
  <form id="search" method="get">
    <input type="hidden" name="tab" value="<?= h($tab) ?>">
    <input type="text" name="q" value="<?= h($q) ?>" placeholder="Search title, company, city">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?><a class="clear" href="<?= h(link_to([], $tab, '')) ?>">clear</a><?php endif; ?>
  </form>

  <?php if (!$jobs): ?>
    <div class="empty"><?= $q !== '' ? 'No jobs match your search.' : 'No jobs here yet.' ?></div>
  <?php else: ?>
  <ol class="jobs">
    <?php foreach ($jobs as $i => $j): ?>
      <li>
        <span class="rank"><?= $offset + $i + 1 ?>.</span>
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
  <?php if ($offset + $perPage < $total): ?>
    <div class="more"><a href="<?= h(link_to(['p' => $page + 1], $tab, $q)) ?>">More</a></div>
  <?php endif; ?>
  <?php endif; ?>

  <div id="footer"><?= h($settings['site_name']) ?></div>
</div>
</body>
</html>
