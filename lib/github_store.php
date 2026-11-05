<?php
// Saves new jobs into data/jobs.json.
//
//  * On Vercel: Vercel can't keep files, so we SAVE by committing the changed data/jobs.json to GitHub
//    (needs the JOBS_GITHUB_TOKEN setting). Vercel then rebuilds the site by itself, about a minute later.
//  * On your own computer (no token): the local file is edited directly.

require_once __DIR__ . '/helpers.php';

const JOBS_FILE_PATH = __DIR__ . '/../data/jobs.json';

function jobs_store_mode(): string   // 'github' | 'file' | 'none'
{
    if (env('JOBS_GITHUB_TOKEN') !== '') return 'github';
    if (env('VERCEL') === '') return 'file';
    return 'none';
}

function gh_target(): array
{
    $repo = env('JOBS_GITHUB_REPO') ?: (env('VERCEL_GIT_REPO_OWNER') . '/' . env('VERCEL_GIT_REPO_SLUG'));
    if ($repo === '/') throw new RuntimeException('Cannot tell which GitHub repository to save to. Set JOBS_GITHUB_REPO (like "yourname/Design-Jobs").');
    $branch = env('JOBS_GITHUB_BRANCH') ?: env('VERCEL_GIT_COMMIT_REF') ?: 'main';   // a preview deploy saves to its own branch, production saves to main
    $base   = rtrim(env('JOBS_GITHUB_API') ?: 'https://api.github.com', '/');
    return ["$base/repos/$repo/contents/data/jobs.json", $branch];
}

function gh_call(string $method, string $url, ?array $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . env('JOBS_GITHUB_TOKEN'), 'Accept: application/vnd.github+json', 'User-Agent: jobboard-intake', 'Content-Type: application/json'],
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    if ($raw === false) throw new RuntimeException('Could not reach GitHub: ' . curl_error($ch));
    return [(int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode((string)$raw, true) ?: []];
}

// The current list of jobs. On Vercel this asks GitHub (the very latest), not the copy shipped with the site.
function jobs_read_fresh(): array
{
    if (jobs_store_mode() === 'github') {
        [$url, $branch] = gh_target();
        [$code, $d] = gh_call('GET', $url . '?ref=' . rawurlencode($branch));
        if ($code === 404) return [];
        if ($code === 401 || $code === 403) throw new RuntimeException('GitHub refused the token (HTTP ' . $code . '). Check that JOBS_GITHUB_TOKEN is correct and can write to this repository (Contents: Read and write).');
        if ($code !== 200) throw new RuntimeException("GitHub said $code when reading the jobs: " . ($d['message'] ?? 'unknown error'));
        $jobs = json_decode(base64_decode(str_replace("\n", '', $d['content'] ?? '')), true);
        return is_array($jobs) ? $jobs : [];
    }
    $jobs = json_decode((string)@file_get_contents(JOBS_FILE_PATH), true);
    return is_array($jobs) ? $jobs : [];
}

// Adds ONE job. $isDuplicate lets the caller refuse at the last moment (two people adding at once).
// Returns 'added' or 'duplicate'.
function jobs_append(array $job, callable $isDuplicate, string $message): string
{
    $mode = jobs_store_mode();
    if ($mode === 'none') throw new RuntimeException('Saving is not set up. Add JOBS_GITHUB_TOKEN in Vercel (a GitHub token that can write to this repository), then redeploy.');
    $encode = fn(array $list) => json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    for ($try = 0; $try < 3; $try++) {
        if ($mode === 'file') {
            $fp = fopen(JOBS_FILE_PATH, 'c+');
            if (!$fp || !flock($fp, LOCK_EX)) throw new RuntimeException('Could not open data/jobs.json for writing.');
            $list = json_decode((string)stream_get_contents($fp), true);
            $list = is_array($list) ? $list : [];
            if ($isDuplicate($list)) { flock($fp, LOCK_UN); fclose($fp); return 'duplicate'; }
            array_unshift($list, $job);
            ftruncate($fp, 0); rewind($fp); fwrite($fp, $encode($list)); flock($fp, LOCK_UN); fclose($fp);
            return 'added';
        }

        [$url, $branch] = gh_target();
        [$code, $d] = gh_call('GET', $url . '?ref=' . rawurlencode($branch));
        $sha  = $code === 200 ? ($d['sha'] ?? null) : null;
        $list = $code === 200 ? (json_decode(base64_decode(str_replace("\n", '', $d['content'] ?? '')), true) ?: []) : [];
        if ($code !== 200 && $code !== 404) throw new RuntimeException("GitHub said $code when reading the jobs: " . ($d['message'] ?? 'unknown error'));
        if ($isDuplicate($list)) return 'duplicate';
        array_unshift($list, $job);                       // newest first in the file
        $put = ['message' => $message, 'branch' => $branch, 'content' => base64_encode($encode($list))];
        if ($sha) $put['sha'] = $sha;
        [$code, $d] = gh_call('PUT', $url, $put);
        if ($code === 200 || $code === 201) return 'added';
        if ($code === 409 || $code === 422) continue;     // someone saved at the same moment: read again and retry
        if ($code === 401 || $code === 403) throw new RuntimeException('GitHub refused to save (HTTP ' . $code . '). Check that JOBS_GITHUB_TOKEN can write to this repository (Contents: Read and write).');
        throw new RuntimeException("GitHub said $code when saving: " . ($d['message'] ?? 'unknown error'));
    }
    throw new RuntimeException('Could not save because the file kept changing. Try again.');
}
