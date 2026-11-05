<?php
require_once __DIR__ . '/lib/jobs.php';

$settings = require __DIR__ . '/config/settings.php';
$all  = load_jobs($settings);
$live = sort_live(array_values(array_filter($all, fn($j) => !$j['dead'])));
$dead = array_slice(sort_dead(array_values(array_filter($all, fn($j) => $j['dead']))), 0, (int)$settings['dead_limit']);   // only the latest few
$log  = build_log($all, $settings);

// Numbers shown on the tabs
$n = ['all' => count($live), 'india' => 0, 'abroad' => 0, 'remote' => 0, 'hybrid' => 0, 'in-office' => 0];
foreach ($live as $j) { $n[$j['region']]++; $n[$j['mode']]++; }

$lastUpdate = $all ? max(array_column($all, 'change')) : 0;   // date of the most recent added/updated job

$tabs = [['all', 'All'], ['india', 'India'], ['abroad', 'Abroad'], ['remote', 'Remote']];
if ($n['hybrid'] > 0) $tabs[] = ['hybrid', 'Hybrid'];
$tabs[] = ['in-office', 'In-office'];

// The tag (the kind of job) is shown as plain text.
function pills(array $tags): string
{
    $out = '';
    foreach ($tags as [$name]) $out .= '<span class="tag">' . h($name) . '</span>';
    return $out;
}

function job_row(array $j, bool $isDead, int $staleDays): string
{
    $posted = $j['posted'] ? time_ago($j['posted']) : '';
    // Dead zone rows show the date it was removed (worked out in lib/jobs.php).
    $removedAt = $isDead ? $j['removed'] : 0;
    $dateCell  = $isDead ? ($removedAt ? date('M j, Y', $removedAt) : '') : $posted;

    // The role name. A NEW / UPDATED pill goes right after it, glued to the last word so it can never sit alone on a new line.
    $title = trim($j['title']);
    if (!$isDead && $j['badge']) {
        $pill  = '<span class="pill ' . h($j['badge']) . '">' . h(strtoupper($j['badge'])) . '</span>';
        $cut   = strrpos($title, ' ');
        $titleHtml = $cut === false ? '<span class="nw">' . h($title) . $pill . '</span>'
                                    : h(substr($title, 0, $cut)) . ' <span class="nw">' . h(substr($title, $cut + 1)) . $pill . '</span>';
    } else {
        $titleHtml = h($title);
    }
    ob_start(); ?>
    <tr data-id="<?= h($j['id']) ?>" data-region="<?= h($j['region']) ?>" data-mode="<?= h($j['mode']) ?>" data-co="<?= h($j['company']) ?>" data-title="<?= h($j['title']) ?>" data-url="<?= h($j['url']) ?>">
      <td class="rank"></td>
      <td class="job">
        <a class="jt" href="<?= h($j['url']) ?>" target="_blank" rel="nofollow noopener"><?= $titleHtml ?></a>
        <div class="m-only meta"><span class="co"><?= h($j['company']) ?></span><?php if ($j['location']): ?> (<?= h($j['location']) ?>)<?php endif; ?><br><?php if (!$isDead): ?><?= pills($j['tags']) ?><br><?php endif; ?><?= h(trim(($isDead && $dateCell ? 'Removed ' : '') . $dateCell)) ?></div>
      </td>
      <td class="company"><span class="cn"><?= h($j['company']) ?></span><?php if ($j['location']): ?> <span class="cloc">(<?= h($j['location']) ?>)</span><?php endif; ?></td>
      <?php if (!$isDead): ?><td class="tags"><?= pills($j['tags']) ?></td><?php endif; ?>
      <td class="posted"><?= h($dateCell) ?></td>
      <?php if (!$isDead): ?><td class="pin"><button type="button" class="pinbtn" title="Pin this job" aria-label="Pin this job">&#9734;</button></td><?php endif; ?>
    </tr>
    <?php return ob_get_clean();
}

// The little Mac-window strip on every card: just three dots (decoration only, no title, no x).
function winbar(): string
{
    return '<div class="winbar" aria-hidden="true"><i class="dot r"></i><i class="dot y"></i><i class="dot g"></i></div>';
}

function table_head(bool $dead = false): string
{
    // The Dead zone is kept simple: no Tag column, and "Removed On" instead of "Posted".
    return '<thead><tr><th class="rank"></th><th>Job</th><th class="company">Company &amp; location</th>'
         . ($dead ? '' : '<th class="tags">Tag</th>')
         . '<th class="posted">' . ($dead ? 'Removed On' : 'Posted') . '</th>' . ($dead ? '' : '<th class="pin"></th>') . '</tr></thead>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($settings['site_name']) ?> - <?= h($settings['tagline']) ?></title>
  <meta name="description" content="<?= h($settings['tagline']) ?>">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/icons/favicon-32.png">
  <link rel="apple-touch-icon" href="/assets/icons/apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <style><?= file_get_contents(__DIR__ . '/style.css') ?></style>
</head>
<body>

<header id="top">
  <div class="bar wrap">
    <a class="brand" href="#all"><?= h($settings['site_name']) ?></a>
    <nav id="tabs">
      <?php foreach ($tabs as [$key, $label]): ?>
        <a href="#<?= $key ?>" data-view="<?= $key ?>"><?= h($label) ?> <span class="n"><?= $n[$key] ?></span></a>
      <?php endforeach; ?>
      <span class="gap"></span>
      <a href="#dead" data-view="dead">Dead zone <span class="n"><?= count($dead) ?></span></a>
      <a href="#log" data-view="log">Update log</a>
      <a href="#about" data-view="about">About</a>
    </nav>
    <div class="sys"><span class="jobsn"><?= $n['all'] ?> jobs</span><span id="clock"></span></div>
  </div>
</header>

<main class="wrap">

  <!-- ===== Jobs + Dead zone ===== -->
  <section id="listView">
    <div class="layout">
      <div class="intro win pink" id="intro">
        <?= winbar() ?>
        <div class="winbody">
          <p>Hand-picked creative jobs. No noise.</p>
          <p>A simple list of design and creative jobs from companies around the internet.<br>No accounts. No applications here. No bullshit. Just the job and a link to where you can apply.</p>        </div>
      </div>

      <!-- Dead zone intro: takes the welcome card's place (and style) on the Dead zone tab -->
      <div class="intro win pink" id="deadNote" hidden>
        <?= winbar() ?>
        <div class="winbody">
          <p>Jobs that are closed, or inactive (not posted or updated in over <?= (int)$settings['stale_days'] ?> days, so probably not hiring any more). Kept here for reference. Any entries more than <?= (int)$settings['dead_limit'] ?> will be removed from here.</p>
        </div>
      </div>

      <div class="main">
        <div class="card win">
          <?= winbar() ?>
          <table class="jobs" id="liveTable"><?= table_head() ?><tbody>
            <?php foreach ($live as $j) echo job_row($j, false, (int)$settings['stale_days']); ?>
          </tbody></table>
          <table class="jobs dead" id="deadTable" hidden><?= table_head(true) ?><tbody>
            <?php foreach ($dead as $j) echo job_row($j, true, (int)$settings['stale_days']); ?>
          </tbody></table>
          <div class="empty" id="empty" hidden></div>
        </div>
        <nav class="pager" id="pager" aria-label="Pages" hidden></nav>
      </div>

      <aside id="pinbox" class="win orange" aria-label="Pinned jobs">
        <?= winbar() ?>
        <div class="winbody">
          <ul id="pinList"></ul>
          <div class="hint" id="pinHint">Tap the &#9734; on any job to pin it here. Pins are saved in this browser only.</div>
        </div>
      </aside>
    </div>
  </section>

  <!-- ===== Update log ===== -->
  <section id="logView" class="panel win" hidden>
    <?= winbar() ?>
    <div class="winbody">
    <h2>Update log</h2>
    <p>Everything that was added, updated, closed or went inactive, newest first.</p>
    <?php
      // One block per day. Inside a day there is ONE pill for each kind of change (so 2 additions + 3 updates = an ADDED pill and an UPDATED pill),
      // with the jobs listed beside it, separated by commas.
      $byDay = [];
      foreach ($log as $e) {
          $key = date('Y-m-d', $e['ts']);
          $byDay[$key]['label'] = $byDay[$key]['label'] ?? date('M j, Y', $e['ts']);
          $byDay[$key]['types'][$e['type']][] = $e['job'];
      }
      foreach ($byDay as $day): ?>
      <div class="log-day-block">
        <div class="log-day"><?= h($day['label']) ?></div>
        <?php foreach (['added', 'updated', 'closed', 'inactive'] as $type): if (empty($day['types'][$type])) continue; ?>
          <div class="log-line">
            <span class="pill <?= $type ?>"><?= strtoupper($type) ?></span>
            <span class="log-items"><?php foreach ($day['types'][$type] as $i => $j): ?><?= $i ? ', ' : '' ?><a href="<?= h($j['url']) ?>" target="_blank" rel="nofollow noopener"><b><?= h($j['company']) ?></b>: <?= h($j['title']) ?></a><?php endforeach; ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </section>

  <!-- ===== About ===== -->
  <section id="aboutView" class="about" hidden>
    <div class="about-row">

      <div class="win pink">
        <?= winbar() ?>
        <div class="winbody">
          <p><b>A simple job board for creative people.</b></p>
          <p>I collect design and creative jobs from around the internet and put them here in one place. Every listing takes you directly to the original job posting.</p>
        </div>
      </div>

      <div class="win orange">
        <?= winbar() ?>
        <div class="winbody">
          <p>Made by Imtiyaz, a designer who spends too much time on the internet and probably too much time looking at job boards.</p>
          <p>I wanted a small, useful place to find creative jobs without all the extra stuff.</p>
        </div>
      </div>

    </div>

    <div class="win">
      <?= winbar() ?>
      <div class="winbody">
        <ol class="steps">
          <li>Jobs are collected from around the internet.</li>
          <li>Everything is hand picked and added manually.</li>
          <li>Every listing links to the original posting.</li>
          <li>Mostly design and creative jobs.</li>
          <li>India, abroad and remote.</li>
          <li>No accounts or applications.</li>
          <li>Pin jobs to save them in your browser.</li>
          <li>Jobs close. The list gets updated.</li>
        </ol>
      </div>
    </div>

    <div class="about-row">

      <div class="win pink">
        <?= winbar() ?>
        <div class="winbody">
          <p><b>Email:</b> <a href="mailto:<?= h($settings['contact_email']) ?>"><?= h($settings['contact_email']) ?></a></p>
        </div>
      </div>

      <div class="win orange">
        <?= winbar() ?>
        <div class="winbody">
          <div class="stats">
            <p><b>Total jobs:</b> <?= (int)$n['all'] ?></p>
            <p><b>Last update:</b> <?= $lastUpdate ? h(date('M j, Y', $lastUpdate)) : 'n/a' ?></p>
            <p><b>Views:</b> <span id="viewsNum">&ndash;</span></p>
          </div>
        </div>
      </div>

    </div>
  </section>

</main>

<script>window.DJ = { perPage: <?= (int)$settings['jobs_per_page'] ?>, views: <?= json_encode($settings['views_counter']) ?> };</script>
<script><?= file_get_contents(__DIR__ . '/assets/app.js') ?></script>
</body>
</html>
