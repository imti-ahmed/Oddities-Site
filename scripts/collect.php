<?php
// The "Collect jobs" button, as a command. Used by the scheduled GitHub job (.github/workflows/collect.yml)
// and handy on your own computer. It needs DATABASE_URL and GROQ_API_KEY in the environment.
//
//   php scripts/collect.php                 read the company boards and job sites, then settle up to 400 new jobs (the AI is asked about 8 jobs per call)
//   php scripts/collect.php --max=800       settle more (or fewer) jobs in this run
//   php scripts/collect.php --gather        read the boards again even if jobs are still waiting from an earlier run
//   php scripts/collect.php --dry-run       only read the boards and show how many jobs would be checked
//   php scripts/collect.php --fresh --yes   FIRST delete every listed job and the queue (the board starts empty)
//   php scripts/collect.php --prune --yes   FIRST hide every job (and drop every queued job) that came from a company or site that is no longer on our lists
//
// If Groq's limits are reached the run stops by itself. Nothing is lost: the next run continues from the same place.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
ini_set('memory_limit', '512M');
set_time_limit(0);
require __DIR__ . '/../lib/collector.php';

$opt = getopt('', ['max::', 'gather', 'dry-run', 'fresh', 'yes', 'prune']);
$max = isset($opt['max']) ? max(1, (int)$opt['max']) : 400;
function say(string $m): void { echo '[' . date('H:i:s') . '] ' . $m . "\n"; }

if (jobs_store_mode() !== 'db') { say('DATABASE_URL is not set. Nothing to do.'); exit(1); }

if (isset($opt['fresh'])) {
    if (!isset($opt['yes'])) { say('--fresh deletes ALL listed jobs and the queue. Add --yes to confirm.'); exit(1); }
    collector_ready();
    db()->exec('TRUNCATE TABLE jobs RESTART IDENTITY');
    db()->exec('TRUNCATE TABLE job_queue');
    say('Deleted every job and emptied the queue. The board is empty.');
}

if (isset($opt['prune'])) {
    if (!isset($opt['yes'])) { say('--prune hides every job that came from a company or job site no longer on our lists. Add --yes to confirm.'); exit(1); }
    $p = collector_prune();
    say("Cleaned up after the list change: {$p['jobs']} listed jobs hidden, {$p['queue']} waiting jobs dropped from the queue. (Jobs added by hand are untouched.)");
}

$s = collector_stats();
say("Start: {$s['jobs']} jobs listed, {$s['pending']} waiting in the queue.");

if (true) {      // every run reads the boards again: it costs no AI, and jobs already known or queued are skipped, so it is safe to repeat
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

$checked = 0; $added = 0; $refused = 0; $failed = 0; $aiCalls = 0; $noAi = 0; $remaining = 0;
while ($checked < $max) {
    $r = collector_next();
    if ($r['status'] === 'empty') { say('The queue is empty: everything has been checked.'); break; }
    $aiCalls += $r['ai_calls'] ?? 0;
    foreach ($r['results'] ?? [] as $x) {
        $checked++;
        if ($x['result'] === 'added') { $added++; if (!$x['ai']) $noAi++; say('  + ' . ($x['company'] !== '' ? "{$x['company']} | {$x['title']}" : preg_replace('/ \(.*$/', '', $x['message'])) . ($x['ai'] ? '' : '  (no AI needed)')); }
        elseif ($x['result'] === 'error') $failed++;
        else $refused++;
    }
    $remaining = $r['remaining'] ?? $remaining;
    if ($r['status'] === 'wait') { say('Groq asked us to slow down. Waiting ' . (int)$r['seconds'] . ' seconds.'); sleep((int)$r['seconds']); continue; }
    if ($r['status'] === 'stop') { say('Stopping: ' . $r['message']); break; }
    if ($checked && $checked % 40 < 8) say("  ... $checked settled: $added added, $refused not added, $remaining waiting, $aiCalls AI calls so far.");
}
$s = collector_stats();
say("Finished. Settled $checked jobs: $added added ($noAi of them without the AI), $refused not added, $failed could not be checked. AI calls used: $aiCalls. {$s['jobs']} jobs listed, {$s['pending']} still waiting.");
if (!empty($GLOBALS['groq_last_model'])) say('AI model used: ' . $GLOBALS['groq_last_model'] . (isset($GLOBALS['groq_batch_cap']) ? ' (your Groq limit only fits ' . $GLOBALS['groq_batch_cap'] . ' jobs per call, so groups were made smaller)' : ''));
