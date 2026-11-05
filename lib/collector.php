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

const COLLECTOR_COMPANIES_PER_STEP = 8;      // company job boards read per request
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
        priority   SMALLINT NOT NULL DEFAULT 1,           -- 0 = looks like an India job (checked first), 1 = everything else
        claimed_at TIMESTAMPTZ,
        done_at    TIMESTAMPTZ
    )");
    db()->exec('ALTER TABLE job_queue ADD COLUMN IF NOT EXISTS priority SMALLINT NOT NULL DEFAULT 1');
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
    $st = db()->prepare('INSERT INTO job_queue (url_key, url, source, payload, posted_at, priority) VALUES (:k, :u, :s, :p, :d, :pr) ON CONFLICT (url_key) DO NOTHING');
    $new = 0;
    foreach ($cands as $c) {
        $url = trim((string)($c['url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
        $key = normalize_job_url($url);
        if (isset($known[$key])) continue;
        $c['url'] = $url;
        $c += ['mode' => 'url', 'posted_at' => null, 'source' => 'collector'];
        $st->execute([':k' => $key, ':u' => $url, ':s' => $c['source'], ':p' => json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':d' => db_date($c['posted_at']),
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
// Takes the next job in the queue (newest first), lets the AI decide, saves it or refuses it.
// Returns one of: ['status' => 'empty'] | ['status' => 'checked', 'result' => ..., ...] | ['status' => 'wait', 'seconds' => n] | ['status' => 'stop', 'message' => ...]
function collector_next(): array
{
    collector_ready();
    $pdo = db();
    $row = $pdo->query("UPDATE job_queue SET state = 'working', claimed_at = now()
        WHERE url_key = (SELECT url_key FROM job_queue WHERE state = 'pending' ORDER BY priority, posted_at DESC NULLS LAST, queued_at LIMIT 1 FOR UPDATE SKIP LOCKED)
        RETURNING *")->fetch();
    if (!$row) return ['status' => 'empty', 'remaining' => 0];

    $c = json_decode($row['payload'], true) ?: ['url' => $row['url'], 'mode' => 'url', 'source' => $row['source']];
    $res = ($c['mode'] ?? 'url') === 'ats' ? intake_candidate($c) : intake_job($c['url'], (string)($c['source'] ?? 'collector'), false);

    if (!empty($res['service_error'])) {                                              // Groq or the database had a problem: put the job back, do not blame the job
        $pdo->prepare("UPDATE job_queue SET state = 'pending' WHERE url_key = :k")->execute([':k' => $row['url_key']]);
        if (!empty($res['rate_limited']) && empty($res['daily_limit'])) return ['status' => 'wait', 'seconds' => min(120, (int)($res['retry_after'] ?? 30)), 'message' => 'Groq asked us to slow down.'];
        return ['status' => 'stop', 'message' => $res['message'] . (!empty($res['daily_limit']) ? ' Progress is saved: run it again later and it continues from here.' : '')];
    }

    $result = in_array($res['status'], ['added', 'rejected', 'duplicate'], true) ? $res['status'] : 'error';
    $pdo->prepare("UPDATE job_queue SET state = 'done', result = :r, reason = :m, done_at = now() WHERE url_key = :k")
        ->execute([':r' => $result, ':m' => mb_substr((string)($res['message'] ?? ''), 0, 300), ':k' => $row['url_key']]);
    $left = (int)$pdo->query("SELECT count(*) FROM job_queue WHERE state = 'pending'")->fetchColumn();
    return ['status' => 'checked', 'result' => $result, 'title' => (string)($c['title'] ?? ''), 'company' => (string)($c['company'] ?? ''), 'message' => (string)($res['message'] ?? ''), 'remaining' => $left];
}

// ---------- the actions the /admin card asks for ----------
function collector_action(string $action, array $in): array
{
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
