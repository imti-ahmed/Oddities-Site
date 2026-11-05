<?php
// Small shared tools used by the website and the background scripts.

function h(?string $s): string   // makes text safe to show on a web page
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function time_ago(?int $ts): string
{
    if (!$ts) return 'never';
    $d = time() - $ts;
    if ($d < 90)      return 'just now';
    if ($d < 3600)    return floor($d / 60) . ' minutes ago';
    if ($d < 86400)   { $n = (int)floor($d / 3600);  return $n . ($n == 1 ? ' hour' : ' hours') . ' ago'; }
    if ($d < 86400*60){ $n = (int)floor($d / 86400); return $n . ($n == 1 ? ' day' : ' days') . ' ago'; }
    $n = (int)floor($d / (86400*30)); return $n . ' months ago';
}

function domain_of(string $url): string
{
    $host = parse_url($url, PHP_URL_HOST) ?: '';
    return preg_replace('/^www\./', '', $host);
}

function is_safe_url(string $url): bool   // only allow normal web links
{
    return (bool)preg_match('#^https?://#i', $url);
}

// Visits a web address. Returns status code, page text, the final address after redirects, and any error.
function http_get(string $url, int $timeout = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_ENCODING       => '',
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; DesignJobsBot/1.0; job link checker)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html,application/json,application/xml;q=0.9,*/*;q=0.8'],
        CURLOPT_MAXFILESIZE    => 5 * 1024 * 1024,
    ]);
    $body   = curl_exec($ch);
    $err    = $body === false ? curl_error($ch) : null;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final  = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    return ['status' => $status, 'body' => (string)$body, 'final_url' => $final, 'error' => $err];
}

function log_line(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . PHP_EOL;
}

// Finds the "ID part" of a job link, e.g. 1234567890 or a long code like 0a1b2c3d-....
// Used to notice when a dead job link quietly sends you to the company homepage instead.
function job_id_in_url(string $url): ?string
{
    $query = parse_url($url, PHP_URL_QUERY) ?: '';
    parse_str($query, $q);
    foreach (['gh_jid', 'ashby_jid', 'jid', 'job_id', 'jobId', 'id'] as $key) {
        if (!empty($q[$key]) && is_string($q[$key])) return $q[$key];
    }
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    foreach (array_reverse(explode('/', trim($path, '/'))) as $seg) {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $seg)) return $seg;   // UUID
        if (preg_match('/^\d{5,}$/', $seg)) return $seg;                                                         // long number
    }
    return null;
}
