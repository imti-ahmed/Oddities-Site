<?php
// Reads data/jobs.json and works out everything the page needs for each job:
// which tab it belongs to, its tags, whether it is NEW / UPDATED, and whether it is in the Dead zone.

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/location.php';

// Remote / Hybrid / In-office. Uses the job's "mode" if it has one, otherwise guesses from the text.
function mode_of(array $j): string
{
    $m = strtolower((string)($j['mode'] ?? ''));
    if (in_array($m, ['remote', 'hybrid', 'in-office'], true)) return $m;
    $text = ($j['location'] ?? '') . ' ' . ($j['title'] ?? '');
    if (preg_match('/\bhybrid\b/i', $text)) return 'hybrid';
    if (preg_match('/\bremote\b/i', $text)) return 'remote';
    return 'in-office';
}

// ONE tag per job: the kind of job. Uses the job's own "tags" (first one) if it has them, otherwise guesses from the title.
// Returned as a list with a single [name, kind] pair so the page code stays simple.
function tags_of(array $j): array
{
    if (!empty($j['tags']) && is_array($j['tags'])) return [[no_em_dash((string)$j['tags'][0]), 'role']];

    $t = strtolower($j['title'] ?? '');
    $kinds = [   // checked in this order, the first match wins
        'Research'       => '/research/',
        'Learning'       => '/instructional|learning/',
        'Motion'         => '/motion|animat/',
        'Video'          => '/video|film|producer/',
        'Illustration'   => '/illustrat/',
        'Brand'          => '/\bbrand/',
        'Graphic'        => '/graphic|visual|communication design/',
        'Content'        => '/content|copywrit|writer|editorial|\beditor\b/',
        'Product Design' => '/product design|\bux\b|\bui\b|interaction|design system|design engineer|experience design/',
        'Creative'       => '/creative/',
    ];
    foreach ($kinds as $name => $re) if (preg_match($re, $t)) return [[$name, 'role']];
    return [[str_contains($t, 'design') ? 'Design' : 'Other', 'role']];
}

// The site never shows em dashes. If a job's text has one (from a job board, or typed by hand), it becomes a plain hyphen.
// (\x{2014} is the em dash character, written as a code so it does not appear in this file.)
function no_em_dash(string $s): string
{
    return preg_replace('/\s*\x{2014}\s*/u', ' - ', $s) ?? $s;
}

// Loads every job from the file and adds the extra information.
function load_jobs(array $settings): array
{
    $raw = json_decode((string)@file_get_contents(__DIR__ . '/../data/jobs.json'), true);
    $now      = time();
    $newCut   = $now - $settings['new_days'] * 86400;
    $staleCut = $now - $settings['stale_days'] * 86400;
    $jobs = [];

    foreach (is_array($raw) ? $raw : [] as $j) {
        if (empty($j['title']) || empty($j['company']) || !is_safe_url($j['url'] ?? '')) continue;

        $posted  = strtotime($j['posted_at'] ?? '') ?: 0;
        $added   = strtotime($j['added_at'] ?? '') ?: $posted;     // if no "added_at", we treat the posted date as when it was added
        $updated = strtotime($j['updated_at'] ?? '') ?: 0;
        $closed  = strtotime($j['closed_at'] ?? '') ?: 0;
        $fresh   = max($posted, $updated);

        $dead = null;      // 'closed' or 'inactive' when the job belongs in the Dead zone
        if (strtolower((string)($j['status'] ?? 'open')) === 'closed') $dead = 'closed';
        elseif ($fresh && $fresh < $staleCut)                           $dead = 'inactive';

        $badge = null;     // 'new' or 'updated' for the glowing badge
        if (!$dead) {
            if ($added >= $newCut)        $badge = 'new';
            elseif ($updated >= $newCut)  $badge = 'updated';
        }
        if (empty($settings['show_badges'])) $badge = null;   // stickers are switched off in config/settings.php: no sticker, and no jumping to the top

        $jobs[] = [
            'id' => substr(sha1($j['url']), 0, 10), 'title' => no_em_dash($j['title']), 'company' => no_em_dash($j['company']),
            'location' => no_em_dash(format_location((string)($j['location'] ?? ''))), 'url' => $j['url'],   // always "City, Country"
            'region' => region_of($j), 'mode' => mode_of($j), 'tags' => tags_of($j),
            'posted' => $posted, 'added' => $added, 'updated' => $updated, 'closed' => $closed,
            'dead' => $dead, 'dead_note' => no_em_dash((string)($j['closed_reason'] ?? '')), 'badge' => $badge,
            'change' => max($added, $updated),
            // The date it left the live list: the closed date, or for inactive jobs the day the "inactive" rule moved it to the Dead zone.
            'removed' => $dead === 'closed' ? ($closed ?: max($added, $updated)) : ($dead === 'inactive' ? $fresh + $settings['stale_days'] * 86400 : 0),
        ];
    }
    return $jobs;
}

// Live jobs: glowing NEW/UPDATED ones first (most recent change first), then everything else newest first.
function sort_live(array $jobs): array
{
    usort($jobs, function ($a, $b) {
        if ((bool)$a['badge'] !== (bool)$b['badge']) return $a['badge'] ? -1 : 1;
        return $a['badge'] ? $b['change'] <=> $a['change'] : $b['posted'] <=> $a['posted'];
    });
    return $jobs;
}

// Dead zone: most recently removed first (closed and inactive jobs mixed together, by the date they left the live list).
function sort_dead(array $jobs): array
{
    usort($jobs, fn($a, $b) => $b['removed'] <=> $a['removed']);   // most recently removed first
    return $jobs;
}

// The update log: one line for each time something was added, updated, closed or went inactive. Newest first.
function build_log(array $jobs, array $settings, int $limit = 200): array
{
    $now = time();
    $events = [];
    foreach ($jobs as $j) {
        if ($j['added'])   $events[] = ['ts' => $j['added'], 'type' => 'added', 'job' => $j];
        if ($j['updated'] && $j['updated'] > $j['added']) $events[] = ['ts' => $j['updated'], 'type' => 'updated', 'job' => $j];
        if ($j['dead'] === 'closed' && $j['closed']) $events[] = ['ts' => $j['closed'], 'type' => 'closed', 'job' => $j];
        if ($j['dead'] === 'inactive') {
            $when = $j['removed'];
            if ($when <= $now) $events[] = ['ts' => $when, 'type' => 'inactive', 'job' => $j];
        }
    }
    usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return array_slice($events, 0, $limit);
}
