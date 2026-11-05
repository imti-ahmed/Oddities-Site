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

// Lists for the two drop-down filters
$allTags = []; $allCompanies = [];
foreach ($all as $j) { foreach ($j['tags'] as $t) $allTags[$t[0]] = true; $allCompanies[$j['company']] = true; }
ksort($allTags); ksort($allCompanies, SORT_NATURAL | SORT_FLAG_CASE);

$tabs = [['all', 'All'], ['india', 'India'], ['abroad', 'Abroad'], ['remote', 'Remote']];
if ($n['hybrid'] > 0) $tabs[] = ['hybrid', 'Hybrid'];
$tabs[] = ['in-office', 'In-office'];

function pills(array $tags): string
{
    $out = '';
    foreach ($tags as [$name, $kind]) $out .= '<button type="button" class="tag ' . h($kind) . '" data-tag="' . h($name) . '">' . h($name) . '</button>';
    return $out;
}

function job_row(array $j, bool $isDead, int $staleDays): string
{
    $tagNames = implode('|', array_map(fn($t) => $t[0], $j['tags']));
    $text = mb_strtolower($j['title'] . ' ' . $j['company'] . ' ' . $j['location'] . ' ' . str_replace('|', ' ', $tagNames));
    $posted = $j['posted'] ? time_ago($j['posted']) : '';
    ob_start(); ?>
    <tr data-id="<?= h($j['id']) ?>" data-region="<?= h($j['region']) ?>" data-mode="<?= h($j['mode']) ?>" data-co="<?= h($j['company']) ?>" data-tags="<?= h($tagNames) ?>" data-text="<?= h($text) ?>" data-title="<?= h($j['title']) ?>" data-url="<?= h($j['url']) ?>">
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
      <td class="company"><span class="av" style="--h:<?= crc32($j['company']) % 360 ?>"><?= h(mb_strtoupper(mb_substr($j['company'], 0, 1))) ?></span><span class="cn"><?= h($j['company']) ?></span><?php if ($j['location']): ?><small class="cloc"><?= h($j['location']) ?></small><?php endif; ?></td>
      <td class="tags"><?= pills($j['tags']) ?></td>
      <td class="posted"><?= h($posted) ?></td>
      <td class="pin"><button type="button" class="pinbtn" title="Pin this job" aria-label="Pin this job">&#9734;</button></td>
    </tr>
    <?php return ob_get_clean();
}

function table_head(): string
{
    return '<thead><tr><th class="rank">#</th><th>Job</th><th class="company">Company &amp; location</th><th class="tags">Tags</th><th class="posted">Posted</th><th class="pin"></th></tr></thead>';
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
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bangers&display=swap">
  <style><?= file_get_contents(__DIR__ . '/style.css') ?></style>
</head>
<body>

<header id="top">
  <div class="wrap">
    <a class="brand" href="#all"><span class="mark">&#127912;</span><?= h($settings['site_name']) ?></a>
    <span class="check">&#9989; Hand-picked creative jobs</span>
    <span class="check">&#9989; India &amp; abroad</span>
  </div>
</header>

<main class="wrap">
  <nav id="tabs">
      <?php foreach ($tabs as [$key, $label]): ?>
        <a href="#<?= $key ?>" data-view="<?= $key ?>"><?= h($label) ?> <span class="n">(<?= $n[$key] ?>)</span></a>
      <?php endforeach; ?>
      <span class="gap"></span>
      <a href="#dead" data-view="dead">Dead zone <span class="n">(<?= count($dead) ?>)</span></a>
      <a href="#log" data-view="log">Update log</a>
      <a href="#about" data-view="about">About</a>
    </nav>


  <!-- ===== Jobs + Dead zone ===== -->
  <section id="listView">
    <div class="intro" id="intro">
      <b><?= h($settings['tagline']) ?></b> Every job links to the company's own page.
      <div class="legend">
        <span class="blinkie new">new</span> added in the last <?= (int)$settings['new_days'] ?> days &nbsp;
        <span class="blinkie updated">updated</span> recently changed &nbsp; &middot; &nbsp;
        &#9734; pin jobs to keep them in the box (saved in this browser)
      </div>
    </div>

    <div class="toolbar">
      <input type="text" id="q" placeholder="Search jobs, companies, cities..." autocomplete="off" aria-label="Search">
      <select id="fTag" aria-label="Filter by tag"><option value="">Tag</option>
        <?php foreach ($allTags as $t => $_): ?><option><?= h($t) ?></option><?php endforeach; ?></select>
      <select id="fCo" aria-label="Filter by company"><option value="">Company</option>
        <?php foreach ($allCompanies as $c => $_): ?><option><?= h($c) ?></option><?php endforeach; ?></select>
      <a href="#" class="clear" id="clear" hidden>clear filters</a>
      <span class="count" id="count"></span>
    </div>

    <div class="layout">
      <div class="main">
        <p class="dead-note" id="deadNote" hidden>Jobs that are <b>closed</b>, or <b>inactive</b> (not posted or updated in over <?= (int)$settings['stale_days'] ?> days, so probably not hiring any more). Kept here for reference.</p>
        <div class="card">
          <table class="jobs" id="liveTable"><?= table_head() ?><tbody>
            <?php foreach ($live as $j) echo job_row($j, false, (int)$settings['stale_days']); ?>
          </tbody></table>
          <table class="jobs dead" id="deadTable" hidden><?= table_head() ?><tbody>
            <?php foreach ($dead as $j) echo job_row($j, true, (int)$settings['stale_days']); ?>
          </tbody></table>
          <div class="empty" id="empty" hidden></div>
        </div>
        <div class="more" id="more" hidden><button type="button" id="moreBtn">Show more</button></div>
      </div>

      <aside id="pinbox" aria-label="Pinned jobs">
        <h3>&#128204; Pinned <span id="pinCount">0</span></h3>
        <ul id="pinList"></ul>
        <div class="hint" id="pinHint">Tap the &#9734; on any job to pin it here. Pins are saved in this browser only.</div>
      </aside>
    </div>
  </section>

  <!-- ===== Update log ===== -->
  <section id="logView" class="panel" hidden>
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
  </section>

  <!-- ===== About ===== -->
  <section id="aboutView" class="panel" hidden>
    <h2>About</h2>
    <p><?= h($settings['site_name']) ?> is a simple, hand-picked list of creative jobs: design, brand, motion, illustration, video, content and more. It's built to be one clean page you can scan quickly and click through to apply.</p>
    <p><b>How it works.</b> Every row links straight to the company's own job page. We don't host applications. Jobs are split into tabs: <b>India</b>, <b>Abroad</b>, <b>Remote</b> and <b>In-office</b>.</p>
    <p><b>New and updated.</b> Jobs that were just added or changed move to the top and glow with a little <span class="blinkie new">new</span> or <span class="blinkie updated">updated</span> badge.</p>
    <p><b>Dead zone.</b> Jobs that have closed, or haven't been posted or updated in a long time, move to the Dead zone so the main list stays fresh. Nothing is deleted.</p>
    <p><b>Pins.</b> Click the &#9734; on a job to pin it to the box at the side. Your pins are saved in your own browser, not on any server, so they're private to you (and clearing your browser data clears them).</p>
    <p><b>Tags.</b> The small coloured tags describe the kind of role, the level and whether it's remote. They're a first draft and will get better over time.</p>
  </section>

</main>

<footer>
  <div class="wrap">
    <div class="col grow"><h4><?= h($settings['site_name']) ?></h4><p><?= h($settings['tagline']) ?> One simple page, no sign-up.</p></div>
    <div class="col"><h4>Browse</h4>
      <a href="#india">India</a><a href="#abroad">Abroad</a><a href="#remote">Remote</a><a href="#in-office">In-office</a></div>
    <div class="col"><h4>Site</h4>
      <a href="#dead">Dead zone</a><a href="#log">Update log</a><a href="#about">About</a></div>
  </div>
  <div class="small">&copy; <?= date('Y') ?> <?= h($settings['site_name']) ?> &middot; Job links lead to external websites we don't control &middot; Your pins stay in your browser.</div>
</footer>

<script>window.DJ = { perPage: <?= (int)$settings['jobs_per_page'] ?> };</script>
<script><?= file_get_contents(__DIR__ . '/assets/app.js') ?></script>
</body>
</html>
