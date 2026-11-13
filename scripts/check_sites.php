<?php
// "Is every listed Portfolio and Tool website still working?" Run every day by .github/workflows/check-sites.yml, but each site is only
// asked about every 2 weeks (see config/directory.php). NO AI is used: only web requests. Needs DATABASE_URL in the environment.
//
//   php scripts/check_sites.php
//
// A site moves to the Dead Zone only when it is gone twice in a row. Blocked or slow sites change nothing.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
set_time_limit(0);
require __DIR__ . '/../lib/site_check.php';
function say(string $m): void { echo '[' . date('H:i:s') . '] ' . $m . "\n"; }

if (!db_enabled()) { say('DATABASE_URL is not set. Nothing to do.'); exit(1); }
db_create_directory_tables();
say(sites_remaining() . ' sites are due for a check.');
$tot = ['checked' => 0, 'alive' => 0, 'gone_signal' => 0, 'unknown' => 0, 'dead' => 0];
do {
    $r = sites_check_batch(40);
    foreach ($tot as $k => $_) $tot[$k] += $r[$k];
    foreach ($r['dead_sites'] as $d) say("  dead: {$d['name']} ({$d['kind']}): {$d['reason']}");
    say("  ... {$tot['checked']} checked, {$r['remaining']} to go.");
} while ($r['checked'] > 0 && $r['remaining'] > 0);
say("Finished. {$tot['checked']} checked: {$tot['alive']} working, {$tot['gone_signal']} gone once (moved to the Dead Zone if gone again tomorrow), {$tot['dead']} moved to the Dead Zone now, {$tot['unknown']} could not be reached.");
