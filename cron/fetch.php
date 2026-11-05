<?php
// FINDER: visits every place in config/sources.php and saves creative jobs to the database.
// Run it from the command line:  php cron/fetch.php

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/fetchers.php';

$settings = require __DIR__ . '/../config/settings.php';
$sources  = require __DIR__ . '/../config/sources.php';
$pdo      = db();
$now      = time();

$pattern = '/\b(' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $settings['creative_keywords'])) . ')/i';
// short words like "ui" or "3d" must be whole words, so "build" doesn't match "ui"
$pattern = str_replace(['|ui)', '|ux)'], ['|ui\b)', '|ux\b)'], $pattern);

$exclude = '/(' . implode('|', array_map(fn($k) => preg_quote($k, '/'), $settings['exclude_keywords'])) . ')/i';

$upsert = $pdo->prepare("
    INSERT INTO jobs (source, title, company, location, url, posted_at, first_seen, last_seen, status)
    VALUES (:source, :title, :company, :location, :url, :posted_at, :now, :now, 'open')
    ON CONFLICT(url) DO UPDATE SET
        title = excluded.title, location = excluded.location, last_seen = excluded.last_seen,
        status = 'open', fail_count = 0, closed_at = NULL, closed_reason = NULL
");

foreach ($sources as $s) {
    $label = $s['name'];
    try {
        [$jobs, $isComplete] = fetch_source($s);
    } catch (Throwable $e) {
        // If a source fails we do NOT touch its existing jobs - it may just be a hiccup.
        log_line("SKIPPED $label: " . $e->getMessage());
        continue;
    }

    $seenUrls = [];
    $added = 0;
    foreach ($jobs as $j) {
        if (!$j['title'] || !is_safe_url($j['url'])) continue;
        if (($s['filter'] ?? true) && (!preg_match($pattern, $j['title']) || preg_match($exclude, $j['title']))) continue;

        $seenUrls[] = $j['url'];
        $existed = $pdo->prepare('SELECT 1 FROM jobs WHERE url = ?');
        $existed->execute([$j['url']]);
        $isNew = !$existed->fetchColumn();

        $upsert->execute([
            ':source' => $label, ':title' => mb_substr($j['title'], 0, 300),
            ':company' => $label, ':location' => mb_substr($j['location'] ?? '', 0, 200),
            ':url' => $j['url'], ':posted_at' => $j['posted_at'] ?: $now, ':now' => $now,
        ]);
        if ($isNew) $added++;
    }

    // For boards that give us their full list: anything we had before that is now missing = job is gone.
    $closed = 0;
    if ($isComplete) {
        $stmt = $pdo->prepare("SELECT id, url FROM jobs WHERE source = ? AND status = 'open'");
        $stmt->execute([$label]);
        $close = $pdo->prepare("UPDATE jobs SET status='closed', closed_at=?, closed_reason='no longer listed on the company board' WHERE id=?");
        foreach ($stmt->fetchAll() as $row) {
            if (!in_array($row['url'], $seenUrls, true)) { $close->execute([$now, $row['id']]); $closed++; }
        }
    }

    log_line(sprintf('%-20s found %d creative jobs, %d new, %d closed', $label, count($seenUrls), $added, $closed));
}
