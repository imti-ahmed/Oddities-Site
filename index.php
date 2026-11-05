<?php
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/helpers.php';

$settings = require __DIR__ . '/config/settings.php';
$perPage  = (int)$settings['jobs_per_page'];
$page     = max(1, (int)($_GET['p'] ?? 1));
$offset   = ($page - 1) * $perPage;

$jobs = [];
$total = 0;
$lastCheck = 0;
$problem = null;   // filled in if the database can't be reached, so we show a friendly message

try {
$pdo   = db();
$total = (int)$pdo->query("SELECT COUNT(*) FROM jobs WHERE status = 'open'")->fetchColumn();

// Newest jobs first. Only jobs that the tester still believes are open are shown.
$stmt = $pdo->prepare("
    SELECT * FROM jobs WHERE status = 'open'
    ORDER BY COALESCE(posted_at, first_seen) DESC, id DESC
    LIMIT :limit OFFSET :offset");
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$jobs = $stmt->fetchAll();

$lastCheck = (int)$pdo->query("SELECT MAX(last_checked) FROM jobs")->fetchColumn();
} catch (Throwable $e) {
    $problem = $e->getMessage();
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
    <a href="./">jobs</a>
  </div>

  <div id="intro">Creative jobs from around the web. <?= number_format($total) ?> open right now, and every link is re-checked automatically.</div>

  <?php if ($problem): ?>
    <div class="empty"><b>The site can't reach its database yet.</b><br><?= h($problem) ?></div>
  <?php elseif (!$jobs): ?>
    <div class="empty">No jobs yet. Run the finder to fill the list (locally: <code>php cron/fetch.php</code>, on Vercel: open <code>/api/fetch?key=YOUR_CRON_SECRET</code>).</div>
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
            <?= h(time_ago($j['posted_at'] ?: $j['first_seen'])) ?>
            <?php if ($j['location']): ?> | <?= h($j['location']) ?><?php endif; ?>
            | checked <?= h(time_ago($j['last_checked'] ?: $j['first_seen'])) ?>
          </div>
        </div>
      </li>
    <?php endforeach; ?>
  </ol>
  <?php if ($offset + $perPage < $total): ?>
    <div class="more"><a href="?p=<?= $page + 1 ?>">More</a></div>
  <?php endif; ?>
  <?php endif; ?>

  <div id="footer">Last link check: <?= h(time_ago($lastCheck ?: null)) ?></div>
</div>
</body>
</html>
