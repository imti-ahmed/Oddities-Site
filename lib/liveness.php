<?php
// "Is this job still open?" Runs every night (and on the /admin button). NO AI is used here: it is just web requests.
//
//  * Jobs that came from a company's job board: we read the company's current list of openings (one request per company).
//    If our job is no longer on that list, the company took it down.
//  * Every other job (from the job sites, or pasted by hand): we open its link. A "not found" page, a page that says
//    "no longer available / position filled", or a redirect to the company's general job list means it is gone.
//
// Careful on purpose, so a hiccup never closes a good job:
//  * Only clear "gone" signals count. A blocked, slow or broken reply is "unknown": nothing changes and we try again tomorrow.
//  * A job is only closed after LIVENESS_NIGHTS "gone" signals in a row (two nights).
//  * An empty or partly read company board is never trusted.
//
// Jobs older than the Dead Zone limit (stale_days, 90) are not checked: they move to the Dead Zone by age anyway, even if still open.

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/ats.php';
require_once __DIR__ . '/pagefetch.php';

const LIVENESS_NIGHTS = 2;           // "gone" signals in a row before a job is closed
const LIVENESS_RECHECK_HOURS = 18;   // a job checked within this many hours is not checked again

function liveness_ready(): void
{
    static $done = false;
    if ($done) return;
    if (jobs_store_mode() !== 'db') throw new RuntimeException('The open-job check needs the database. Add DATABASE_URL in Vercel (the Neon connection string), then redeploy.');
    try { db()->query('SELECT 1 FROM jobs LIMIT 1'); }
    catch (Throwable $e) { if (!db_missing_table($e)) throw $e; db_create_and_seed(); }
    db_add_check_columns();
    $done = true;
}

// The jobs worth checking right now: not closed, not older than the Dead Zone limit, not checked in the last few hours.
function liveness_due_where(): string
{
    $settings = require __DIR__ . '/../config/settings.php';
    $days = (int)$settings['stale_days'];
    return "status IS DISTINCT FROM 'closed' AND deleted_at IS NULL
        AND COALESCE(GREATEST(posted_at, updated_at), posted_at, updated_at, added_at, current_date) >= current_date - $days
        AND (last_checked_at IS NULL OR last_checked_at < now() - interval '" . LIVENESS_RECHECK_HOURS . " hours')";
}

function liveness_remaining(): int
{
    return (int)db()->query('SELECT count(*) FROM jobs WHERE ' . liveness_due_where())->fetchColumn();
}

// Does a page say the job is gone? Returns the phrase it found, or null.
function page_says_closed(string $html): ?string
{
    $html = preg_replace('#<(script|style|noscript)\b.*?</\1>#is', ' ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = mb_substr(preg_replace('/\s+/u', ' ', $text), 0, 8000);
    $re = '/\b(no longer (available|accepting applications|open|active)|(job|position|role|posting|vacancy|opening|listing) (is |has been |was )?(now )?(closed|filled|expired|removed)|this (job|position|role|posting) (has expired|is closed|is no longer)|applications? (are |is )?(now )?closed|not accepting (any )?applications|page not found|job not found|(we )?couldn.t find (that|this) (job|page))\b/i';
    return preg_match($re, $text, $m) ? $m[0] : null;
}

// One round: checks up to $n due jobs. Returns a summary (counts, the jobs that were closed, how many are still due).
function liveness_batch(int $n = 40): array
{
    liveness_ready();
    $pdo = db();
    $rows = $pdo->query('SELECT id, url, source, title, company, check_fails FROM jobs WHERE ' . liveness_due_where() . ' ORDER BY last_checked_at NULLS FIRST, id LIMIT ' . max(1, $n))->fetchAll();
    $out = ['status' => 'ok', 'checked' => count($rows), 'alive' => 0, 'gone_signal' => 0, 'unknown' => 0, 'closed' => 0, 'closed_jobs' => []];
    if (!$rows) return $out + ['remaining' => 0];

    // which jobs belong to a company board we can read
    $byName = [];
    foreach (collector_companies_list() as $c) $byName[mb_strtolower($c['name'])] = $c;
    $needBoards = []; $direct = [];
    foreach ($rows as $r) {
        $co = preg_match('/^company:\s*(.+)$/i', (string)$r['source'], $m) ? ($byName[mb_strtolower(trim($m[1]))] ?? null) : null;
        if ($co) $needBoards[$co['name']] = $co; else $direct[$r['id']] = $r['url'];
    }
    $boards = $needBoards ? ats_board_keys(array_values($needBoards)) : [];
    $pages  = $direct ? fetch_many($direct, 12, 150000) : [];

    $upd = $pdo->prepare('UPDATE jobs SET last_checked_at = now(), last_seen_at = CASE WHEN :alive THEN now() ELSE last_seen_at END, check_fails = :fails,
        status = CASE WHEN :close THEN \'closed\' ELSE status END, closed_at = CASE WHEN :close THEN current_date ELSE closed_at END,
        closed_reason = CASE WHEN :close THEN :reason ELSE closed_reason END WHERE id = :id');
    foreach ($rows as $r) {
        $verdict = 'unknown'; $why = '';
        $co = preg_match('/^company:\s*(.+)$/i', (string)$r['source'], $m) ? ($byName[mb_strtolower(trim($m[1]))] ?? null) : null;
        if ($co) {
            $b = $boards[$co['name']] ?? ['ok' => false];
            if ($b['ok']) { if (isset($b['keys'][job_board_key($r['url'])])) $verdict = 'alive'; else { $verdict = 'gone'; $why = 'No longer on the company\'s job board'; } }
        } else {
            $p = $pages[$r['id']] ?? ['code' => 0, 'body' => '', 'url' => ''];
            if (in_array($p['code'], [404, 410], true)) { $verdict = 'gone'; $why = 'The job page is gone (HTTP ' . $p['code'] . ')'; }
            elseif ($p['code'] === 200) {
                $phrase = page_says_closed($p['body']);
                $o = array_values(array_filter(explode('/', (string)parse_url($r['url'], PHP_URL_PATH)), 'strlen'));
                $f = array_values(array_filter(explode('/', (string)parse_url($p['url'], PHP_URL_PATH)), 'strlen'));
                if ($phrase) { $verdict = 'gone'; $why = 'The job page says it is closed'; }
                elseif (str_contains((string)parse_url($p['url'], PHP_URL_QUERY), 'error=true') || (count($o) >= 2 && count($f) <= 1 && parse_url($p['url'], PHP_URL_HOST) === parse_url($r['url'], PHP_URL_HOST))) { $verdict = 'gone'; $why = 'The job page redirects to the general job list'; }
                else $verdict = 'alive';
            }                                                                      // anything else (blocked, slow, broken) stays "unknown"
        }

        $fails = (int)$r['check_fails']; $close = false;
        if ($verdict === 'alive') { $fails = 0; $out['alive']++; }
        elseif ($verdict === 'gone') { $fails++; $out['gone_signal']++; if ($fails >= LIVENESS_NIGHTS) { $close = true; $out['closed']++; $out['closed_jobs'][] = ['title' => $r['title'], 'company' => $r['company'], 'reason' => $why]; } }
        else $out['unknown']++;
        $upd->execute([':alive' => $verdict === 'alive' ? 'true' : 'false', ':fails' => $fails, ':close' => $close ? 'true' : 'false', ':reason' => $why, ':id' => $r['id']]);
    }
    return $out + ['remaining' => liveness_remaining()];
}

function collector_companies_list(): array { $c = require __DIR__ . '/../config/companies.php'; return $c['readable']; }

function liveness_action(string $action, array $in): array
{
    try {
        if ($action === 'live_plan') { liveness_ready(); return ['status' => 'ok', 'due' => liveness_remaining()]; }
        if ($action === 'live_batch') return liveness_batch((int)($in['size'] ?? 40));
    } catch (Throwable $e) {
        return ['status' => 'stop', 'message' => $e instanceof PDOException ? 'Database problem: ' . db_error_hint($e) : $e->getMessage()];
    }
    return ['status' => 'stop', 'message' => 'Unknown request.'];
}
