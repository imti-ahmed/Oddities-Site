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
    ob_start(); ?>
    <tr data-id="<?= h($j['id']) ?>" data-region="<?= h($j['region']) ?>" data-mode="<?= h($j['mode']) ?>" data-co="<?= h($j['company']) ?>" data-title="<?= h($j['title']) ?>" data-url="<?= h($j['url']) ?>">
      <td class="rank"></td>
      <td class="job">
        <?php if ($isDead): ?>
          <span class="stamp" title="<?= h($j['dead'] === 'closed' ? ($j['dead_note'] ?: 'Marked as closed') : "Not posted or updated in over $staleDays days") ?>"><?= $j['dead'] === 'closed' ? 'Closed' : 'Inactive' ?></span>
        <?php elseif ($j['badge']): ?>
          <span class="blinkie <?= h($j['badge']) ?>"><?= h($j['badge']) ?></span>
        <?php endif; ?>
        <a class="jt" href="<?= h($j['url']) ?>" target="_blank" rel="nofollow noopener"><?= h($j['title']) ?></a>
        <div class="m-only meta"><span class="co"><?= h($j['company']) ?></span><br><?= pills($j['tags']) ?><br><?= h(trim($j['location'] . ($j['location'] && $posted ? ' · ' : '') . $posted)) ?></div>
      </td>
      <td class="company"><span class="cn"><?= h($j['company']) ?></span><?php if ($j['location']): ?><small class="cloc"><?= h($j['location']) ?></small><?php endif; ?></td>
      <td class="tags"><?= pills($j['tags']) ?></td>
      <td class="posted"><?= h($posted) ?></td>
      <td class="pin"><button type="button" class="pinbtn" title="Pin this job" aria-label="Pin this job">&#9734;</button></td>
    </tr>
    <?php return ob_get_clean();
}

// The little Mac-window strip on every card: three dots and a faint "x" (decoration only, no title).
function winbar(): string
{
    return '<div class="winbar" aria-hidden="true"><i class="dot r"></i><i class="dot y"></i><i class="dot g"></i><span class="wx">x</span></div>';
}

function table_head(): string
{
    return '<thead><tr><th class="rank">#</th><th>Job</th><th class="company">Company &amp; location</th><th class="tags">Tag</th><th class="posted">Posted</th><th class="pin"></th></tr></thead>';
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
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Bangers&display=swap">
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
    <div class="intro win" id="intro">
      <?= winbar() ?>
      <div class="winbody"><b><?= h($settings['tagline']) ?></b> Every job links to the company's own page.
      <div class="legend">&#9734; Pin jobs to keep them in the box on the side (saved in this browser).</div></div>
    </div>

    <div class="layout">
      <div class="main">
        <p class="dead-note" id="deadNote" hidden>Jobs that are <b>closed</b>, or <b>inactive</b> (not posted or updated in over <?= (int)$settings['stale_days'] ?> days, so probably not hiring any more). Kept here for reference.</p>
        <div class="card win">
          <?= winbar() ?>
          <table class="jobs" id="liveTable"><?= table_head() ?><tbody>
            <?php foreach ($live as $j) echo job_row($j, false, (int)$settings['stale_days']); ?>
          </tbody></table>
          <table class="jobs dead" id="deadTable" hidden><?= table_head() ?><tbody>
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
        <span><a href="<?= h($e['job']['url']) ?>" target="_blank" rel="nofollow noopener"><b><?= h($e['job']['company']) ?></b> &mdash; <?= h($e['job']['title']) ?></a>
          <?php if ($e['job']['location']): ?><small>&middot; <?= h($e['job']['location']) ?></small><?php endif; ?></span>
      </div>
    <?php endforeach; ?>
    </div>
  </section>

  <!-- ===== About ===== -->
  <section id="aboutView" class="panel win" hidden>
    <?= winbar() ?>
    <div class="winbody">
    <h2>About</h2>
    <p><?= h($settings['site_name']) ?> is a simple, hand-picked list of creative jobs: design, brand, motion, illustration, video, content and more. It's built to be one clean page you can scan quickly and click through to apply.</p>
    <p><b>How it works.</b> Every row links straight to the company's own job page. We don't host applications. Jobs are split into tabs: <b>India</b>, <b>Abroad</b>, <b>Remote</b> and <b>In-office</b>.</p>
    <p><b>New and updated.</b> Jobs that were just added or changed move to the top and get a little <span class="blinkie new">new</span> or <span class="blinkie updated">updated</span> sticker.</p>
    <p><b>Dead zone.</b> Jobs that have closed, or haven't been posted or updated in a long time, move to the Dead zone so the main list stays fresh. Nothing is deleted.</p>
    <p><b>Pins.</b> Click the &#9734; on a job to pin it to the box at the side. Your pins are saved in your own browser, not on any server, so they're private to you (and clearing your browser data clears them).</p>
    <p><b>Tags.</b> Each job gets one sticker that says what kind of job it is, like Product Design, Brand or Motion.</p>
    </div>
  </section>

</main>

<script>window.DJ = { perPage: <?= (int)$settings['jobs_per_page'] ?> };</script>
<script><?= file_get_contents(__DIR__ . '/assets/app.js') ?></script>
</body>
</html>
