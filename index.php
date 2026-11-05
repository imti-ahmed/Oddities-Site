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

$newCount = count(array_filter($live, fn($j) => $j['badge'] === 'new'));
$updCount = count(array_filter($live, fn($j) => $j['badge'] === 'updated'));
$lastUpdate = $all ? max(array_column($all, 'change')) : 0;

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
        <div class="meta"><span class="co"><?= h($j['company']) ?></span> &middot; <?= h(domain_of($j['url'])) ?></div>
        <div class="m-only meta"><?= pills($j['tags']) ?><br><?= h(trim($j['location'] . ($j['location'] && $posted ? ' · ' : '') . $posted)) ?></div>
      </td>
      <td class="tags"><?= pills($j['tags']) ?></td>
      <td class="loc"><?= h($j['location']) ?></td>
      <td class="posted"><?= h($posted) ?></td>
      <td class="pin"><button type="button" class="pinbtn" title="Pin this job" aria-label="Pin this job">&#9734;</button></td>
    </tr>
    <?php return ob_get_clean();
}

function table_head(): string
{
    return '<thead><tr><th class="rank">#</th><th>Job</th><th class="tags">Tags</th><th class="loc">Location</th><th class="posted">Posted</th><th class="pin"></th></tr></thead>';
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
<div class="page">

  <!-- ===== Header: banner + ticker ===== -->
  <header class="banner">
    <h1><a href="#all"><?= h($settings['site_name']) ?></a></h1>
    <p class="sub">~ <b>hand-picked</b> creative jobs in India &amp; abroad ~</p>
    <div class="rainbow"></div>
  </header>
  <?php $tick = '&#9733; WELCOME! &#9733; ' . $n['all'] . ' creative jobs listed &#9733; ' . $newCount . ' brand new, ' . $updCount . ' updated &#9733; pin your favourites with the &#9734; &#9733; every job links to the company\'s own page &#9733; &nbsp;&nbsp;&nbsp;&nbsp;'; ?>
  <div class="ticker" aria-hidden="true"><span><?= $tick ?><i class="dup"><?= $tick ?></i></span></div>

  <!-- ===== The window ===== -->
  <div class="win">
    <div class="titlebar"><span class="name"><?= h($settings['site_name']) ?> - Job Listings</span><span class="btns" aria-hidden="true"><i>_</i><i>&#9633;</i><i>x</i></span></div>

    <nav id="tabs">
      <?php foreach ($tabs as [$key, $label]): ?>
        <a href="#<?= $key ?>" data-view="<?= $key ?>"><?= h($label) ?> <span class="n">(<?= $n[$key] ?>)</span></a>
      <?php endforeach; ?>
      <span class="gap"></span>
      <a href="#dead" data-view="dead">Dead zone <span class="n">(<?= count($dead) ?>)</span></a>
      <a href="#log" data-view="log">Update log</a>
      <a href="#about" data-view="about">About</a>
    </nav>

    <main class="sheet">

      <!-- Jobs + Dead zone -->
      <section id="listView">
        <div class="intro" id="intro">
          <b>Welcome!</b> <?= h($settings['tagline']) ?> Every job links to the company's own page.
          <div class="legend">
            <span class="blinkie new">new</span> added in the last <?= (int)$settings['new_days'] ?> days &nbsp;
            <span class="blinkie updated">updated</span> recently changed &nbsp;&nbsp; &#9734; pin a job to keep it in the box (saved in this browser)
          </div>
        </div>

        <div class="toolbar">
          <label for="q">Search:</label>
          <input type="text" id="q" placeholder="job, company, city..." autocomplete="off">
          <select id="fTag" aria-label="Filter by tag"><option value="">Tag: any</option>
            <?php foreach ($allTags as $t => $_): ?><option><?= h($t) ?></option><?php endforeach; ?></select>
          <select id="fCo" aria-label="Filter by company"><option value="">Company: any</option>
            <?php foreach ($allCompanies as $c => $_): ?><option><?= h($c) ?></option><?php endforeach; ?></select>
          <a href="#" class="clear" id="clear" hidden>clear filters</a>
        </div>

        <div class="layout">
          <div class="main">
            <p class="dead-note" id="deadNote" hidden>Jobs that are <b>closed</b>, or <b>inactive</b> (not posted or updated in over <?= (int)$settings['stale_days'] ?> days, so probably not hiring any more). Kept here for reference.</p>
            <div class="card sunken">
              <table class="jobs" id="liveTable"><?= table_head() ?><tbody>
                <?php foreach ($live as $j) echo job_row($j, false, (int)$settings['stale_days']); ?>
              </tbody></table>
              <table class="jobs dead" id="deadTable" hidden><?= table_head() ?><tbody>
                <?php foreach ($dead as $j) echo job_row($j, true, (int)$settings['stale_days']); ?>
              </tbody></table>
              <div class="empty" id="empty" hidden></div>
            </div>
            <div class="more" id="more" hidden><button type="button" class="btn" id="moreBtn">Show more</button></div>
          </div>

          <aside id="pinbox" aria-label="Pinned jobs">
            <div class="titlebar"><span class="name">&#9733; Pinned (<span id="pinCount">0</span>)</span></div>
            <div class="body">
              <ul id="pinList"></ul>
              <div class="hint" id="pinHint">Click the &#9734; on any job to pin it here. Pins are saved in this browser only.</div>
            </div>
          </aside>
        </div>
      </section>

      <!-- Update log -->
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

      <!-- About -->
      <section id="aboutView" class="panel" hidden>
        <h2>About</h2>
        <p><?= h($settings['site_name']) ?> is a simple, hand-picked list of creative jobs: design, brand, motion, illustration, video, content and more. It's built to be one clean page you can scan quickly and click through to apply.</p>
        <hr>
        <p><b>How it works.</b> Every row links straight to the company's own job page. We don't host applications. Jobs are split into tabs: <b>India</b>, <b>Abroad</b>, <b>Remote</b> and <b>In-office</b>.</p>
        <p><b>New and updated.</b> Jobs that were just added or changed move to the top and get a little <span class="blinkie new">new</span> or <span class="blinkie updated">updated</span> sticker.</p>
        <p><b>Dead zone.</b> Jobs that have closed, or haven't been posted or updated in a long time, move to the Dead zone so the main list stays fresh. Nothing is deleted.</p>
        <p><b>Pins.</b> Click the &#9734; on a job to pin it to the box at the side. Your pins are saved in your own browser, not on any server, so they're private to you (and clearing your browser data clears them).</p>
        <p><b>Tags.</b> The small coloured tags describe the kind of role, the level and whether it's remote. They're a first draft and will get better over time.</p>
      </section>

    </main>

    <div class="status">
      <div id="count"></div>
      <div>Last update: <?= $lastUpdate ? h(date('M j, Y', $lastUpdate)) : 'n/a' ?></div>
      <div>Pins stay in your browser</div>
    </div>
  </div>

  <!-- ===== Footer ===== -->
  <footer>
    <div class="rainbow"></div>
    <div class="links">
      <a href="#india">India</a> | <a href="#abroad">Abroad</a> | <a href="#remote">Remote</a> | <a href="#in-office">In-office</a> |
      <a href="#dead">Dead zone</a> | <a href="#log">Update log</a> | <a href="#about">About</a> | <a href="#top" onclick="window.scrollTo(0,0);return false">Back to top &uarr;</a>
    </div>
    <div class="badges" aria-hidden="true"><div class="b88">PHP<br>powered</div><div class="b88">No<br>sign-up</div><div class="b88">No<br>cookies</div></div>
    <p class="fine">&copy; <?= date('Y') ?> <?= h($settings['site_name']) ?> &middot; Job links lead to external websites we don't control &middot; Your pins stay in your browser.</p>
  </footer>

</div>

<script>window.DJ = { perPage: <?= (int)$settings['jobs_per_page'] ?> };</script>
<script><?= file_get_contents(__DIR__ . '/assets/app.js') ?></script>
</body>
</html>
