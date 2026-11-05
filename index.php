<?php
require_once __DIR__ . '/lib/helpers.php';

$settings = require __DIR__ . '/config/settings.php';
$perPage  = (int)$settings['jobs_per_page'];
$page     = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page - 1) * $perPage;

// All jobs live in one simple file: data/jobs.json
$all = json_decode((string)@file_get_contents(__DIR__ . '/data/jobs.json'), true);
$all = is_array($all) ? array_values(array_filter($all, fn($j) => !empty($j['title']) && is_safe_url($j['url'] ?? ''))) : [];

// Newest first
usort($all, fn($a, $b) => strcmp($b['posted_at'] ?? '', $a['posted_at'] ?? ''));

$total = count($all);
$jobs  = array_slice($all, $offset, $perPage);
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
    <a href="./">jobs</a>
  </div>

  <div id="intro">Creative jobs from around the web. <?= number_format($total) ?> jobs listed.</div>

  <?php if (!$jobs): ?>
    <div class="empty">No jobs yet. Add some to <code>data/jobs.json</code>.</div>
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
    <div class="more"><a href="?p=<?= $page + 1 ?>">More</a></div>
  <?php endif; ?>
  <?php endif; ?>

  <div id="footer"><?= h($settings['site_name']) ?></div>
</div>
</body>
</html>
