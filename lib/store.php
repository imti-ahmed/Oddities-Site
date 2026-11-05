<?php
// Where the jobs live.
//
//  * With DATABASE_URL set (Vercel): the Neon database. Saving a job is instant and needs no new build of the site.
//  * Without it (your own computer): the file data/jobs.json, edited directly.
//  * On Vercel with no DATABASE_URL: saving is refused with a clear message (the site itself still shows the file's jobs).

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';

const JOBS_FILE_PATH = __DIR__ . '/../data/jobs.json';

function jobs_store_mode(): string   // 'db' | 'file' | 'none'
{
    if (db_enabled()) return 'db';
    if (env('VERCEL') === '') return 'file';
    return 'none';
}

function jobs_read_file(): array
{
    $jobs = json_decode((string)@file_get_contents(JOBS_FILE_PATH), true);
    return is_array($jobs) ? $jobs : [];
}

// The current list of jobs for the DUPLICATE CHECKS, straight from where they are kept. It includes deleted (hidden) jobs, marked 'hidden' => true,
// so a job you deleted is never checked or added again.
function jobs_read_fresh(): array
{
    if (jobs_store_mode() === 'db') {
        try { return db_all_jobs(true); }
        catch (Throwable $e) { throw new RuntimeException('Could not read the database: ' . db_error_hint($e)); }
    }
    return array_map(fn($j) => !empty($j['deleted_at']) ? $j + ['hidden' => true] : $j, jobs_read_file());
}

// The website uses this one (without the deleted jobs): if the database is down, show the jobs from the file instead of an empty site.
function jobs_for_page(): array
{
    if (jobs_store_mode() === 'db') {
        try { return db_all_jobs(false); } catch (Throwable $e) { /* fall through to the file */ }
    }
    return array_values(array_filter(jobs_read_file(), fn($j) => empty($j['deleted_at'])));
}

function db_error_hint(Throwable $e): string
{
    $m = $e->getMessage();
    if (preg_match('/password authentication failed|authentication/i', $m)) return 'the database refused the password. Check DATABASE_URL in Vercel.';
    if (preg_match('/could not translate host|could not connect|timeout|timed out|Connection refused/i', $m)) return 'could not reach the database. Is the Neon project running, and is DATABASE_URL right?';
    return mb_substr(preg_replace('/\s+/', ' ', $m), 0, 200);
}

// Adds ONE job. $isDuplicate lets the caller refuse at the last moment. Returns 'added' or 'duplicate'.
function jobs_append(array $job, callable $isDuplicate, string $message = ''): string
{
    $mode = jobs_store_mode();
    if ($mode === 'none') throw new RuntimeException('Saving is not set up. Add DATABASE_URL in Vercel (the Neon connection string), then redeploy.');

    if ($mode === 'db') {
        try {
            if ($isDuplicate(db_all_jobs(true))) return 'duplicate';        // (deleted jobs count: they are never added again)
            return db_insert_job($job) ? 'added' : 'duplicate';      // the database also refuses the same link twice
        } catch (Throwable $e) {
            throw new RuntimeException('Could not save to the database: ' . db_error_hint($e));
        }
    }

    $fp = fopen(JOBS_FILE_PATH, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Could not open data/jobs.json for writing.');
    $list = json_decode((string)stream_get_contents($fp), true);
    $list = is_array($list) ? $list : [];
    if ($isDuplicate($list)) { flock($fp, LOCK_UN); fclose($fp); return 'duplicate'; }
    array_unshift($list, $job);
    ftruncate($fp, 0); rewind($fp);
    fwrite($fp, json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    flock($fp, LOCK_UN); fclose($fp);
    return 'added';
}

// Brings a deleted (hidden) job back onto the website. Returns true if there was one.
function jobs_restore(string $url): bool
{
    require_once __DIR__ . '/pagefetch.php';
    if (jobs_store_mode() === 'db') return db_restore_job($url);
    if (jobs_store_mode() === 'none') throw new RuntimeException('Saving is not set up. Add DATABASE_URL in Vercel (the Neon connection string), then redeploy.');
    $fp = fopen(JOBS_FILE_PATH, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Could not open data/jobs.json for writing.');
    $list = json_decode((string)stream_get_contents($fp), true);
    $list = is_array($list) ? $list : [];
    $key = normalize_job_url($url); $found = false;
    foreach ($list as $i => $j) if (!empty($j['deleted_at']) && normalize_job_url((string)($j['url'] ?? '')) === $key) { unset($list[$i]['deleted_at']); $found = true; }
    if ($found) { ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"); }
    flock($fp, LOCK_UN); fclose($fp);
    return $found;
}

// "Deletes" one job (admin only): it is hidden from the website but kept, so it is never checked or added again. Returns true if the job was found.
function jobs_delete(string $url): bool
{
    require_once __DIR__ . '/pagefetch.php';
    $mode = jobs_store_mode();
    if ($mode === 'none') throw new RuntimeException('Saving is not set up. Add DATABASE_URL in Vercel (the Neon connection string), then redeploy.');
    if ($mode === 'db') {
        try { return db_delete_job($url); }
        catch (Throwable $e) { throw new RuntimeException('Could not delete from the database: ' . db_error_hint($e)); }
    }
    $fp = fopen(JOBS_FILE_PATH, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Could not open data/jobs.json for writing.');
    $list = json_decode((string)stream_get_contents($fp), true);
    $list = is_array($list) ? $list : [];
    $key = normalize_job_url($url); $found = false;
    foreach ($list as $i => $j) {
        if (normalize_job_url((string)($j['url'] ?? '')) !== $key) continue;
        $list[$i]['deleted_at'] = date('c'); unset($list[$i]['highlighted']); $found = true;
    }
    if ($found) { ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"); }
    flock($fp, LOCK_UN); fclose($fp);
    return $found;
}

// Highlight (or un-highlight) one job for everyone (the Highlighted tab). Returns true if the job was found.
function jobs_set_highlight(string $url, bool $on): bool
{
    require_once __DIR__ . '/pagefetch.php';
    $mode = jobs_store_mode();
    if ($mode === 'none') throw new RuntimeException('Saving is not set up. Add DATABASE_URL in Vercel (the Neon connection string), then redeploy.');
    if ($mode === 'db') {
        try { return db_set_highlight($url, $on); }
        catch (Throwable $e) { throw new RuntimeException('Could not save to the database: ' . db_error_hint($e)); }
    }
    $fp = fopen(JOBS_FILE_PATH, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Could not open data/jobs.json for writing.');
    $list = json_decode((string)stream_get_contents($fp), true);
    $list = is_array($list) ? $list : [];
    $key = normalize_job_url($url); $found = false;
    foreach ($list as $i => $j) {
        if (normalize_job_url((string)($j['url'] ?? '')) !== $key) continue;
        if ($on) $list[$i]['highlighted'] = true; else unset($list[$i]['highlighted']);
        $found = true;
    }
    if ($found) { ftruncate($fp, 0); rewind($fp); fwrite($fp, json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"); }
    flock($fp, LOCK_UN); fclose($fp);
    return $found;
}
