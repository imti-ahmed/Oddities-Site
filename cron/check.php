<?php
// TESTER: visits the link of each open job and makes sure the job is really still there.
// If the page says "not found" / "no longer available", the job is marked closed and disappears from the site.
// Run it from the command line:  php cron/check.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

$settings = require __DIR__ . '/../config/settings.php';
$pdo      = db();
$now      = time();

// Visit the jobs we haven't checked for the longest time first.
$jobs = $pdo->query("
    SELECT id, url, title, company, fail_count FROM jobs
    WHERE status = 'open'
    ORDER BY COALESCE(last_checked, 0) ASC
    LIMIT " . (int)$settings['check_batch_size'])->fetchAll();

$markChecked = $pdo->prepare('UPDATE jobs SET last_checked = ?, fail_count = 0 WHERE id = ?');
$markFailed  = $pdo->prepare('UPDATE jobs SET last_checked = ?, fail_count = fail_count + 1 WHERE id = ?');
$markClosed  = $pdo->prepare("UPDATE jobs SET status='closed', closed_at=?, closed_reason=?, last_checked=? WHERE id=?");

$stats = ['still open' => 0, 'closed' => 0, 'unsure' => 0];

foreach ($jobs as $job) {
    $r = http_get($job['url']);
    $reason = null;   // set when we are SURE the job is gone
    $unsure = false;  // set when we can't tell (site down, blocked us, etc.)

    if ($r['error']) {
        $unsure = true;
        $note = 'could not connect';
    } elseif (in_array($r['status'], [404, 410], true)) {
        $reason = 'page not found (' . $r['status'] . ')';
    } elseif ($r['status'] === 200) {
        $final = strtolower($r['final_url']);
        $body  = strtolower(strip_tags($r['body']));
        $jobId = job_id_in_url($job['url']);
        if (preg_match('/[?&]error=true/', $final)) {
            $reason = 'redirected to the job board with an error';   // how Greenhouse handles closed jobs
        } elseif ($jobId && stripos($r['final_url'], $jobId) === false) {
            $reason = 'link now redirects away from the job (' . domain_of($r['final_url']) . ')';
        } else {
            foreach ($settings['closed_phrases'] as $phrase) {
                if (str_contains($body, $phrase)) { $reason = 'page says: "' . $phrase . '"'; break; }
            }
        }
    } else {
        // 403 / 429 / 5xx etc: the site may be blocking robots or having a bad day. Don't punish the job.
        $note = 'HTTP ' . $r['status'];
        $unsure = $r['status'] >= 500 || $r['status'] === 429 ? true : false;
        if (!$unsure) { $markChecked->execute([$now, $job['id']]); $stats['still open']++; usleep($settings['check_delay_ms'] * 1000); continue; }
    }

    if ($reason) {
        $markClosed->execute([$now, $reason, $now, $job['id']]);
        $stats['closed']++;
        log_line("CLOSED  {$job['company']} - {$job['title']}  [$reason]");
    } elseif ($unsure) {
        $markFailed->execute([$now, $job['id']]);
        $stats['unsure']++;
        log_line("UNSURE  {$job['company']} - {$job['title']}  [$note]");
        if ($job['fail_count'] + 1 >= $settings['close_after_fails']) {
            $markClosed->execute([$now, "unreachable $note, " . $settings['close_after_fails'] . ' runs in a row', $now, $job['id']]);
            log_line("CLOSED  {$job['company']} - {$job['title']}  [unreachable many times]");
        }
    } else {
        $markChecked->execute([$now, $job['id']]);
        $stats['still open']++;
    }
    usleep($settings['check_delay_ms'] * 1000);
}

// Tidy up: delete very old closed jobs.
$pdo->prepare("DELETE FROM jobs WHERE status='closed' AND closed_at < ?")
    ->execute([$now - $settings['purge_closed_days'] * 86400]);

log_line(sprintf('Checked %d jobs: %d still open, %d closed, %d unsure', count($jobs), $stats['still open'], $stats['closed'], $stats['unsure']));
