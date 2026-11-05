<?php
// ONE job link in, one decision out. (A page that lists many jobs gives back the links to check one by one, see intake_list.) Used by the /admin card now, and by the automatic collector (cron) later.
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

function intake_job(string $url, string $source = 'admin', bool $allowList = true): array
{
    $roles    = require __DIR__ . '/../config/roles.php';
    $settings = require __DIR__ . '/../config/settings.php';
    $strict   = env('VERCEL') !== '';                       // on Vercel, refuse private/internal addresses; on your own computer you may test with local pages

    $url = trim($url);
    [$ok, $why] = check_public_url($url, $strict);
    if (!$ok) return ['status' => 'error', 'message' => $why];

    // 1. already listed? (cheap check first, before spending any AI time)
    try { $existing = jobs_read_fresh(); } catch (Throwable $e) { return ['status' => 'error', 'service_error' => true, 'message' => $e->getMessage()]; }
    if (job_already_listed($existing, ['url' => $url, 'title' => "\0", 'company' => "\0", 'location' => "\0"])) return ['status' => 'duplicate', 'message' => 'That link is already in the list.'];

    // 2. read the page
    $page = read_job_page($url, (int)$roles['rules']['max_page_chars'], $strict);
    if (!$page['ok']) return ['status' => 'error', 'message' => $page['error']];
    if (mb_strlen(trim($page['text'])) < 120 && !$page['structured']) {
        if ($allowList) { $list = intake_list($page, $existing, $roles); if ($list['status'] !== 'rejected') return $list; }   // maybe it is a page of links to jobs
        return ['status' => 'rejected', 'message' => 'Not enough readable text on that page. Some sites only show the job after JavaScript runs; try the link of the same job on the company\'s own careers page.'];
    }
    return intake_decide($page, $url, $source, $existing, $roles, $settings, $allowList);
}

// Steps 3 to 6 for a page we already hold: ask the AI, double-check its answer, check for duplicates, save.
// $hints (optional) = facts already known from the job board itself, e.g. ['posted_at' => '2026-11-03'].
// Errors that come from a service (Groq, the database) carry 'service_error' => true, so a long run can pause or stop instead of marking jobs as failed.
function intake_decide(array $page, string $url, string $source, array $existing, array $roles, array $settings, bool $allowList = true, array $hints = []): array
{
    // 3. ask the AI
    $ask = groq_ask($page, $url, $source, $roles);
    if (!$ask['ok']) return ['status' => 'error', 'service_error' => empty($ask['soft']), 'message' => $ask['error'], 'rate_limited' => !empty($ask['rate_limited']),
                             'retry_after' => $ask['retry_after'] ?? null, 'daily_limit' => !empty($ask['daily_limit'])];

    // 3b. a page that lists many jobs: find the job links on it (each one is then checked on its own)
    // (Also when the AI is unsure what kind of page it is: a careers page with little text but links to job pages is a list too.)
    if ($allowList && ($ask['answer']['accept'] ?? false) !== true && ($ask['answer']['page_type'] ?? '') !== 'single_job') {
        $isList = ($ask['answer']['page_type'] ?? '') === 'job_list';
        $list = intake_list($page, $existing, $roles);
        if ($list['status'] !== 'rejected' || $isList) return $list;   // otherwise fall through to the AI's own reason below
    }

    return intake_apply_answer($ask['answer'], $page, $url, $source, $existing, $roles, $settings, $hints);
}

// The plain sentence shown after a job was added.
function intake_added_message(array $job): string
{
    $bits = [$job['tags'][0], $job['region'] === 'india' ? 'India' : 'Abroad'];
    if (!empty($job['mode'])) $bits[] = ucfirst($job['mode']);
    return 'Added: ' . $job['title'] . ' at ' . $job['company'] . ' (' . implode(', ', $bits) . ').' . (jobs_store_mode() === 'db' ? ' It is saved. The site shows it within a minute.' : '');
}

// Steps 4 to 6: double-check the AI's answer, look for duplicates, save. $existing is updated, so jobs of the same batch see each other.
function intake_apply_answer(array $answer, array $page, string $url, string $source, array &$existing, array $roles, array $settings, array $hints = []): array
{
    if (empty($answer['posted_at']) && !empty($hints['posted_at'])) $answer['posted_at'] = $hints['posted_at'];

    // 4. double-check the AI's answer
    $check = validate_answer($answer, $roles, $page['text'] . "\n" . json_encode($page['structured'], JSON_UNESCAPED_UNICODE), $url, $settings);
    if (!$check['ok']) return ['status' => 'rejected', 'message' => 'Not added: ' . rtrim($check['reason'], '.') . '.', 'notes' => $check['notes']];
    $job = $check['job'];
    $job['source'] = $source;
    return intake_save($job, $existing, $check['notes'] ?? [], $check['confidence'] ?? null);
}

// Steps 5 and 6: same job under a different link? Then save.
function intake_save(array $job, array &$existing, array $notes = [], $confidence = null): array
{
    if (job_already_listed($existing, $job)) return ['status' => 'duplicate', 'message' => 'That job (same title, company and place) is already in the list.'];
    try {
        $result = jobs_append($job, fn($list) => job_already_listed($list, $job), 'Add job: ' . $job['company'] . ', ' . $job['title'] . ' (via ' . $job['source'] . ')');
    } catch (Throwable $e) { return ['status' => 'error', 'service_error' => true, 'message' => $e->getMessage()]; }
    if ($result === 'duplicate') return ['status' => 'duplicate', 'message' => 'That job is already in the list.'];
    $existing[] = $job;
    return ['status' => 'added', 'message' => intake_added_message($job), 'job' => $job, 'notes' => $notes, 'confidence' => $confidence];
}

// ---------- fewer AI calls ----------
// Same job (title + company + place) already listed? Uses what the company board gave us, so no AI time is spent on it.
function job_pre_duplicate(array $existing, array $c): bool
{
    $probe = ['url' => (string)$c['url'], 'title' => trim((string)$c['title']), 'company' => trim((string)$c['company']), 'location' => format_location((string)($c['location'] ?? ''))];
    foreach ($existing as $j) {
        if (mb_strtolower(trim((string)($j['title'] ?? ''))) === mb_strtolower($probe['title']) && mb_strtolower(trim((string)($j['company'] ?? ''))) === mb_strtolower($probe['company'])
            && format_location((string)($j['location'] ?? '')) === $probe['location']) return true;
    }
    return false;
}

// A job whose title is plainly one of our roles and whose board gives the place needs no AI. Returns the job, or null when the AI should look.
// Switch it off with 'fast_path' => false in config/roles.php.
function fast_path_job(array $c, array $roles, array $settings): ?array
{
    if (empty($roles['rules']['fast_path'])) return null;
    $title = trim((string)($c['title'] ?? ''));
    if ($title === '' || preg_match('/\b(intern|internship|trainee|apprentice)\b/i', $title)) return null;
    if (mb_strlen((string)($c['description'] ?? '')) < 250 || trim((string)($c['location'] ?? '')) === '') return null;

    // "Senior Product Designer, Payments (Contract)" -> "product designer"
    $base = mb_strtolower($title);
    $base = trim(preg_split('/\s+[-\x{2013}|]\s+|,\s*/u', preg_replace('/\s*[\(\[].*$/u', '', $base))[0]);
    while (preg_match('/^(senior|sr\.?|staff|principal|lead|junior|jr\.?|associate|mid[- ]level|entry[- ]level|distinguished|founding)\s+/u', $base)) $base = preg_replace('/^\S+\s+/u', '', $base);
    $base = trim(preg_replace('/\s+(i|ii|iii|iv|v|[1-5])$/u', '', $base));
    $roleOf = [
        'ui designer' => 'UI/UX Designer', 'ux designer' => 'UI/UX Designer', 'ui/ux designer' => 'UI/UX Designer', 'ux/ui designer' => 'UI/UX Designer', 'web designer' => 'UI/UX Designer',
        'product designer' => 'Product Designer', 'ux researcher' => 'UX Researcher', 'user researcher' => 'UX Researcher', 'design researcher' => 'UX Researcher',
        'design engineer' => 'Design Engineer', 'ux engineer' => 'Design Engineer', 'creative technologist' => 'Creative Technologist',
        'visual designer' => 'Visual Designer', 'graphic designer' => 'Graphic Designer', 'illustrator' => 'Illustrator', 'interaction designer' => 'Interaction Designer',
        'design system designer' => 'Design System Designer', 'design systems designer' => 'Design System Designer', 'art director' => 'Art Director',
        'packaging designer' => 'Packaging Designer', 'brand designer' => 'Brand Designer', 'cmf designer' => 'CMF Designer', 'creative coder' => 'Creative Coder', 'vibe coder' => 'Vibe Coder',
    ];
    $role = $roleOf[$base] ?? null;
    if ($role === null) return null;
    $category = array_column($roles['tags'], 'category', 'tag')[$role] ?? null;
    if ($category === null) return null;

    $rawLoc = (string)$c['location'];
    $location = format_location($rawLoc);
    if ($location === '') return null;
    $region = preg_match('/\b(india|bengaluru|bangalore|mumbai|delhi|gurgaon|gurugram|noida|hyderabad|pune|chennai|kolkata|ahmedabad|jaipur|kochi|chandigarh|indore)\b/i', $rawLoc) ? 'india' : 'abroad';

    $wp = mb_strtolower((string)($c['workplace'] ?? ''));
    $mode = str_contains($wp, 'remote') ? 'remote' : (str_contains($wp, 'hybrid') ? 'hybrid' : (preg_match('/on[- ]?site|office/', $wp) ? 'in-office' : (preg_match('/\bremote\b/i', $rawLoc) ? 'remote' : null)));

    $today = date('Y-m-d');
    $posted = (!empty($c['posted_at']) && $c['posted_at'] <= $today) ? $c['posted_at'] : $today;
    if ((strtotime($today) - strtotime($posted)) / 86400 > (int)($settings['stale_days'] ?? 90)) return null;

    $job = ['title' => no_em_dash(mb_substr($title, 0, 200)), 'company' => no_em_dash(trim((string)$c['company'])), 'location' => $location, 'url' => normalize_job_url((string)$c['url']),
            'posted_at' => $posted, 'added_at' => $today, 'region' => $region, 'tags' => [$category], 'role' => $role, 'source' => (string)($c['source'] ?? 'collector')];
    if ($mode) $job['mode'] = $mode;
    return $job;
}

// A page that lists many jobs. Finds the links on it, asks the AI which look like our roles, and hands them back so the
// caller (the /admin card in the browser) can check them one at a time with intake_job(..., allowList: false).
function intake_list(array $page, array $existing, array $roles): array
{
    $all = extract_page_links($page['html'] ?? '', $page['final_url']);
    if (!$all) return ['status' => 'rejected', 'message' => 'This page lists jobs, but I could not find the links to the individual jobs. It may load them with JavaScript. Try a different page, or paste single job links.'];

    $known = 0; $new = [];
    foreach ($all as $l) {
        if (job_already_listed($existing, ['url' => $l['url'], 'title' => "\0", 'company' => "\0", 'location' => "\0"])) { $known++; continue; }
        $new[] = $l;
    }
    if (!$new) return ['status' => 'duplicate', 'message' => 'Every job link on that page is already in the list.'];

    $pick = groq_pick_links($new, $roles);
    if (!$pick['ok']) return ['status' => 'error', 'message' => $pick['error']];
    if (!$pick['links']) return ['status' => 'rejected', 'message' => 'That page lists jobs, but none of the titles look like our roles.' . ($known ? " ($known already in the list.)" : '')];

    return ['status' => 'list', 'message' => 'This page lists many jobs. ' . count($pick['links']) . ' may fit our roles' . ($known ? " ($known already in the list)" : '') . '. Checking each one now.',
            'links' => $pick['links'], 'already_listed' => $known];
}


// A candidate from a company job board: we already hold the text, so no page needs to be downloaded.
function intake_candidate(array $c): array
{
    $roles    = require __DIR__ . '/../config/roles.php';
    $settings = require __DIR__ . '/../config/settings.php';
    try { $existing = jobs_read_fresh(); } catch (Throwable $e) { return ['status' => 'error', 'service_error' => true, 'message' => $e->getMessage()]; }
    $url = trim((string)$c['url']);
    if (job_already_listed($existing, ['url' => $url, 'title' => "\0", 'company' => "\0", 'location' => "\0"])) return ['status' => 'duplicate', 'message' => 'That link is already in the list.'];

    $structured = array_filter([
        'title' => $c['title'], 'company' => $c['company'], 'locations' => $c['location'] !== '' ? [$c['location']] : null,
        'workplace_type' => $c['workplace'] ?? null, 'date_posted' => $c['posted_at'] ?? null, 'employment_type' => $c['employment_type'] ?? null,
    ], fn($v) => $v !== null && $v !== '' && $v !== []);
    $page = ['ok' => true, 'final_url' => $url, 'page_title' => (string)$c['title'], 'description' => '', 'structured' => $structured,
             'text' => mb_substr((string)($c['description'] ?? ''), 0, (int)$roles['rules']['max_page_chars']), 'html' => ''];
    return intake_decide($page, $url, (string)($c['source'] ?? 'collector'), $existing, $roles, $settings, false, ['posted_at' => $c['posted_at'] ?? null]);
}
