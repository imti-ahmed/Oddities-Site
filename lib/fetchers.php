<?php
// Each function below knows how to read ONE kind of job board.
// Every one returns a list of jobs shaped like:
//   ['title'=>..., 'location'=>..., 'url'=>..., 'posted_at'=>unix time or null]

require_once __DIR__ . '/helpers.php';

function fetch_json(string $url): array
{
    $r = http_get($url, 30);
    if ($r['error'] || $r['status'] !== 200) {
        throw new RuntimeException("Could not load $url (" . ($r['error'] ?: 'HTTP ' . $r['status']) . ')');
    }
    $data = json_decode($r['body'], true);
    if (!is_array($data)) throw new RuntimeException("Unexpected reply from $url");
    return $data;
}

// Greenhouse boards: boards.greenhouse.io/<token>
function fetch_greenhouse(array $s): array
{
    $data = fetch_json('https://boards-api.greenhouse.io/v1/boards/' . rawurlencode($s['token']) . '/jobs');
    $out = [];
    foreach ($data['jobs'] ?? [] as $j) {
        $out[] = [
            'title'     => $j['title'] ?? '',
            'location'  => $j['location']['name'] ?? '',
            'url'       => $j['absolute_url'] ?? '',
            'posted_at' => !empty($j['updated_at']) ? strtotime($j['updated_at']) : null,
        ];
    }
    return $out;
}

// Lever boards: jobs.lever.co/<token>
function fetch_lever(array $s): array
{
    $data = fetch_json('https://api.lever.co/v0/postings/' . rawurlencode($s['token']) . '?mode=json');
    $out = [];
    foreach ($data as $j) {
        $out[] = [
            'title'     => $j['text'] ?? '',
            'location'  => $j['categories']['location'] ?? '',
            'url'       => $j['hostedUrl'] ?? '',
            'posted_at' => isset($j['createdAt']) ? (int)($j['createdAt'] / 1000) : null,
        ];
    }
    return $out;
}

// Ashby boards: jobs.ashbyhq.com/<token>
function fetch_ashby(array $s): array
{
    $data = fetch_json('https://api.ashbyhq.com/posting-api/job-board/' . rawurlencode($s['token']));
    $out = [];
    foreach ($data['jobs'] ?? [] as $j) {
        if (isset($j['isListed']) && !$j['isListed']) continue;
        $out[] = [
            'title'     => $j['title'] ?? '',
            'location'  => $j['location'] ?? '',
            'url'       => $j['jobUrl'] ?? '',
            'posted_at' => !empty($j['publishedAt']) ? strtotime($j['publishedAt']) : null,
        ];
    }
    return $out;
}

// Any RSS or Atom feed (many job boards and company sites have one)
function fetch_rss(array $s): array
{
    $r = http_get($s['url'], 30);
    if ($r['error'] || $r['status'] !== 200) {
        throw new RuntimeException("Could not load feed {$s['url']} (" . ($r['error'] ?: 'HTTP ' . $r['status']) . ')');
    }
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($r['body'], 'SimpleXMLElement', LIBXML_NOCDATA);
    if (!$xml) throw new RuntimeException("Feed {$s['url']} is not valid RSS/Atom");

    $out = [];
    if (isset($xml->channel->item)) {                       // RSS
        foreach ($xml->channel->item as $i) {
            $out[] = [
                'title'     => trim((string)$i->title),
                'location'  => '',
                'url'       => trim((string)$i->link),
                'posted_at' => ($i->pubDate ?? null) ? strtotime((string)$i->pubDate) ?: null : null,
            ];
        }
    } elseif (isset($xml->entry)) {                         // Atom
        foreach ($xml->entry as $e) {
            $link = '';
            foreach ($e->link as $l) { $link = (string)$l['href']; if ((string)$l['rel'] !== 'self') break; }
            $out[] = [
                'title'     => trim((string)$e->title),
                'location'  => '',
                'url'       => $link,
                'posted_at' => strtotime((string)($e->published ?: $e->updated)) ?: null,
            ];
        }
    }
    return $out;
}

// Picks the right reader for a source from config/sources.php.
// Second value = true if the source gives us its COMPLETE list of open jobs
// (so a job missing from it is definitely gone).
function fetch_source(array $s): array
{
    switch ($s['type']) {
        case 'greenhouse': return [fetch_greenhouse($s), true];
        case 'lever':      return [fetch_lever($s), true];
        case 'ashby':      return [fetch_ashby($s), true];
        case 'rss':        return [fetch_rss($s), false];
    }
    throw new RuntimeException("Unknown source type '{$s['type']}'");
}
