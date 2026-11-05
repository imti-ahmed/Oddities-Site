<?php
// The collector: fills the board from OUR LIST (config/companies.php + config/sources.php) using the AI.
//
//   1. GATHER   read every company job board and every source; every opening whose title could fit goes into a QUEUE (a database table)
//   2. CHECK    take one queued job at a time, let the AI check it against our rules (lib/intake.php), list it or refuse it
//
// The queue remembers what was already checked, so a run can stop at any moment (Groq's limits, closed browser tab) and carry on later
// without spending AI time on the same job twice. Needs the database (DATABASE_URL).

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/ats.php';
require_once __DIR__ . '/pagefetch.php';
require_once __DIR__ . '/groq.php';
require_once __DIR__ . '/intake.php';

const COLLECTOR_COMPANIES_PER_STEP = 5;      // company job boards read per request
const COLLECTOR_RECHECK_DAYS = 30;           // a job the AI refused is looked at again after this many days

function collector_ready(): void
{
    static $done = false;
    if ($done) return;
    if (jobs_store_mode() !== 'db') throw new RuntimeException('The collector needs the database. Add DATABASE_URL in Vercel (the Neon connection string), then redeploy.');
    try { db()->query('SELECT 1 FROM jobs LIMIT 1'); }                  // the jobs table itself is made on first use
    catch (Throwable $e) { if (!db_missing_table($e)) throw $e; db_create_and_seed(); }
    db()->exec("CREATE TABLE IF NOT EXISTS job_queue (
        url_key    TEXT PRIMARY KEY,
        url        TEXT NOT NULL,
        source     TEXT,
        payload    TEXT NOT NULL,
        posted_at  DATE,
        state      TEXT NOT NULL DEFAULT 'pending',   -- pending | working | done
        result     TEXT,                              -- added | rejected | duplicate | error
        reason     TEXT,
        queued_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
        mode       TEXT NOT NULL DEFAULT 'url',                    -- ats = we already hold the text, url = the page must be opened
        priority   SMALLINT NOT NULL DEFAULT 1,           -- 0 = looks like an India job (checked first), 1 = everything else
        claimed_at TIMESTAMPTZ,
        done_at    TIMESTAMPTZ
    )");
    db()->exec('ALTER TABLE job_queue ADD COLUMN IF NOT EXISTS priority SMALLINT NOT NULL DEFAULT 1');
    db()->exec("ALTER TABLE job_queue ADD COLUMN IF NOT EXISTS mode TEXT NOT NULL DEFAULT 'url'");
    // housekeeping: a claim nobody finished (closed tab, crash) goes back in line after 10 minutes; old refusals are looked at again
    db()->exec("UPDATE job_queue SET state = 'pending' WHERE state = 'working' AND claimed_at < now() - interval '10 minutes'");
    db()->exec("DELETE FROM job_queue WHERE state = 'done' AND result <> 'added' AND done_at < now() - interval '" . COLLECTOR_RECHECK_DAYS . " days'");
    $done = true;
}

function collector_companies(): array { $c = require __DIR__ . '/../config/companies.php'; return $c['readable']; }
function collector_sources(): array   { $s = require __DIR__ . '/../config/sources.php'; return $s['use']; }

function collector_stats(): array
{
    collector_ready();
    $by = ['pending' => 0, 'working' => 0, 'done' => 0, 'added' => 0];
    foreach (db()->query("SELECT state, result, count(*) n FROM job_queue GROUP BY state, result") as $r) {
        $by[$r['state']] = ($by[$r['state']] ?? 0) + (int)$r['n'];
        if ($r['result'] === 'added') $by['added'] += (int)$r['n'];
    }
    $by['jobs'] = (int)db()->query('SELECT count(*) FROM jobs')->fetchColumn();
    return $by;
}

// Puts candidates in the queue (skipping jobs that are already listed or already queued/checked). Returns how many are new.
function collector_queue(array $cands): int
{
    if (!$cands) return 0;
    $known = [];
    foreach (db()->query('SELECT url_key FROM jobs') as $r) $known[$r['url_key']] = true;
    $st = db()->prepare('INSERT INTO job_queue (url_key, url, source, payload, posted_at, priority, mode) VALUES (:k, :u, :s, :p, :d, :pr, :m) ON CONFLICT (url_key) DO NOTHING');
    $new = 0;
    foreach ($cands as $c) {
        $url = trim((string)($c['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
        $key = normalize_job_url($url);
        if (isset($known[$key])) continue;
        $c['url'] = $url;
        $c += ['mode' => 'url', 'posted_at' => null, 'source' => 'collector'];
        $st->execute([':k' => $key, ':u' => $url, ':s' => $c['source'], ':p' => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':d' => db_date($c['posted_at']), ':m' => ($c['mode'] ?? 'url') === 'ats' ? 'ats' : 'url',
            ':pr' => preg_match('/india|bengaluru|bangalore|mumbai|delhi|gurgaon|gurugram|noida|hyderabad|pune|chennai|kolkata|ahmedabad|jaipur|kochi|indore|chandigarh/i', ($c['location'] ?? '') . ' ' . ($c['title'] ?? '') . ' ' . ($c['source'] ?? '') . ' ' . $url) ? 0 : 1]);
        $new += $st->rowCount();
    }
    return $new;
}

// ---------- 1. GATHER ----------
// Company job boards, a few at a time. $from = where we are in the list.
function collector_gather_companies(int $from): array
{
    collector_ready();
    $settings = require __DIR__ . '/../config/settings.php';
    $all = collector_companies();
    $slice = array_slice($all, max(0, $from), COLLECTOR_COMPANIES_PER_STEP);
    $r = ats_gather_many($slice, (int)$settings['stale_days']);
    $queued = collector_queue($r['candidates']);
    $next = $from + count($slice);
    return ['status' => 'ok', 'next' => $next, 'total' => count($all), 'finished' => $next >= count($all), 'found' => count($r['candidates']), 'queued' => $queued,
            'looked_at' => $r['seen'], 'errors' => $r['errors'],
            'message' => 'Read the job boards of companies ' . ($from + 1) . ' to ' . $next . ' of ' . count($all) . '.'];
}

// One of the sources (job sites), by its position in config/sources.php.
function collector_gather_source(int $i): array
{
    collector_ready();
    $sources = collector_sources();
    if (!isset($sources[$i])) return ['status' => 'ok', 'finished' => true, 'message' => 'No more sources.'];
    $s = $sources[$i];
    $label = 'source: ' . $s['name'];
    $out = ['status' => 'ok', 'name' => $s['name'], 'index' => $i, 'total' => count($sources), 'finished' => $i + 1 >= count($sources), 'found' => 0, 'queued' => 0];
    $strict = env('VERCEL') !== '';
    $cands = [];

    try {
        if (($s['how'] ?? '') === 'rss') {
            $r = fetch_many(['f' => $s['feed']]); $x = @simplexml_load_string($r['f']['body']);
            foreach ($x ? $x->channel->item : [] as $it) {
                $t = trim((string)$it->title);
                if (title_is_candidate($t)) $cands[] = ['url' => trim((string)$it->link), 'title' => $t, 'posted_at' => date_only((string)$it->pubDate), 'source' => $label, 'mode' => 'url'];
            }
            if (!$x || !count($x->channel->item)) $out['note'] = 'its feed has no jobs in it right now';
        } elseif (($s['how'] ?? '') === 'api') {                                      // Hacker News: ids, then each story
            $r = fetch_many(['ids' => $s['api']]); $ids = json_decode($r['ids']['body'], true) ?: [];
            $reqs = []; foreach (array_slice($ids, 0, 80) as $id) $reqs[$id] = 'https://hacker-news.firebaseio.com/v0/item/' . (int)$id . '.json';
            foreach (fetch_many($reqs) as $id => $resp) {
                $it = json_body($resp); if (!$it || empty($it['title']) || !title_is_candidate($it['title'])) continue;
                $cands[] = ['url' => $it['url'] ?? ('https://news.ycombinator.com/item?id=' . $id), 'title' => $it['title'], 'posted_at' => date_only($it['time'] ?? null), 'source' => $label, 'mode' => 'url'];
            }
        } else {                                                                      // a normal web page: its links, then the AI picks the job links
            $d = download_page($s['url'], $strict);
            if (!$d['ok']) return $out + ['message' => $s['name'] . ': ' . $d['error'], 'note' => $d['error']];
            $links = extract_page_links($d['html'], $d['final_url']);
            $known = [];
            foreach (db()->query('SELECT url_key FROM jobs UNION SELECT url_key FROM job_queue') as $r) $known[$r['url_key']] = true;
            $links = array_values(array_filter($links, fn($l) => !isset($known[normalize_job_url($l['url'])])));
            if ($links) {
                $roles = require __DIR__ . '/../config/roles.php';
                $pick = groq_pick_links($links, $roles, 60);
                if (!$pick['ok']) return $out + ['status' => !empty($pick['rate_limited']) ? 'wait' : 'ok', 'seconds' => $pick['retry_after'] ?? null, 'message' => $s['name'] . ': ' . $pick['error'], 'note' => $pick['error']];
                foreach ($pick['links'] as $l) $cands[] = ['url' => $l['url'], 'title' => $l['text'], 'posted_at' => null, 'source' => $label, 'mode' => 'url'];
            }
        }
    } catch (Throwable $e) {
        return $out + ['message' => $s['name'] . ': ' . mb_substr($e->getMessage(), 0, 160), 'note' => 'could not be read'];
    }
    $out['found'] = count($cands);
    $out['queued'] = collector_queue($cands);
    $out['message'] = $s['name'] . ': found ' . $out['found'] . ' job links' . ($out['queued'] !== $out['found'] ? ', ' . $out['queued'] . ' new' : '') . (!empty($out['note']) ? ' (' . $out['note'] . ')' : '') . '.';
    return $out;
}

// ---------- 2. CHECK ----------
// Takes the next BATCH of queued jobs (newest first, India first) and settles them with as few AI calls as possible:
//   1. a link or a title+company+place that is already listed is skipped (no AI)
//   2. a job with a plainly right title and a known place is listed by plain code (no AI), unless 'fast_path' is switched off
//   3. everything else goes to the AI together, up to 'batch_size' jobs in ONE call
// Returns one of: ['status' => 'empty'] | ['status' => 'checked', 'results' => [...], 'ai_calls' => n, 'remaining' => n]
//   | ['status' => 'wait', 'seconds' => n, 'results' => [...]] | ['status' => 'stop', 'message' => ..., 'results' => [...]]
function collector_next(): array
{
    collector_ready();
    $pdo = db();
    $roles = require __DIR__ . '/../config/roles.php';
    $settings = require __DIR__ . '/../config/settings.php';
    $size = max(1, (int)($roles['rules']['batch_size'] ?? 8));
    $order = 'priority, posted_at DESC NULLS LAST, queued_at';

    $mode = $pdo->query("SELECT mode FROM job_queue WHERE state = 'pending' ORDER BY $order LIMIT 1")->fetchColumn();
    if ($mode === false) return ['status' => 'empty', 'remaining' => 0];
    $n = $mode === 'ats' ? $size : min($size, 4);            // pages that must be opened one by one are taken in smaller groups
    $rows = $pdo->query("UPDATE job_queue SET state = 'working', claimed_at = now()
        WHERE url_key IN (SELECT url_key FROM job_queue WHERE state = 'pending' AND mode = " . $pdo->quote($mode) . " ORDER BY $order LIMIT $n FOR UPDATE SKIP LOCKED)
        RETURNING url_key, url, source, payload, mode")->fetchAll();
    if (!$rows) return ['status' => 'empty', 'remaining' => 0];

    try { $existing = jobs_read_fresh(); }
    catch (Throwable $e) { collector_unclaim($rows); return ['status' => 'stop', 'message' => $e->getMessage(), 'results' => []]; }

    $results = []; $ai = [];       // $ai = jobs that need the AI: id => ['row' =>, 'c' =>, 'page' =>]
    $finish = function (array $row, array $c, array $res, bool $usedAi) use ($pdo, &$results) {
        $result = in_array($res['status'], ['added', 'rejected', 'duplicate'], true) ? $res['status'] : 'error';
        $pdo->prepare("UPDATE job_queue SET state = 'done', result = :r, reason = :m, done_at = now() WHERE url_key = :k")
            ->execute([':r' => $result, ':m' => mb_substr((string)($res['message'] ?? ''), 0, 300), ':k' => $row['url_key']]);
        $results[] = ['result' => $result, 'title' => (string)($c['title'] ?? ''), 'company' => (string)($c['company'] ?? ''), 'message' => (string)($res['message'] ?? ''), 'ai' => $usedAi];
    };
    $strict = env('VERCEL') !== '';

    foreach ($rows as $i => $row) {
        $c = json_decode($row['payload'], true) ?: ['url' => $row['url'], 'mode' => 'url', 'source' => $row['source']];
        $c['url'] = $c['url'] ?? $row['url'];
        if (($c['mode'] ?? 'url') === 'ats') {
            if (job_already_listed($existing, ['url' => $c['url'], 'title' => "\0", 'company' => "\0", 'location' => "\0"])) { $finish($row, $c, ['status' => 'duplicate', 'message' => 'That link is already in the list.'], false); continue; }
            if (job_pre_duplicate($existing, $c)) { $finish($row, $c, ['status' => 'duplicate', 'message' => 'Same title, company and place are already in the list.'], false); continue; }
            $fast = fast_path_job($c, $roles, $settings);
            if ($fast) { $res = intake_save($fast, $existing); if (!empty($res['service_error'])) { collector_unclaim([$row]); continue; } $finish($row, $c, $res, false); continue; }
            $structured = array_filter(['title' => $c['title'], 'company' => $c['company'], 'locations' => ($c['location'] ?? '') !== '' ? [$c['location']] : null, 'workplace_type' => $c['workplace'] ?? null,
                'date_posted' => $c['posted_at'] ?? null, 'employment_type' => $c['employment_type'] ?? null], fn($v) => $v !== null && $v !== '' && $v !== []);
            $page = ['ok' => true, 'final_url' => $c['url'], 'page_title' => (string)$c['title'], 'description' => '', 'structured' => $structured, 'text' => mb_substr((string)($c['description'] ?? ''), 0, (int)$roles['rules']['max_page_chars']), 'html' => ''];
        } else {
            [$okUrl, $why] = check_public_url($c['url'], $strict);
            if (!$okUrl) { $finish($row, $c, ['status' => 'error', 'message' => $why], false); continue; }
            if (job_already_listed($existing, ['url' => $c['url'], 'title' => "\0", 'company' => "\0", 'location' => "\0"])) { $finish($row, $c, ['status' => 'duplicate', 'message' => 'That link is already in the list.'], false); continue; }
            $page = read_job_page($c['url'], (int)$roles['rules']['max_page_chars'], $strict);
            if (!$page['ok']) { $finish($row, $c, ['status' => 'error', 'message' => $page['error']], false); continue; }
            if (mb_strlen(trim($page['text'])) < 120 && !$page['structured']) { $finish($row, $c, ['status' => 'rejected', 'message' => 'Not enough readable text on that page.'], false); continue; }
        }
        $ai[$i] = ['row' => $row, 'c' => $c, 'page' => $page];
    }

    $calls = 0;
    if ($ai) {
        $max = (int)($roles['rules']['batch_page_chars'] ?? 1800);
        $items = [];
        foreach ($ai as $id => $x) { $x['page']['text'] = mb_substr($x['page']['text'], 0, $max); $ai[$id]['page'] = $x['page']; $items[] = ['id' => $id, 'url' => $x['c']['url'], 'source' => (string)($x['c']['source'] ?? 'collector'), 'page' => $x['page']]; }
        $calls++;
        $r = groq_check_batch_safe($items, $roles);
        if ($r['ok']) $calls += max(0, ($r['calls'] ?? 1) - 1);
        if (!$r['ok']) {
            if (!empty($r['soft'])) { foreach ($ai as $x) $finish($x['row'], $x['c'], ['status' => 'error', 'message' => $r['error']], true); }
            else {                                                                    // Groq itself has a problem: put these jobs back, do not blame them
                collector_unclaim(array_column($ai, 'row'));
                $out = ['results' => $results, 'ai_calls' => $calls];
                if (!empty($r['rate_limited']) && empty($r['daily_limit'])) return ['status' => 'wait', 'seconds' => min(120, (int)($r['retry_after'] ?? 30)), 'message' => 'Groq asked us to slow down.'] + $out;
                return ['status' => 'stop', 'message' => $r['error'] . (!empty($r['daily_limit']) ? ' Progress is saved: run it again later and it continues from here.' : '')] + $out;
            }
        } else {
            foreach ($ai as $id => $x) {
                if (isset($r['failed'][$id])) { $finish($x['row'], $x['c'], ['status' => 'error', 'message' => $r['failed'][$id]], true); continue; }   // this one job could not be answered
                $answer = $r['answers'][$id] ?? null;
                if ($answer === null) {                                               // the AI skipped this one: ask about it alone
                    $calls++;
                    $one = groq_ask($x['page'], $x['c']['url'], (string)($x['c']['source'] ?? 'collector'), $roles);
                    if (!$one['ok']) {
                        if (empty($one['soft'])) { collector_unclaim([$x['row']]); continue; }
                        $finish($x['row'], $x['c'], ['status' => 'error', 'message' => $one['error']], true); continue;
                    }
                    $answer = $one['answer'];
                }
                $res = intake_apply_answer($answer, $x['page'], $x['c']['url'], (string)($x['c']['source'] ?? 'collector'), $existing, $roles, $settings, ['posted_at' => $x['c']['posted_at'] ?? null]);
                if (!empty($res['service_error'])) { collector_unclaim([$x['row']]); continue; }
                $finish($x['row'], $x['c'], $res, true);
            }
        }
    }
    $left = (int)$pdo->query("SELECT count(*) FROM job_queue WHERE state = 'pending'")->fetchColumn();
    return ['status' => 'checked', 'results' => $results, 'ai_calls' => $calls, 'remaining' => $left];
}

// Puts claimed jobs back in line (used when Groq or the database had a problem, so the job itself is not blamed).
function collector_unclaim(array $rows): void
{
    if (!$rows) return;
    $st = db()->prepare("UPDATE job_queue SET state = 'pending' WHERE url_key = :k");
    foreach ($rows as $r) $st->execute([':k' => $r['url_key']]);
}

// ---------- the actions the /admin card asks for ----------
function collector_action(string $action, array $in): array
{
    if (str_starts_with($action, 'live_')) { require_once __DIR__ . '/liveness.php'; return liveness_action($action, $in); }
    try {
        switch ($action) {
            case 'plan':
                $s = collector_stats();
                return ['status' => 'ok', 'companies' => count(collector_companies()), 'sources' => count(collector_sources()), 'pending' => $s['pending'], 'jobs' => $s['jobs']];
            case 'gather_companies': return collector_gather_companies((int)($in['from'] ?? 0));
            case 'gather_source':    return collector_gather_source((int)($in['index'] ?? 0));
            case 'next':             return collector_next();
            case 'stats':            return ['status' => 'ok'] + collector_stats();
        }
    } catch (Throwable $e) {
        return ['status' => 'stop', 'message' => $e instanceof PDOException ? 'Database problem: ' . db_error_hint($e) : $e->getMessage()];
    }
    return ['status' => 'stop', 'message' => 'Unknown request.'];
}
