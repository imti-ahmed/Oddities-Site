<?php
// Reads data/jobs.json and works out everything the page needs for each job:
// which tab it belongs to, its tags, whether it is NEW / UPDATED, and whether it is in the Dead zone.

require_once __DIR__ . '/helpers.php';

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

// Simple tags for a row. Uses the job's own "tags" list if it has one, otherwise guesses from the title.
// Each tag is [name, kind] where kind is role / level / mode (kinds only change the colour).
function tags_of(array $j): array
{
    if (!empty($j['tags']) && is_array($j['tags'])) {
        return array_map(fn($t) => [(string)$t, 'role'], $j['tags']);
    }
    $t = strtolower($j['title'] ?? '');
    $tags = [];

    $roles = [   // checked in this order, the first two that match are used
        'Product Design' => '/product design|\bux\b|\bui\b|interaction|design system|design engineer|experience design/',
        'Brand'          => '/\bbrand/',
        'Graphic'        => '/graphic|visual|communication design/',
        'Motion'         => '/motion|animat/',
        'Video'          => '/video|film|producer/',
        'Illustration'   => '/illustrat/',
        'Content'        => '/content|copywrit|writer|editorial|\beditor\b/',
        'Research'       => '/research/',
        'Learning'       => '/instructional|learning/',
        'Creative'       => '/creative/',
    ];
    foreach ($roles as $name => $re) {
        if (preg_match($re, $t)) $tags[] = [$name, 'role'];
        if (count($tags) === 2) break;
    }
    if (!$tags && str_contains($t, 'design')) $tags[] = ['Design', 'role'];

    if (preg_match('/\bintern(ship)?\b/', $t))                                   $tags[] = ['Intern', 'level'];
    elseif (preg_match('/\b(manager|director|head|vp|vice president)\b/', $t))   $tags[] = ['Leadership', 'level'];
    elseif (preg_match('/\b(senior|sr\.?|staff|principal|lead)\b/', $t))         $tags[] = ['Senior', 'level'];
    elseif (preg_match('/\b(junior|associate|early career|graduate)\b/', $t))    $tags[] = ['Junior', 'level'];

    $mode = mode_of($j);
    if ($mode !== 'in-office') $tags[] = [ucfirst($mode), 'mode'];
    return $tags;
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

        $jobs[] = [
            'id' => substr(sha1($j['url']), 0, 10), 'title' => $j['title'], 'company' => $j['company'],
            'location' => $j['location'] ?? '', 'url' => $j['url'],
            'region' => region_of($j), 'mode' => mode_of($j), 'tags' => tags_of($j),
            'posted' => $posted, 'added' => $added, 'updated' => $updated, 'closed' => $closed,
            'dead' => $dead, 'dead_note' => (string)($j['closed_reason'] ?? ''), 'badge' => $badge,
            'change' => max($added, $updated),
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

// Dead zone: closed ones first (most recently closed first), then inactive ones (newest first).
function sort_dead(array $jobs): array
{
    usort($jobs, function ($a, $b) {
        if ($a['dead'] !== $b['dead']) return $a['dead'] === 'closed' ? -1 : 1;
        return $a['dead'] === 'closed' ? $b['closed'] <=> $a['closed'] : $b['posted'] <=> $a['posted'];
    });
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
            $when = max($j['posted'], $j['updated']) + $settings['stale_days'] * 86400;
            if ($when <= $now) $events[] = ['ts' => $when, 'type' => 'inactive', 'job' => $j];
        }
    }
    usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return array_slice($events, 0, $limit);
}
