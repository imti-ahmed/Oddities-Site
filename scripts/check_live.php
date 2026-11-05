<?php
// "Is every listed job still open?" Run every night by .github/workflows/check-live.yml. NO AI is used: only web requests.
// Needs DATABASE_URL in the environment.
//
//   php scripts/check_live.php
//
// A job is closed (and moves to the Dead Zone) only when it is gone two nights in a row. Blocked or slow pages change nothing.
// Jobs older than the Dead Zone limit (90 days) are not checked: they move to the Dead Zone by age.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
ini_set('memory_limit', '512M');
set_time_limit(0);
require __DIR__ . '/../lib/liveness.php';
function say(string $m): void { echo '[' . date('H:i:s') . '] ' . $m . "\n"; }

if (jobs_store_mode() !== 'db') { say('DATABASE_URL is not set. Nothing to do.'); exit(1); }
liveness_ready();
$due = liveness_remaining();
say("$due jobs are due for a check.");
$tot = ['checked' => 0, 'alive' => 0, 'gone_signal' => 0, 'unknown' => 0, 'closed' => 0];
do {
    $r = liveness_batch(40);
    foreach ($tot as $k => $_) $tot[$k] += $r[$k];
    foreach ($r['closed_jobs'] as $c) say("  closed: {$c['title']} at {$c['company']} ({$c['reason']})");
    say("  ... {$tot['checked']} checked, {$r['remaining']} to go.");
} while ($r['checked'] > 0 && $r['remaining'] > 0);
say("Finished. {$tot['checked']} checked: {$tot['alive']} still open, {$tot['gone_signal']} gone once (closed if gone again tomorrow), {$tot['closed']} closed now, {$tot['unknown']} could not be reached.");
