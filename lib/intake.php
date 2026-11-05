<?php
// ONE job link in, one decision out. Used by the /admin card now, and by the automatic collector (cron) later.
//
//   link  ->  read the page  ->  ask Groq to check it against our rules  ->  double-check the answer in code  ->  save
//
// Returns ['status' => 'added' | 'rejected' | 'duplicate' | 'error', 'message' => 'plain sentence', 'job' => [...optional]]

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/pagefetch.php';
require_once __DIR__ . '/groq.php';
require_once __DIR__ . '/store.php';

// Is this job (or the same job under another link) already in the list?
function job_already_listed(array $list, array $job): bool
{
    $url = normalize_job_url($job['url']);
    foreach ($list as $j) {
        if (normalize_job_url((string)($j['url'] ?? '')) === $url) return true;
        if (mb_strtolower(trim((string)($j['title'] ?? ''))) === mb_strtolower($job['title']) && mb_strtolower(trim((string)($j['company'] ?? ''))) === mb_strtolower($job['company'])
            && format_location((string)($j['location'] ?? '')) === $job['location']) return true;
    }
    return false;
}

function intake_job(string $url, string $source = 'admin'): array
{
    $roles    = require __DIR__ . '/../config/roles.php';
    $settings = require __DIR__ . '/../config/settings.php';
    $strict   = env('VERCEL') !== '';                       // on Vercel, refuse private/internal addresses; on your own computer you may test with local pages

    $url = trim($url);
    [$ok, $why] = check_public_url($url, $strict);
    if (!$ok) return ['status' => 'error', 'message' => $why];

    // 1. already listed? (cheap check first, before spending any AI time)
    try { $existing = jobs_read_fresh(); } catch (Throwable $e) { return ['status' => 'error', 'message' => $e->getMessage()]; }
    if (job_already_listed($existing, ['url' => $url, 'title' => "\0", 'company' => "\0", 'location' => "\0"])) return ['status' => 'duplicate', 'message' => 'That link is already in the list.'];

    // 2. read the page
    $page = read_job_page($url, (int)$roles['rules']['max_page_chars'], $strict);
    if (!$page['ok']) return ['status' => 'error', 'message' => $page['error']];
    if (mb_strlen(trim($page['text'])) < 120 && !$page['structured']) return ['status' => 'rejected', 'message' => 'Not enough readable text on that page. Some sites only show the job after JavaScript runs; try the link of the same job on the company\'s own careers page.'];

    // 3. ask the AI
    $ask = groq_ask($page, $url, $source, $roles);
    if (!$ask['ok']) return ['status' => 'error', 'message' => $ask['error']];

    // 4. double-check the AI's answer
    $check = validate_answer($ask['answer'], $roles, $page['text'] . "\n" . json_encode($page['structured'], JSON_UNESCAPED_UNICODE), $url, $settings);
    if (!$check['ok']) return ['status' => 'rejected', 'message' => 'Not added: ' . rtrim($check['reason'], '.') . '.', 'notes' => $check['notes']];
    $job = $check['job'];
    $job['source'] = $source;

    // 5. same job under a different link?
    if (job_already_listed($existing, $job)) return ['status' => 'duplicate', 'message' => 'That job (same title, company and place) is already in the list.'];

    // 6. save
    try {
        $result = jobs_append($job, fn($list) => job_already_listed($list, $job), 'Add job: ' . $job['company'] . ', ' . $job['title'] . ' (via ' . $source . ')');
    } catch (Throwable $e) { return ['status' => 'error', 'message' => $e->getMessage()]; }
    if ($result === 'duplicate') return ['status' => 'duplicate', 'message' => 'That job is already in the list.'];

    $bits = [$job['tags'][0], $job['region'] === 'india' ? 'India' : 'Abroad'];
    if (!empty($job['mode'])) $bits[] = ucfirst($job['mode']);
    return ['status' => 'added', 'message' => 'Added: ' . $job['title'] . ' at ' . $job['company'] . ' (' . implode(', ', $bits) . ').' . (jobs_store_mode() === 'db' ? ' It is saved. The site shows it within a minute.' : ''),
            'job' => $job, 'notes' => $check['notes'], 'confidence' => $check['confidence']];
}
