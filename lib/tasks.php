<?php
// The two jobs the site does in the background. They are used both by
//   - the command line scripts (cron/fetch.php, cron/check.php), and
//   - the web addresses on Vercel (api/fetch.php, api/check.php).

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fetchers.php';

// ---------- FINDER ----------
// Visits the places in config/sources.php and saves creative jobs.
// $budget = stop starting new sources after this many seconds (0 = no limit).
function run_fetch(float $budget = 0): void
{
    $settings = require __DIR__ . '/../config/settings.php';
    $sources  = require __DIR__ . '/../config/sources.php';
    $pdo      = db();
    $start    = microtime(true);
    $now      = time();

    $pattern = '/\b(' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $settings['creative_keywords'])) . ')/i';
    // short words like "ui" must be whole words, so "build" doesn't match "ui"
    $pattern = str_replace(['|ui)', '|ux)'], ['|ui\b)', '|ux\b)'], $pattern);
    $exclude = '/(' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $settings['exclude_keywords'])) . ')/i';

    // Visit the sources we haven't visited for the longest time first.
    $last = [];
    foreach ($pdo->query('SELECT source, last_fetched FROM source_state') as $row) $last[$row['source']] = (int)$row['last_fetched'];
    usort($sources, fn($a, $b) => ($last[$a['name']] ?? 0) <=> ($last[$b['name']] ?? 0));

    $exists = $pdo->prepare('SELECT 1 FROM jobs WHERE url = ?');
    $upsert = $pdo->prepare("
        INSERT INTO jobs (source, title, company, location, url, posted_at, first_seen, last_seen, status)
        VALUES (:source, :title, :company, :location, :url, :posted_at, :now1, :now2, 'open')
        ON CONFLICT(url) DO UPDATE SET
            title = excluded.title, location = excluded.location, last_seen = excluded.last_seen,
            status = 'open', fail_count = 0, closed_at = NULL, closed_reason = NULL");
    $markVisited = $pdo->prepare('INSERT INTO source_state (source, last_fetched) VALUES (:s, :t1)
                                  ON CONFLICT(source) DO UPDATE SET last_fetched = excluded.last_fetched');

    $done = 0;
    foreach ($sources as $s) {
        if ($budget && microtime(true) - $start > $budget) {
            log_line('Time is up - the remaining ' . (count($sources) - $done) . ' sources will be done on the next run.');
            break;
        }
        $done++;
        $label = $s['name'];
        $markVisited->execute([':s' => $label, ':t1' => time()]);

        try {
            [$jobs, $isComplete] = fetch_source($s);
        } catch (Throwable $e) {
            // If a source fails we do NOT touch its existing jobs - it may just be a hiccup.
            log_line("SKIPPED $label: " . $e->getMessage());
            continue;
        }

        $seen = [];
        $added = 0;
        foreach ($jobs as $j) {
            if (!$j['title'] || !is_safe_url($j['url'])) continue;
            if (($s['filter'] ?? true) && (!preg_match($pattern, $j['title']) || preg_match($exclude, $j['title']))) continue;

            $seen[] = $j['url'];
            $exists->execute([$j['url']]);
            $isNew = !$exists->fetchColumn();

            $upsert->execute([
                ':source' => $label, ':title' => mb_substr($j['title'], 0, 300),
                ':company' => $label, ':location' => mb_substr($j['location'] ?? '', 0, 200),
                ':url' => $j['url'], ':posted_at' => $j['posted_at'] ?: $now, ':now1' => $now, ':now2' => $now,
            ]);
            if ($isNew) $added++;
        }

        // For boards that give a COMPLETE list: a job we had before that is now missing = job is gone.
        $closed = 0;
        if ($isComplete) {
            $rows = $pdo->prepare("SELECT id, url FROM jobs WHERE source = ? AND status = 'open'");
            $rows->execute([$label]);
            $close = $pdo->prepare("UPDATE jobs SET status='closed', closed_at=?, closed_reason='no longer listed on the company board' WHERE id=?");
            $seenSet = array_flip($seen);
            foreach ($rows->fetchAll() as $row) {
                if (!isset($seenSet[$row['url']])) { $close->execute([$now, $row['id']]); $closed++; }
            }
        }
        log_line(sprintf('%-20s found %d creative jobs, %d new, %d closed', $label, count($seen), $added, $closed));
    }
}

// ---------- TESTER ----------
// Visits job links and marks jobs closed if they are really gone.
// $batch = how many links to visit, $delayMs = pause between visits, $budget = max seconds (0 = no limit).
function run_check(int $batch, int $delayMs, float $budget = 0): void
{
    $settings = require __DIR__ . '/../config/settings.php';
    $pdo      = db();
    $start    = microtime(true);
    $now      = time();

    // Visit the jobs we haven't checked for the longest time first.
    $jobs = $pdo->query("
        SELECT id, url, title, company, fail_count FROM jobs
        WHERE status = 'open'
        ORDER BY COALESCE(last_checked, 0) ASC
        LIMIT " . (int)$batch)->fetchAll();

    $markChecked = $pdo->prepare('UPDATE jobs SET last_checked = ?, fail_count = 0 WHERE id = ?');
    $markFailed  = $pdo->prepare('UPDATE jobs SET last_checked = ?, fail_count = fail_count + 1 WHERE id = ?');
    $markClosed  = $pdo->prepare("UPDATE jobs SET status='closed', closed_at=?, closed_reason=?, last_checked=? WHERE id=?");

    $stats = ['still open' => 0, 'closed' => 0, 'unsure' => 0];
    $visited = 0;

    foreach ($jobs as $job) {
        if ($budget && microtime(true) - $start > $budget) break;
        $visited++;

        $r = http_get($job['url']);
        $reason = null;   // set when we are SURE the job is gone
        $unsure = false;  // set when we can't tell (site down, blocked us, etc.)
        $note   = '';

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
            $unsure = $r['status'] >= 500 || $r['status'] === 429;
            if (!$unsure) { $markChecked->execute([$now, $job['id']]); $stats['still open']++; usleep($delayMs * 1000); continue; }
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
                $markClosed->execute([$now, "unreachable ($note), " . $settings['close_after_fails'] . ' runs in a row', $now, $job['id']]);
                log_line("CLOSED  {$job['company']} - {$job['title']}  [unreachable many times]");
            }
        } else {
            $markChecked->execute([$now, $job['id']]);
            $stats['still open']++;
        }
        usleep($delayMs * 1000);
    }

    // Tidy up: delete very old closed jobs.
    $pdo->prepare("DELETE FROM jobs WHERE status='closed' AND closed_at < ?")
        ->execute([$now - $settings['purge_closed_days'] * 86400]);

    log_line(sprintf('Checked %d jobs: %d still open, %d closed, %d unsure', $visited, $stats['still open'], $stats['closed'], $stats['unsure']));
}
