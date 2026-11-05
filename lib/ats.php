<?php
// Reads the public job boards of companies (Greenhouse, Lever, Ashby, SmartRecruiters) and turns every opening whose title
// looks like one of our roles into a "candidate". The AI then checks each candidate (see lib/collector.php).
//
// A candidate is: url, title, company, location, posted_at (YYYY-MM-DD or null), description (plain text), employment_type,
// workplace (remote | hybrid | onsite | null), source, mode ('ats' = we already hold the text, 'url' = the page must be opened).

require_once __DIR__ . '/helpers.php';

// ---------- cheap pre-check on the TITLE only (no AI): is it worth asking the AI about? ----------
function title_is_candidate(string $title): bool
{
    $t = mb_strtolower($title);
    $fits = '/\b(designers?|design|art director|art direction|illustrat\w*|creative|brand|ux|ui|user experience|user interface|visual|graphic|packaging|cmf|interaction|technologist|vibe|typograph\w*|motion|ux research\w*|user research\w*|design research\w*|researcher)\b/u';
    if (!preg_match($fits, $t)) return false;
    if (preg_match('/\b(researcher|research)\b/u', $t) && !preg_match('/\b(ux|user|design|product|customer|human|usability|interaction)\b/u', $t)
        && !preg_match('/\b(designers?|design|creative|brand|visual|graphic)\b/u', $t)) return false;     // "Research Scientist" is not UX research
    // clearly other fields, unless the title is also about design
    $other = '/\b(software|backend|back-end|full[- ]?stack|data|machine learning|devops|security|sales|account|recruit\w*|finance|legal|counsel|solutions|support|operations|supply chain|manufacturing|mechanical|electrical|asic|silicon|firmware|fpga|verification|hardware|sre|infrastructure|platform engineer|growth|seo|paid|performance marketing|product manager|program manager|engineering manager)\b/u';
    if (preg_match($other, $t) && !preg_match('/\b(designers?|art director|illustrat\w*|creative|brand)\b/u', $t)) return false;
    return true;
}

// ---------- small helpers ----------
function plain_from_html(string $html, int $max = 4000): string
{
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');          // some boards send the HTML escaped
    $html = preg_replace('#</(p|div|li|h[1-6]|br|tr|ul|ol)>|<br\s*/?>#i', "\n", $html);
    $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
    $t = trim(preg_replace("/\n\s*\n+/", "\n", $t));
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max) . "\n[text cut here]" : $t;
}

function date_only($v): ?string
{
    if ($v === null || $v === '') return null;
    $ts = is_numeric($v) ? (int)($v > 99999999999 ? $v / 1000 : $v) : strtotime((string)$v);
    return $ts ? date('Y-m-d', $ts) : null;
}

// Several web requests at the same time. $reqs = [key => url]. Returns [key => ['code' => int, 'body' => string, 'url' => final address after redirects]]
// $maxBytes > 0 stops reading a page after that many bytes (enough to see whether a job page says "closed").
function fetch_many(array $reqs, int $timeout = 12, int $maxBytes = 0): array
{
    $mh = curl_multi_init(); $hs = []; $bufs = [];
    foreach ($reqs as $k => $url) {
        $ch = curl_init($url);
        $bufs[$k] = '';
        $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_ENCODING => '', CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; JobBoardBot/1.0)', CURLOPT_HTTPHEADER => ['Accept: application/json, application/xml, text/html;q=0.8']];
        if ($maxBytes > 0) {
            unset($opts[CURLOPT_RETURNTRANSFER]);
            $opts[CURLOPT_WRITEFUNCTION] = function ($c, $chunk) use (&$bufs, $k, $maxBytes) { $bufs[$k] .= $chunk; return strlen($bufs[$k]) > $maxBytes ? 0 : strlen($chunk); };
        }
        curl_setopt_array($ch, $opts);
        curl_multi_add_handle($mh, $ch); $hs[$k] = $ch;
    }
    do { $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0); } while ($running && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $k => $ch) {
        $out[$k] = ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => $maxBytes > 0 ? $bufs[$k] : (string)curl_multi_getcontent($ch), 'url' => (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL)];
        curl_multi_remove_handle($mh, $ch);          // (no curl_close: it does nothing since PHP 8.0 and PHP 8.5 complains about it)
    }
    @curl_multi_close($mh);
    return $out;
}

function json_body(array $r): ?array
{
    if ($r['code'] !== 200) return null;
    $d = json_decode($r['body'], true);
    return is_array($d) ? $d : null;
}

// ---------- one company board ----------
// Reads the boards of several companies at once. $companies = rows of config/companies.php 'readable'.
// Returns ['candidates' => [...], 'errors' => ['Company' => 'message'], 'seen' => number of openings looked at]
function ats_gather_many(array $companies, int $staleDays = 90): array
{
    $cands = []; $errors = []; $seen = 0;
    $cut = time() - $staleDays * 86400;

    // 1. the list of openings of every company, in parallel
    $reqs = [];
    foreach ($companies as $i => $c) {
        $t = rawurlencode($c['token']);
        $reqs[$i] = match ($c['ats']) {
            'greenhouse'      => "https://boards-api.greenhouse.io/v1/boards/$t/jobs",
            'lever'           => "https://api.lever.co/v0/postings/$t?mode=json",
            'ashby'           => "https://api.ashbyhq.com/posting-api/job-board/$t",
            'smartrecruiters' => "https://api.smartrecruiters.com/v1/companies/$t/postings?limit=100",
            default           => '',
        };
        if ($reqs[$i] === '') unset($reqs[$i]);
    }
    $lists = fetch_many($reqs);

    $details = [];      // second round: Greenhouse and SmartRecruiters need one more request per opening for the text
    foreach ($companies as $i => $c) {
        if (!isset($lists[$i])) continue;
        $d = json_body($lists[$i]);
        if ($d === null) { $errors[$c['name']] = 'could not read its job board (HTTP ' . $lists[$i]['code'] . ')'; continue; }
        $name = $c['name']; $src = 'company: ' . $name;

        switch ($c['ats']) {
            case 'greenhouse':
                foreach ($d['jobs'] ?? [] as $j) {
                    $seen++;
                    $date = date_only($j['updated_at'] ?? ($j['first_published'] ?? null));     // "last updated": the first publish date stays old for jobs that are still open
                    if (!title_is_candidate((string)($j['title'] ?? '')) || ($date && strtotime($date) < $cut)) continue;
                    $details["$i|" . $j['id']] = ['url' => "https://boards-api.greenhouse.io/v1/boards/" . rawurlencode($c['token']) . "/jobs/" . $j['id'],
                        'base' => ['url' => (string)$j['absolute_url'], 'title' => $j['title'], 'company' => $name, 'location' => (string)($j['location']['name'] ?? ''), 'posted_at' => $date, 'source' => $src]];
                }
                break;
            case 'lever':
                foreach (is_array($d) ? $d : [] as $j) {
                    $seen++;
                    $date = date_only($j['createdAt'] ?? null);
                    if (!title_is_candidate((string)($j['text'] ?? '')) || ($date && strtotime($date) < $cut)) continue;
                    $cat = $j['categories'] ?? [];
                    $loc = implode(' / ', array_filter((array)($cat['allLocations'] ?? [$cat['location'] ?? ''])));
                    $cands[] = ['url' => (string)$j['hostedUrl'], 'title' => $j['text'], 'company' => $name, 'location' => $loc, 'posted_at' => $date,
                        'description' => plain_from_html((string)($j['descriptionPlain'] ?? '') . "\n" . (string)($j['additionalPlain'] ?? '')), 'employment_type' => $cat['commitment'] ?? null,
                        'workplace' => $j['workplaceType'] ?? null, 'source' => $src, 'mode' => 'ats'];
                }
                break;
            case 'ashby':
                foreach ($d['jobs'] ?? [] as $j) {
                    $seen++;
                    $date = date_only($j['publishedAt'] ?? null);
                    if (($j['isListed'] ?? true) === false || !title_is_candidate((string)($j['title'] ?? '')) || ($date && strtotime($date) < $cut)) continue;
                    $locs = array_filter(array_merge([$j['location'] ?? ''], array_map(fn($s) => is_array($s) ? ($s['location'] ?? '') : (string)$s, (array)($j['secondaryLocations'] ?? []))));
                    $cands[] = ['url' => (string)$j['jobUrl'], 'title' => $j['title'], 'company' => $name, 'location' => implode(' / ', $locs), 'posted_at' => $date,
                        'description' => plain_from_html((string)($j['descriptionPlain'] ?? '')), 'employment_type' => $j['employmentType'] ?? null,
                        'workplace' => !empty($j['isRemote']) ? 'remote' : ($j['workplaceType'] ?? null), 'source' => $src, 'mode' => 'ats'];
                }
                break;
            case 'smartrecruiters':
                foreach ($d['content'] ?? [] as $j) {
                    $seen++;
                    $date = date_only($j['releasedDate'] ?? null);
                    if (!title_is_candidate((string)($j['name'] ?? '')) || ($date && strtotime($date) < $cut)) continue;
                    $l = $j['location'] ?? [];
                    $details["$i|" . $j['id']] = ['url' => (string)$j['ref'],
                        'base' => ['url' => 'https://jobs.smartrecruiters.com/' . rawurlencode($c['token']) . '/' . $j['id'], 'title' => trim((string)$j['name']), 'company' => $name,
                            'location' => (string)($l['fullLocation'] ?? ''), 'posted_at' => $date, 'source' => $src,
                            'workplace' => !empty($l['remote']) ? 'remote' : (!empty($l['hybrid']) ? 'hybrid' : null), 'employment_type' => $j['typeOfEmployment']['label'] ?? null]];
                }
                break;
        }
    }

    // 2. the text of the openings that passed the title check
    if ($details) {
        $got = fetch_many(array_map(fn($x) => $x['url'], $details));
        foreach ($details as $k => $x) {
            $d = json_body($got[$k]);
            if ($d === null) continue;
            $b = $x['base'];
            if (isset($d['content'])) {                                                      // Greenhouse
                $b['description'] = plain_from_html((string)$d['content']);
            } else {                                                                         // SmartRecruiters
                $parts = [];
                foreach ($d['jobAd']['sections'] ?? [] as $s) if (!empty($s['text'])) $parts[] = ($s['title'] ?? '') . "\n" . plain_from_html((string)$s['text'], 2000);
                $b['description'] = mb_substr(implode("\n\n", $parts), 0, 4000);
                if (!empty($d['postingUrl'])) $b['url'] = $d['postingUrl'];
            }
            $b += ['employment_type' => null, 'workplace' => null, 'mode' => 'ats'];
            $b['mode'] = 'ats';
            $cands[] = $b;
        }
    }
    return ['candidates' => $cands, 'errors' => $errors, 'seen' => $seen];
}


// ---------- "is this job still on the company's board?" ----------
// A job is recognised by its link. SmartRecruiters links carry a long number; the board lists numbers, so we compare those.
function job_board_key(string $url): string
{
    if (preg_match('#smartrecruiters\.com/[^/]+/(\d{6,})#i', $url, $m)) return 'sr:' . $m[1];
    return normalize_job_url($url);
}

// Reads the current openings of several company boards (links only).
// Returns [company name => ['ok' => bool, 'keys' => [key => true], 'count' => n]]. ok = false when the board could not be read completely.
function ats_board_keys(array $companies): array
{
    $reqs = [];
    foreach ($companies as $i => $c) {
        $t = rawurlencode($c['token']);
        $reqs[$i] = match ($c['ats']) {
            'greenhouse'      => "https://boards-api.greenhouse.io/v1/boards/$t/jobs",
            'lever'           => "https://api.lever.co/v0/postings/$t?mode=json",
            'ashby'           => "https://api.ashbyhq.com/posting-api/job-board/$t",
            'smartrecruiters' => "https://api.smartrecruiters.com/v1/companies/$t/postings?limit=100",
            default           => '',
        };
        if ($reqs[$i] === '') unset($reqs[$i]);
    }
    $got = fetch_many($reqs, 15);
    $out = [];
    foreach ($companies as $i => $c) {
        $d = isset($got[$i]) ? json_body($got[$i]) : null;
        if ($d === null) { $out[$c['name']] = ['ok' => false, 'keys' => [], 'count' => 0]; continue; }
        $keys = []; $complete = true;
        switch ($c['ats']) {
            case 'greenhouse': foreach ($d['jobs'] ?? [] as $j) $keys[job_board_key((string)$j['absolute_url'])] = true; break;
            case 'lever':      foreach (is_array($d) ? $d : [] as $j) $keys[job_board_key((string)$j['hostedUrl'])] = true; break;
            case 'ashby':      foreach ($d['jobs'] ?? [] as $j) $keys[job_board_key((string)$j['jobUrl'])] = true; break;
            case 'smartrecruiters':
                foreach ($d['content'] ?? [] as $j) $keys['sr:' . $j['id']] = true;
                if ((int)($d['totalFound'] ?? 0) > count($d['content'] ?? [])) $complete = false;      // more openings than one page: we only saw part of the list
                break;
        }
        $out[$c['name']] = ['ok' => $complete && count($keys) > 0, 'keys' => $keys, 'count' => count($keys)];   // an empty list is not trusted either
    }
    return $out;
}
