<?php
require_once __DIR__ . '/lib/jobs.php';

$settings = require __DIR__ . '/config/settings.php';
$all  = load_jobs($settings);
$live = sort_live(array_values(array_filter($all, fn($j) => !$j['dead'])));
$dead = sort_dead(array_values(array_filter($all, fn($j) => $j['dead'])));   // every dead job, most recently removed first (the page numbers handle long lists)
$log  = build_log($all, $settings);

require_once __DIR__ . '/lib/directory.php';
$tools      = directory_items('tools');
$portfolios = directory_items('portfolios');
$companies  = directory_items('companies');

// The Dead Zone holds everything that stopped working: closed or stale jobs, and portfolio / tool / company websites that are gone.
$deadItems = [];
foreach ($dead as $j) $deadItems[] = ['id' => $j['id'], 'type' => 'Job', 'title' => trim($j['title']) . ' (' . trim($j['company']) . ')', 'url' => $j['url'], 'removed' => $j['removed']];
foreach ($tools as $it)      if ($it['dead']) $deadItems[] = ['id' => $it['id'], 'type' => 'Tools',     'title' => $it['name'], 'url' => $it['link'], 'removed' => $it['removed']];
foreach ($portfolios as $it) if ($it['dead']) $deadItems[] = ['id' => $it['id'], 'type' => 'Portfolio', 'title' => $it['name'], 'url' => $it['link'], 'removed' => $it['removed']];
foreach ($companies as $it)  if ($it['dead']) $deadItems[] = ['id' => $it['id'], 'type' => 'Company',   'title' => $it['name'], 'url' => $it['link'], 'removed' => $it['removed']];
usort($deadItems, fn($a, $b) => $b['removed'] <=> $a['removed']);   // most recently removed first

// Tools, portfolios and companies are in the update log too: ADDED when saved, CLOSED when their website stopped working.
foreach ([['Tools', $tools], ['Portfolio', $portfolios], ['Company', $companies]] as [$label, $list]) {
    foreach ($list as $it) {
        $entry = ['label' => $label, 'text' => $it['name'], 'url' => $it['link']];
        if ($it['added'])                  $log[] = ['ts' => $it['added'],   'type' => 'added',  'job' => $entry];
        if ($it['updated'] > $it['added'] + 3600) $log[] = ['ts' => $it['updated'], 'type' => 'updated', 'job' => $entry];   // (a change within the first hour is just the screenshot being saved)
        if ($it['dead'] && $it['removed']) $log[] = ['ts' => $it['removed'], 'type' => 'closed', 'job' => $entry];
    }
}
// Every release (config/versions.php) is a "SITE UPDATE" line in the Update log: the version, then the short line in brackets.
$releases = require __DIR__ . '/config/versions.php';
$siteVersion = 'V.' . ($releases[0]['version'] ?? '1.0');   // the newest release: shown beside the clock
foreach ($releases as $rel) $log[] = ['ts' => strtotime($rel['date'] . ' 12:00') ?: 0, 'type' => 'site', 'job' => ['label' => 'V.' . $rel['version'], 'text' => $rel['note'], 'site' => true]];
usort($log, fn($a, $b) => $b['ts'] <=> $a['ts']);
$log = array_slice($log, 0, 200);

// The galleries only show sites that still work.
$tools      = array_values(array_filter($tools, fn($i) => !$i['dead']));
$portfolios = array_values(array_filter($portfolios, fn($i) => !$i['dead']));
$companies  = array_values(array_filter($companies, fn($i) => !$i['dead']));

// NEW / UPDATED pills for portfolios, tools and companies, like the jobs: NEW for 'new_hours' hours after an entry is added, UPDATED for 'new_hours' hours
// after a later change. Entries with a pill go to the top (after the ones you pinned for everyone), most recent change first.
$newCut = time() - (int)$settings['new_hours'] * 3600;
$withBadges = function (array $list) use ($settings, $newCut): array {
    foreach ($list as &$it) {
        $it['badge'] = null; $it['change'] = max($it['added'], $it['updated']);
        if (empty($settings['show_badges'])) continue;
        if ($it['added'] >= $newCut) $it['badge'] = 'new';
        elseif ($it['updated'] > $it['added'] + 3600 && $it['updated'] >= $newCut) $it['badge'] = 'updated';
    }
    unset($it);
    usort($list, function ($a, $b) {
        if ($a['pinned'] !== $b['pinned']) return $a['pinned'] ? -1 : 1;
        if ((bool)$a['badge'] !== (bool)$b['badge']) return $a['badge'] ? -1 : 1;
        return $a['badge'] ? $b['change'] <=> $a['change'] : 0;      // (usort keeps the old order for everything else)
    });
    return $list;
};
$tools = $withBadges($tools); $portfolios = $withBadges($portfolios); $companies = $withBadges($companies);

// Numbers shown on the tabs
$n = ['all' => count($live), 'highlighted' => 0, 'india' => 0, 'abroad' => 0, 'remote' => 0];
foreach ($live as $j) {
    $n[$j['region']]++;
    if ($j['mode'] === 'remote') $n['remote']++;
    if ($j['highlighted']) $n['highlighted']++;
}

$totalOddities = $n['all'] + count($tools) + count($portfolios) + count($companies);   // every working job, portfolio, tool and company
$lastUpdate = max($all ? max(array_column($all, 'change')) : 0, ...array_column(array_merge($tools, $portfolios, $companies), 'added'), ...array_column($deadItems, 'removed'));   // the most recent addition or change of anything
$isAdmin = defined('IS_ADMIN') && IS_ADMIN;                    // true only when the page is opened at /admin (see api/admin.php)
if (!$isAdmin) header('Cache-Control: public, s-maxage=60, stale-while-revalidate=300');   // Vercel keeps the page for a minute, so the database is not asked on every visit

$tabs = [['all', 'All'], ['highlighted', 'Highlighted'], ['india', 'India'], ['abroad', 'Abroad'], ['remote', 'Remote']];

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
    // Dead Zone rows show the date it was removed (worked out in lib/jobs.php).
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
    <tr data-id="<?= h($j['id']) ?>" data-region="<?= h($j['region']) ?>" data-mode="<?= h($j['mode']) ?>" data-co="<?= h($j['company']) ?>" data-title="<?= h($j['title']) ?>" data-url="<?= h($j['url']) ?>" data-hl="<?= !$isDead && $j['highlighted'] ? '1' : '' ?>">
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

// One row of the Dead Zone table: Title, Type (Job / Portfolio / Tools) and the date it was removed.
function dead_row(array $d): string
{
    $when = $d['removed'] ? date('M j, Y', $d['removed']) : '';
    ob_start(); ?>
    <tr data-id="<?= h($d['id']) ?>" data-title="<?= h($d['title']) ?>" data-url="<?= h($d['url']) ?>">
      <td class="rank"></td>
      <td class="job">
        <a class="jt" href="<?= h($d['url']) ?>" target="_blank" rel="nofollow noopener"><?= h($d['title']) ?></a>
        <div class="m-only meta"><?= h($d['type']) ?><?= $when ? ' · Removed ' . h($when) : '' ?></div>
      </td>
      <td class="type"><?= h($d['type']) ?></td>
      <td class="posted"><?= h($when) ?></td>
    </tr>
    <?php return ob_get_clean();
}

// The little NEW / UPDATED sticker (same as on the jobs).
function pill_html(?string $badge): string
{
    return $badge ? '<span class="pill ' . h($badge) . '">' . h(strtoupper($badge)) . '</span>' : '';
}

// A name followed by its sticker, glued to the last word so the sticker can never sit alone on a new line (as in the job rows).
function title_with_pill(string $title, ?string $badge): string
{
    $title = trim($title);
    if (!$badge) return h($title);
    $cut = strrpos($title, ' ');
    return $cut === false ? '<span class="nw">' . h($title) . pill_html($badge) . '</span>'
                          : h(substr($title, 0, $cut)) . ' <span class="nw">' . h(substr($title, $cut + 1)) . pill_html($badge) . '</span>';
}

// The Companies list: a plain table like the jobs. Name, Website, Type, Description, and a pin star.
function company_head(): string
{
    return '<thead><tr><th class="rank"></th><th>Name</th><th class="cweb">Website</th><th class="ctype">Type</th><th class="cdesc">Description</th><th class="pin"></th></tr></thead>';
}

function company_row(array $c, int $i): string
{
    $host = preg_replace('/^www\./', '', (string)parse_url($c['link'], PHP_URL_HOST));
    $label = $c['type'] !== '' ? 'Company · ' . $c['type'] : 'Company';
    ob_start(); ?>
    <tr class="crow<?= $i % 2 ? ' z' : '' ?>" data-id="<?= h($c['id']) ?>" data-co="<?= h($label) ?>" data-title="<?= h($c['name']) ?>" data-url="<?= h($c['link']) ?>">
      <td class="rank"><?= $i + 1 ?>.</td>
      <td class="job">
        <a class="jt" href="<?= h($c['link']) ?>" target="_blank" rel="nofollow noopener"><?= title_with_pill($c['name'], $c['badge'] ?? null) ?></a>
        <div class="m-only meta"><a class="glink" href="<?= h($c['link']) ?>" target="_blank" rel="nofollow noopener"><?= h($host) ?></a><?= $c['type'] !== '' ? ' &middot; ' . h($c['type']) : '' ?><?php if ($c['description'] !== ''): ?><br><?= h($c['description']) ?><?php endif; ?></div>
      </td>
      <td class="cweb"><a class="glink" href="<?= h($c['link']) ?>" target="_blank" rel="nofollow noopener"><?= h($host) ?></a></td>
      <td class="ctype"><?= h($c['type']) ?></td>
      <td class="cdesc"><div class="clamp"><?= h($c['description']) ?></div></td>
      <td class="pin"><button type="button" class="pinbtn" title="Pin this" aria-label="Pin this">&#9734;</button></td>
    </tr>
    <?php return ob_get_clean();
}

// One card in the Portfolios / Tools gallery: a picture, the name (opens the site), the tag (tools only) and a pin star.
function gallery_card(array $it, string $kind): string
{
    $color = $kind === 'tools' ? ' purple' : ' green';   // Tools cards have a purple frame, Portfolio cards a green one
    $host = preg_replace('/^www\./', '', (string)parse_url($it['link'], PHP_URL_HOST));
    $label = $kind === 'tools' ? ($it['tag'] !== '' ? 'Tool · ' . $it['tag'] : 'Tool') : 'Portfolio';
    ob_start(); ?>
    <div class="win gitem<?= $color ?>" data-id="<?= h($it['id']) ?>" data-co="<?= h($label) ?>" data-title="<?= h($it['name']) ?>" data-url="<?= h($it['link']) ?>">
      <div class="ginner">
        <a class="gimg" href="<?= h($it['link']) ?>" target="_blank" rel="nofollow noopener" tabindex="-1" aria-hidden="true"><img src="<?= h(directory_image($it)) ?>" alt="" loading="lazy"></a>
        <div class="gcap">
          <div class="gtxt">
            <div class="gtitle"><a class="gt" href="<?= h($it['link']) ?>" target="_blank" rel="nofollow noopener"><?= h($it['name']) ?></a><?= pill_html($it['badge'] ?? null) ?></div>
            <small><a class="glink" href="<?= h($it['link']) ?>" target="_blank" rel="nofollow noopener"><?= h($host) ?></a><?php if ($kind === 'tools' && $it['tag'] !== ''): ?> &middot; <?= h($it['tag']) ?><?php endif; ?></small>
          </div>
          <button type="button" class="pinbtn" title="Pin this" aria-label="Pin this">&#9734;</button>
        </div>
      </div>
    </div>
    <?php return ob_get_clean();
}

// The /admin card: choose what kind of entry you are adding (Job, Portfolio, Tools), paste a link, the AI makes the entry.
function admin_card(): string
{
    ob_start(); ?>
        <div class="win" id="adminCard">
          <?= winbar() ?>
          <div class="winbody">
            <div class="kinds" role="radiogroup" aria-label="What are you adding?">
              <label><input type="radio" name="adminKind" value="job" checked><span>Job</span></label>
              <label><input type="radio" name="adminKind" value="portfolios"><span>Portfolio</span></label>
              <label><input type="radio" name="adminKind" value="tools"><span>Tools</span></label>
              <label><input type="radio" name="adminKind" value="companies"><span>Company</span></label>
            </div>
            <p id="adminHelp">Paste a job link, or a page that lists many jobs. The AI checks every job against our roles and lists the ones that fit.</p>
            <form id="adminForm" autocomplete="off">
              <input type="url" id="adminUrl" placeholder="https://..." aria-label="Link" required>
              <button type="submit" id="adminBtn">Add job</button>
            </form>
            <p id="adminMsg" class="admin-msg" role="status"></p>
            <ul id="adminLog" class="admin-log"></ul>
          </div>
        </div>
    <?php return ob_get_clean();
}
// The little Mac-window strip on every card: just three dots (decoration only, no title, no x).
function winbar(): string
{
    return '<div class="winbar" aria-hidden="true"><i class="dot r"></i><i class="dot y"></i><i class="dot g"></i></div>';
}

function table_head(bool $dead = false): string
{
    // The Dead Zone holds jobs, portfolios and tools together: Title (for a job: Role Name (Company)), Type, and when it was removed.
    if ($dead) return '<thead><tr><th class="rank"></th><th>Title</th><th class="type">Type</th><th class="posted">Removed On</th></tr></thead>';
    return '<thead><tr><th class="rank"></th><th>Job</th><th class="company">Company &amp; location</th><th class="tags">Tag</th><th class="posted">Posted</th><th class="pin"></th></tr></thead>';
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
      <div class="menu-dots"><?= winbar() ?></div>   <!-- the Mac-window strip on top of the phone menu (hidden on bigger screens) -->
      <!-- The big sections of the site. "Jobs" stays lit on All, Highlighted, India, Abroad and Remote. -->
      <a href="#all" data-top="jobs">Jobs <span class="n"><?= $n['all'] ?></span></a>
      <a href="#portfolios" data-top="portfolios">Portfolios <span class="n"><?= count($portfolios) ?></span></a>
      <a href="#tools" data-top="tools">Tools <span class="n"><?= count($tools) ?></span></a>
      <a href="#companies" data-top="companies">Companies <span class="n"><?= count($companies) ?></span></a>
      <span class="gap"></span>
      <a href="#dead" data-top="dead">Dead Zone <span class="n"><?= count($deadItems) ?></span></a>
      <a href="#log" data-top="log">Update log</a>
      <a href="#about" data-top="about">About</a>
    </nav>
    <div class="sys"><span class="jobsn" data-extra="<?= count($tools) + count($portfolios) + count($companies) ?>"><?= (int)$totalOddities ?> oddities</span><span id="clock"></span><span class="ver" title="Site version: X is a major update, Y a minor one"><?= h($siteVersion) ?></span></div>
    <button type="button" id="menuBtn" class="menubtn" aria-label="Menu" aria-expanded="false" aria-controls="tabs"><span></span><span></span><span></span></button>
  </div>
</header>

<main class="wrap">

  <!-- ===== Jobs, Dead Zone, Portfolios and Tools: one layout, so the Pinned card is beside all of them ===== -->
  <section id="listView">
    <div class="layout">
      <div class="intro win pink" id="intro">
        <?= winbar() ?>
        <div class="winbody">
          <p>Hand-picked creative jobs. No noise.</p>
          <p>A simple list of design and creative jobs from companies around the internet.<br>No accounts. No applications here. No bullshit. Just the job and a link to where you can apply.</p>
        </div>
      </div>

      <!-- Dead Zone intro: takes the welcome card's place (and style) on the Dead Zone tab -->
      <div class="intro win pink" id="deadNote" hidden>
        <?= winbar() ?>
        <div class="winbody">
          <p>Where things go when they stop working.</p>
          <p>Jobs that closed or have not been updated in <?= (int)$settings['stale_days'] ?> days, and portfolios or tools whose websites are gone. Every website is checked again every <?= (int)(require __DIR__ . '/config/directory.php')['recheck_days'] ?> days, and moved here when it no longer works. We keep them for reference, never delete them.</p>
        </div>
      </div>

      <!-- The pills under the welcome card: they switch between the job lists. Same width as the card and the table. -->
      <nav id="pills" aria-label="Job lists">
        <?php foreach ($tabs as [$key, $label]): ?>
          <a class="pg" href="#<?= $key ?>" data-view="<?= $key ?>"<?= ($key === 'highlighted' && !$n['highlighted'] && !$isAdmin) ? ' hidden' : '' ?>><?= h($label) ?> <span class="n">(<?= $n[$key] ?>)</span></a>
        <?php endforeach; ?>
      </nav>

      <!-- Portfolios: welcome card, then the gallery -->
      <div class="intro win pink" id="introP" hidden>
        <?= winbar() ?>
        <div class="winbody">
          <p>Good work deserves to be found.</p>
          <p>A collection of portfolios, personal sites, and creative work from people worth discovering.<br>No follower counts. No popularity contests. Just interesting people doing interesting work.</p>
        </div>
      </div>
      <div class="main" id="mainP" hidden>
        <?php if ($portfolios): ?><div class="gallery"><?php foreach ($portfolios as $it) echo gallery_card($it, 'portfolios'); ?></div>
        <?php else: ?><div class="win"><div class="empty">No portfolios here yet.</div></div><?php endif; ?>
      </div>

      <!-- Tools: welcome card, then the gallery -->
      <div class="intro win pink" id="introT" hidden>
        <?= winbar() ?>
        <div class="winbody">
          <p>Useful things for creative people.</p>
          <p>A collection of tools, experiments, and little internet gems that make creating more interesting.<br>Not another list of the usual suspects. Just things that are actually worth knowing about.</p>
        </div>
      </div>
      <div class="main" id="mainT" hidden>
        <?php if ($tools): ?><div class="gallery"><?php foreach ($tools as $it) echo gallery_card($it, 'tools'); ?></div>
        <?php else: ?><div class="win"><div class="empty">No tools here yet.</div></div><?php endif; ?>
      </div>

      <!-- Companies: welcome card, then a plain list like the jobs -->
      <div class="intro win pink" id="introC" hidden>
        <?= winbar() ?>
        <div class="winbody">
          <p>Companies worth keeping an eye on.</p>
          <p>A hand-picked list of cool companies in tech, design and beyond, each with a short note on what they do.<br>No rankings. No sponsored spots. Just places doing interesting work.</p>
        </div>
      </div>
      <div class="main" id="mainC" hidden>
        <div class="card win">
          <?= winbar() ?>
          <?php if ($companies): ?>
          <table class="jobs companies" id="companyTable"><?= company_head() ?><tbody>
            <?php foreach ($companies as $i => $c) echo company_row($c, $i); ?>
          </tbody></table>
          <?php else: ?><div class="empty">No companies here yet.</div><?php endif; ?>
        </div>
      </div>

      <div class="main" id="jobsMain">
        <div class="card win">
          <?= winbar() ?>
          <table class="jobs" id="liveTable"><?= table_head() ?><tbody>
            <?php foreach ($live as $j) echo job_row($j, false, (int)$settings['stale_days']); ?>
          </tbody></table>
          <table class="jobs dead" id="deadTable" hidden><?= table_head(true) ?><tbody>
            <?php foreach ($deadItems as $d) echo dead_row($d); ?>
          </tbody></table>
          <div class="empty" id="empty" hidden></div>
        </div>
        <nav class="pager" id="pager" aria-label="Pages" hidden></nav>
      </div>

      <div class="side">
        <?php if ($isAdmin): ?>
        <!-- Admin card: only on /admin. Pick what you are adding, paste a link; the AI makes the entry. -->
        <?= admin_card() ?>
        <?php else: ?>
        <!-- Backup: if the server could not tell the address was /admin, the browser adds the card itself (see assets/app.js). Nothing shows on other addresses. -->
        <template id="adminTpl"><?= admin_card() ?></template>
        <?php endif; ?>

        <aside id="pinbox" class="win orange" aria-label="Pinned jobs">
          <?= winbar() ?>
          <div class="winbody">
            <ul id="pinList"></ul>
            <div class="hint" id="pinHint">Tap the &#9734; on any job to pin it here. Pins are saved in this browser only.</div>
          </div>
        </aside>
      </div>
    </div>
  </section>

  <!-- ===== Update log ===== -->
  <section id="logView" class="panel win green" hidden>
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
        <?php foreach (['site', 'added', 'updated', 'closed', 'inactive'] as $type): if (empty($day['types'][$type])) continue; ?>
          <div class="log-line">
            <span class="pill <?= $type ?>"><?= $type === 'site' ? 'SITE UPDATE' : strtoupper($type) ?></span>
            <span class="log-items"><?php foreach ($day['types'][$type] as $i => $j): ?><?= $i ? ', ' : '' ?><?php if (!empty($j['site'])): ?><span><b><?= h($j['label']) ?></b> (<?= h($j['text']) ?>)</span><?php else: ?><a href="<?= h($j['url']) ?>" target="_blank" rel="nofollow noopener"><b><?= h($j['label'] ?? 'Jobs') ?></b>: <?= h($j['text'] ?? ($j['company'] . ' (' . $j['title'] . ')')) ?></a><?php endif; ?><?php endforeach; ?></span>
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
          <p><b>A small collection of oddities for creative people.</b></p>
          <p>I collect jobs, portfolios, tools and cool companies from around the internet and put them here in one place. Every entry takes you straight to the original source.</p>
        </div>
      </div>

      <div class="win orange">
        <?= winbar() ?>
        <div class="winbody">
          <p>Made by <a class="maker" href="https://www.imtiyazahmed.com" target="_blank" rel="noopener"><strong>Imtiyaz Ahmed</strong></a>, a designer &amp; builder who spends too much time trying to make fun and useful stuff for the internet.</p>
          <p>I wanted a small, useful place to find good creative things that are hard to find elsewhere, without all the extra stuff.</p>
        </div>
      </div>

    </div>

    <div class="win">
      <?= winbar() ?>
      <div class="winbody">
        <ol class="steps">
          <li>Hand-picked from around the internet.</li>
          <li>Jobs, portfolios, tools and companies.</li>
          <li>Jobs cover India, abroad and remote.</li>
          <li>Every entry links to the original source.</li>
          <li>Checked often: jobs nightly, sites every 2 weeks.</li>
          <li>Dead things go to the Dead Zone, never deleted.</li>
          <li>Pin what you like. Saved in your browser.</li>
          <li>No accounts, no sign-ups.</li>
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
            <p><b>Total Oddities:</b> <?= (int)$totalOddities ?></p>
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
