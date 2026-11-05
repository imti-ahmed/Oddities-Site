<?php
require_once __DIR__ . '/lib/jobs.php';

$settings = require __DIR__ . '/config/settings.php';
$all  = load_jobs($settings);
$live = sort_live(array_values(array_filter($all, fn($j) => !$j['dead'])));
$dead = sort_dead(array_values(array_filter($all, fn($j) => $j['dead'])));
$log  = build_log($all, $settings);

// Numbers shown on the tabs
$n = ['all' => count($live), 'india' => 0, 'abroad' => 0, 'remote' => 0, 'hybrid' => 0, 'in-office' => 0];
foreach ($live as $j) { $n[$j['region']]++; $n[$j['mode']]++; }

$lastUpdate = $all ? max(array_column($all, 'change')) : 0;   // date of the most recent added/updated job

$tabs = [['all', 'All'], ['india', 'India'], ['abroad', 'Abroad'], ['remote', 'Remote']];
if ($n['hybrid'] > 0) $tabs[] = ['hybrid', 'Hybrid'];
$tabs[] = ['in-office', 'In-office'];

// Sticker colour for each kind of job (anything new gets a colour picked from its name)
function tag_style(string $name): string
{
    $colors = ['Product Design' => '#b9d3ff', 'Brand' => '#ffd6a5', 'Graphic' => '#ffb3cf', 'Motion' => '#d9bfff', 'Video' => '#ffb8b0',
               'Illustration' => '#c4f0bd', 'Content' => '#b5ece0', 'Research' => '#d8d8f6', 'Learning' => '#ffe6a8', 'Creative' => '#ffc4e6',
               'Design' => '#e3e6ea', 'Other' => '#e3e6ea'];
    $h = crc32($name);
    $c = $colors[$name] ?? 'hsl(' . ($h % 360) . ' 85% 82%)';
    return '--c:' . $c . ';--r:' . (($h % 5) - 2) . 'deg';
}

function pills(array $tags): string
{
    $out = '';
    foreach ($tags as [$name]) $out .= '<span class="tag" style="' . h(tag_style($name)) . '">' . h($name) . '</span>';
    return $out;
}

function job_row(array $j, bool $isDead, int $staleDays): string
{
    $posted = $j['posted'] ? time_ago($j['posted']) : '';
    // Dead zone rows show the date it was removed: the closed date, or for inactive jobs the day the "inactive" rule moved it here.
    $removedAt = !$isDead ? 0 : ($j['dead'] === 'closed' ? ($j['closed'] ?: $j['change']) : max($j['posted'], $j['updated']) + $staleDays * 86400);
    $dateCell  = $isDead ? ($removedAt ? date('M j, Y', $removedAt) : '') : $posted;
    ob_start(); ?>
    <tr data-id="<?= h($j['id']) ?>" data-region="<?= h($j['region']) ?>" data-mode="<?= h($j['mode']) ?>" data-co="<?= h($j['company']) ?>" data-title="<?= h($j['title']) ?>" data-url="<?= h($j['url']) ?>">
      <td class="rank"></td>
      <td class="job">
        <?php if (!$isDead && $j['badge']): ?>
          <span class="blinkie <?= h($j['badge']) ?>" data-t="<?= h($j['badge']) ?>"><?= h($j['badge']) ?></span>
        <?php endif; ?>
        <a class="jt" href="<?= h($j['url']) ?>" target="_blank" rel="nofollow noopener"><?= h($j['title']) ?></a>
        <div class="m-only meta"><span class="co"><?= h($j['company']) ?></span><br><?php if (!$isDead): ?><?= pills($j['tags']) ?><br><?php endif; ?><?= h(trim($j['location'] . ($j['location'] && $dateCell ? ' · ' : '') . ($isDead && $dateCell ? 'Removed ' : '') . $dateCell)) ?></div>
      </td>
      <td class="company"><span class="cn"><?= h($j['company']) ?></span><?php if ($j['location']): ?><small class="cloc"><?= h($j['location']) ?></small><?php endif; ?></td>
      <?php if (!$isDead): ?><td class="tags"><?= pills($j['tags']) ?></td><?php endif; ?>
      <td class="posted"><?= h($dateCell) ?></td>
      <td class="pin"><button type="button" class="pinbtn" title="Pin this job" aria-label="Pin this job">&#9734;</button></td>
    </tr>
    <?php return ob_get_clean();
}

// The little Mac-window strip on every card: three dots and a faint "x" (decoration only, no title).
function winbar(): string
{
    return '<div class="winbar" aria-hidden="true"><i class="dot r"></i><i class="dot y"></i><i class="dot g"></i><span class="wx">x</span></div>';
}

function table_head(bool $dead = false): string
{
    // The Dead zone is kept simple: no Tag column, and "Removed On" instead of "Posted".
    return '<thead><tr><th class="rank">#</th><th>Job</th><th class="company">Company &amp; location</th>'
         . ($dead ? '' : '<th class="tags">Tag</th>')
         . '<th class="posted">' . ($dead ? 'Removed On' : 'Posted') . '</th><th class="pin"></th></tr></thead>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($settings['site_name']) ?> - <?= h($settings['tagline']) ?></title>
  <meta name="description" content="<?= h($settings['tagline']) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Bangers&family=Playfair+Display:ital,wght@1,700&display=swap">
  <style><?= file_get_contents(__DIR__ . '/style.css') ?></style>
</head>
<body>

<header id="top">
  <div class="bar wrap">
    <a class="brand" href="#all"><?= h(mb_strtolower($settings['site_name'])) ?></a>
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
      <div class="intro win" id="intro">
        <?= winbar() ?>
        <div class="winbody">
          <p>Hand-picked creative jobs. No noise.</p>
          <p>A simple list of design and creative jobs from companies around the internet.<br>No accounts. No applications here. No bullshit. Just the job and a link to where you can apply.</p>        </div>
      </div>

      <!-- Dead zone intro: takes the welcome card's place (and style) on the Dead zone tab -->
      <div class="intro win" id="deadNote" hidden>
        <?= winbar() ?>
        <div class="winbody">
          <p>Jobs that are closed, or inactive (not posted or updated in over <?= (int)$settings['stale_days'] ?> days, so probably not hiring any more). Kept here for reference.</p>
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
        <div class="more" id="more" hidden><button type="button" class="btn-aqua" id="moreBtn">Show more</button></div>
      </div>

      <aside id="pinbox" class="win" aria-label="Pinned jobs">
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
    <?php $day = ''; foreach ($log as $e):
        $d = date('M j, Y', $e['ts']);
        if ($d !== $day): $day = $d; ?><div class="log-day"><?= h($d) ?></div><?php endif; ?>
      <div class="log-row">
        <span class="stamp <?= h($e['type']) ?>"><?= h($e['type']) ?></span>
        <span><a href="<?= h($e['job']['url']) ?>" target="_blank" rel="nofollow noopener"><b><?= h($e['job']['company']) ?></b>: <?= h($e['job']['title']) ?></a>
          <?php if ($e['job']['location']): ?><small>&middot; <?= h($e['job']['location']) ?></small><?php endif; ?></span>
      </div>
    <?php endforeach; ?>
    </div>
  </section>

  <!-- ===== About ===== -->
  <section id="aboutView" class="about" hidden>
    <div class="about-row">

      <div class="win">
        <?= winbar() ?>
        <div class="winbody">
          <p>A simple job board for creative people.</p>
          <p>I collect design and creative jobs from around the internet and put them here in one place. Every listing takes you directly to the original job posting.</p>        </div>
      </div>

      <div class="win">
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

      <div class="win">
        <?= winbar() ?>
        <div class="winbody">
          <p>Email: <a href="mailto:<?= h($settings['contact_email']) ?>"><?= h($settings['contact_email']) ?></a></p>
        </div>
      </div>

      <div class="win">
        <?= winbar() ?>
        <div class="winbody">
          <div class="stats">
            <p>Total jobs: <?= (int)$n['all'] ?></p>
            <p>Last update: <?= $lastUpdate ? h(date('M j, Y', $lastUpdate)) : 'n/a' ?></p>
            <p>Views: <span id="viewsNum">&ndash;</span></p>
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
