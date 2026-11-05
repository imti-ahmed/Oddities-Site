<?php
// The "Collect jobs" button, as a command. Used by the scheduled GitHub job (.github/workflows/collect.yml)
// and handy on your own computer. It needs DATABASE_URL and GROQ_API_KEY in the environment.
//
//   php scripts/collect.php                 read the company boards and job sites, then check up to 150 jobs with the AI
//   php scripts/collect.php --max=300       check more (or fewer) jobs in this run
//   php scripts/collect.php --gather        read the boards again even if jobs are still waiting from an earlier run
//   php scripts/collect.php --dry-run       only read the boards and show how many jobs would be checked
//   php scripts/collect.php --fresh --yes   FIRST delete every listed job and the queue (the board starts empty)
//
// If Groq's limits are reached the run stops by itself. Nothing is lost: the next run continues from the same place.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
ini_set('memory_limit', '512M');
set_time_limit(0);
require __DIR__ . '/../lib/collector.php';

$opt = getopt('', ['max::', 'gather', 'dry-run', 'fresh', 'yes']);
$max = isset($opt['max']) ? max(1, (int)$opt['max']) : 150;
function say(string $m): void { echo '[' . date('H:i:s') . '] ' . $m . "\n"; }

if (jobs_store_mode() !== 'db') { say('DATABASE_URL is not set. Nothing to do.'); exit(1); }

if (isset($opt['fresh'])) {
    if (!isset($opt['yes'])) { say('--fresh deletes ALL listed jobs and the queue. Add --yes to confirm.'); exit(1); }
    collector_ready();
    db()->exec('TRUNCATE TABLE jobs RESTART IDENTITY');
    db()->exec('TRUNCATE TABLE job_queue');
    say('Deleted every job and emptied the queue. The board is empty.');
}

$s = collector_stats();
say("Start: {$s['jobs']} jobs listed, {$s['pending']} waiting in the queue.");

if ($s['pending'] === 0 || isset($opt['gather']) || isset($opt['dry-run'])) {
    $queued = 0;
    for ($from = 0, $total = count(collector_companies()); $from < $total; ) {
        $g = collector_gather_companies($from);
        $queued += $g['queued']; $from = $g['next'];
        foreach ($g['errors'] as $name => $why) say("  $name: $why");
        say("Company job boards: $from of $total, $queued jobs to check so far.");
        if ($g['finished']) break;
    }
    $n = count(collector_sources());
    for ($i = 0; $i < $n; $i++) {
        $r = collector_gather_source($i);
        if (($r['status'] ?? '') === 'wait') { say($r['message'] . ' Waiting ' . (int)$r['seconds'] . 's.'); sleep(min(120, (int)$r['seconds'])); $i--; continue; }
        say($r['message']); $queued += $r['queued'] ?? 0;
    }
    say("Gathered. $queued new jobs queued.");
}
if (isset($opt['dry-run'])) { $s = collector_stats(); say("Dry run: {$s['pending']} jobs are waiting to be checked. Nothing was sent to the AI."); exit(0); }

$checked = 0; $added = 0; $refused = 0; $failed = 0;
while ($checked < $max) {
    $r = collector_next();
    if ($r['status'] === 'empty') { say('The queue is empty: everything has been checked.'); break; }
    if ($r['status'] === 'wait') { say('Groq asked us to slow down. Waiting ' . (int)$r['seconds'] . ' seconds.'); sleep((int)$r['seconds']); continue; }
    if ($r['status'] === 'stop') { say('Stopping: ' . $r['message']); break; }
    $checked++;
    if ($r['result'] === 'added') { $added++; say("  + {$r['company']} | {$r['title']}"); }
    elseif ($r['result'] === 'error') $failed++;
    else $refused++;
    if ($checked % 25 === 0) say("  ... $checked checked: $added added, $refused not added, {$r['remaining']} waiting.");
}
$s = collector_stats();
say("Finished. Checked $checked: $added added, $refused not added, $failed could not be checked. {$s['jobs']} jobs listed, {$s['pending']} still waiting.");
