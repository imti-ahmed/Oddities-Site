<?php
// Where the jobs are saved. Everything else just calls load_jobs() / save_jobs().
//
//  * On your computer:  data/jobs.json is edited directly.
//  * On Vercel:         Vercel can't save files, so we save by committing the new data/jobs.json
//                       to GitHub (needs JOBS_GITHUB_TOKEN). Vercel then rebuilds the site by itself.

require_once __DIR__ . '/helpers.php';

function env(string $name): string
{
    $v = getenv($name);
    if ($v === false || $v === '') $v = $_ENV[$name] ?? $_SERVER[$name] ?? '';
    return (string)$v;
}

const JOBS_FILE = __DIR__ . '/../data/jobs.json';

function store_mode(): string   // 'github' | 'file' | 'none'
{
    if (env('JOBS_GITHUB_TOKEN') !== '') return 'github';
    if (env('VERCEL') === '')       return 'file';
    return 'none';                  // on Vercel with no token: nothing can be saved
}

function github_target(): array
{
    $repo = env('JOBS_GITHUB_REPO') ?: (env('VERCEL_GIT_REPO_OWNER') . '/' . env('VERCEL_GIT_REPO_SLUG'));
    if ($repo === '/') throw new RuntimeException('Set JOBS_GITHUB_REPO (like "yourname/Design-Jobs") in the environment variables.');
    $branch = env('JOBS_GITHUB_BRANCH') ?: env('VERCEL_GIT_COMMIT_REF') ?: 'main';
    $base   = rtrim(env('JOBS_GITHUB_API') ?: 'https://api.github.com', '/');
    return ["$base/repos/$repo/contents/data/jobs.json", $branch];
}

function github_call(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . env('JOBS_GITHUB_TOKEN'), 'Accept: application/vnd.github+json',
            'User-Agent: design-jobs-admin', 'Content-Type: application/json',
        ],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Could not reach GitHub: ' . curl_error($ch));
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    return [$code, json_decode((string)$raw, true) ?: []];
}

// Returns [jobs, sha]. sha is GitHub's "version stamp" of the file (needed to save changes safely).
function github_read(): array
{
    [$url, $branch] = github_target();
    [$code, $data] = github_call('GET', $url . '?ref=' . rawurlencode($branch));
    if ($code === 404) return [[], null];
    if ($code !== 200) throw new RuntimeException("GitHub said $code when reading jobs: " . ($data['message'] ?? 'unknown error'));
    $jobs = json_decode(base64_decode(str_replace("\n", '', $data['content'] ?? '')), true);
    return [is_array($jobs) ? $jobs : [], $data['sha'] ?? null];
}

// Reads the jobs. $fresh = true on Vercel means "ask GitHub" (the latest), instead of the copy shipped with the site.
function load_jobs(bool $fresh = false): array
{
    if ($fresh && store_mode() === 'github') return github_read()[0];
    $jobs = json_decode((string)@file_get_contents(JOBS_FILE), true);
    return is_array($jobs) ? $jobs : [];
}

// Changes the jobs. $change gets the current list and returns the new list.
// Done as "read, change, save" and retried once, so two quick edits don't overwrite each other.
function update_jobs(callable $change, string $message): void
{
    $mode = store_mode();
    if ($mode === 'none') throw new RuntimeException('Saving is not set up yet. Add JOBS_GITHUB_TOKEN in Vercel (see README).');

    for ($try = 0; $try < 2; $try++) {
        if ($mode === 'file') {
            $fp = fopen(JOBS_FILE, 'c+');
            if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Could not open data/jobs.json for writing.');
            $current = json_decode((string)stream_get_contents($fp), true);
            $new = $change(is_array($current) ? $current : []);
            ftruncate($fp, 0); rewind($fp);
            fwrite($fp, json_encode(array_values($new), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
            flock($fp, LOCK_UN); fclose($fp);
            return;
        }

        [$url, $branch] = github_target();
        [$jobs, $sha] = github_read();
        $new  = array_values($change($jobs));
        $body = [
            'message' => $message, 'branch' => $branch,
            'content' => base64_encode(json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"),
        ];
        if ($sha) $body['sha'] = $sha;
        [$code, $data] = github_call('PUT', $url, $body);
        if ($code === 200 || $code === 201) return;
        if ($code === 409 || $code === 422) continue;   // someone saved in between: read again and retry once
        throw new RuntimeException("GitHub said $code when saving: " . ($data['message'] ?? 'unknown error'));
    }
    throw new RuntimeException('Could not save (the file kept changing). Try again.');
}
