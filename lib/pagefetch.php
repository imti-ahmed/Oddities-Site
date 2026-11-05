<?php
// Reads ONE job page from the internet and turns it into clean text for the AI.
// It is careful: it will not visit internal/private addresses, it follows only a few redirects, and it limits the size.

require_once __DIR__ . '/helpers.php';

// Is this a normal public web address? ($strict = also refuse addresses that point inside a private network)
function check_public_url(string $url, bool $strict): array
{
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true)) return [false, 'That is not a web link (it must start with http:// or https://).'];
    if (isset($p['user']) || isset($p['pass'])) return [false, 'Links with a username or password inside are not allowed.'];
    if (!$strict) return [true, ''];

    if (isset($p['port']) && !in_array((int)$p['port'], [80, 443], true)) return [false, 'Only normal web addresses are allowed (no custom ports).'];
    $host = strtolower($p['host']);
    if ($host === 'localhost' || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) return [false, 'That address is not a public website.'];
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) $ips[] = $host;
    else {
        foreach ((array)@gethostbynamel($host) as $ip) $ips[] = $ip;
        foreach ((array)@dns_get_record($host, DNS_AAAA) as $r) if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
    }
    if (!$ips) return [false, 'Could not find that website.'];
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return [false, 'That address is not a public website.'];
    }
    return [true, ''];
}

// Makes two links to the same job look the same: no #fragment, no tracking parameters (utm_*, ref, gh_src...), no trailing slash.
function normalize_job_url(string $url): string
{
    $p = parse_url(trim($url));
    if (!$p || empty($p['host'])) return trim($url);
    $query = '';
    if (!empty($p['query'])) {
        parse_str($p['query'], $q);
        foreach (array_keys($q) as $k) if (preg_match('/^(utm_.*|ref|ref_src|source|src|fbclid|gclid|gh_src|lever-source.*|ashby_.*src.*|trk|trackingid)$/i', (string)$k)) unset($q[$k]);
        ksort($q);
        $query = $q ? '?' . http_build_query($q) : '';
    }
    $path = rtrim($p['path'] ?? '', '/');
    return strtolower($p['scheme'] ?? 'https') . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path . $query;
}

// Downloads the page. Returns ['ok' => bool, 'error' => string, 'html' => string, 'final_url' => string]
function download_page(string $url, bool $strict, int $maxBytes = 2500000): array
{
    $current = $url;
    for ($hop = 0; $hop < 6; $hop++) {
        [$ok, $why] = check_public_url($current, $strict);
        if (!$ok) return ['ok' => false, 'error' => $why, 'html' => '', 'final_url' => $current];

        $ch = curl_init($current);
        $headers = []; $body = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 18, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; JobBoardBot/1.0; reads one job page when asked)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5', 'Accept-Language: en'],
            CURLOPT_HEADERFUNCTION => function ($c, $line) use (&$headers) { $t = trim($line); if (strpos($t, ':') !== false) { [$k, $v] = explode(':', $t, 2); $headers[strtolower(trim($k))] = trim($v); } return strlen($line); },
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) use (&$body, $maxBytes) { $body .= $chunk; return strlen($body) > $maxBytes ? 0 : strlen($chunk); },   // stop if it gets too big
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        if ($code === 0 && strlen($body) <= $maxBytes) return ['ok' => false, 'error' => 'Could not open that page' . ($err ? " ($err)" : '') . '.', 'html' => '', 'final_url' => $current];

        if (in_array($code, [301, 302, 303, 307, 308], true) && !empty($headers['location'])) {   // follow the redirect ourselves, checking each step
            $next = $headers['location'];
            if (!preg_match('#^https?://#i', $next)) {
                $b = parse_url($current);
                $next = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '') . ($next[0] === '/' ? $next : '/' . $next);
            }
            $current = $next;
            continue;
        }
        if ($code >= 400) return ['ok' => false, 'error' => "That page answered with an error (HTTP $code). It may be closed, removed or blocking visitors.", 'html' => '', 'final_url' => $current];
        $type = strtolower($headers['content-type'] ?? '');
        if ($type !== '' && !preg_match('#text/html|application/xhtml|text/plain#', $type)) return ['ok' => false, 'error' => 'That link is not a web page.', 'html' => '', 'final_url' => $current];
        return ['ok' => true, 'error' => '', 'html' => $body, 'final_url' => $current];
    }
    return ['ok' => false, 'error' => 'That link redirects too many times.', 'html' => '', 'final_url' => $current];
}

// Pulls the job details many sites hide in the page for Google ("JobPosting" structured data).
function extract_job_posting(string $html): array
{
    $found = [];
    if (!preg_match_all('#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) return [];
    $walk = function ($node) use (&$walk, &$found) {
        if (!is_array($node)) return;
        $type = $node['@type'] ?? null;
        if (($type === 'JobPosting') || (is_array($type) && in_array('JobPosting', $type, true))) $found[] = $node;
        foreach ($node as $v) if (is_array($v)) $walk($v);
    };
    foreach ($m[1] as $json) $walk(json_decode(html_entity_decode(trim($json)), true));
    if (!$found) return [];
    $j = $found[0];
    $loc = [];
    foreach ((array)($j['jobLocation'] ?? []) as $l) {
        if (!is_array($l)) continue;
        $a = $l['address'] ?? $l;
        $loc[] = trim(implode(', ', array_filter([$a['addressLocality'] ?? '', $a['addressRegion'] ?? '', is_array($a['addressCountry'] ?? null) ? ($a['addressCountry']['name'] ?? '') : ($a['addressCountry'] ?? '')])));
    }
    $org = $j['hiringOrganization'] ?? '';
    return array_filter([
        'title' => is_string($j['title'] ?? null) ? $j['title'] : null,
        'company' => is_array($org) ? ($org['name'] ?? null) : (is_string($org) ? $org : null),
        'locations' => array_values(array_filter($loc)),
        'remote_type' => $j['jobLocationType'] ?? null,                 // "TELECOMMUTE" means remote
        'applicant_location_requirements' => isset($j['applicantLocationRequirements']) ? json_encode($j['applicantLocationRequirements'], JSON_UNESCAPED_UNICODE) : null,
        'date_posted' => $j['datePosted'] ?? null,
        'valid_through' => $j['validThrough'] ?? null,
        'employment_type' => $j['employmentType'] ?? null,
        // the posting's own description (often HTML-in-text); helps when the visible page loads its text with JavaScript
        'description' => isset($j['description']) && is_string($j['description'])
            ? mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($j['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8')))), 0, 3000) : null,
    ], fn($v) => $v !== null && $v !== [] && $v !== '');
}

// Turns the page into clean readable text (no menus, scripts or styles), cut to a safe length.
function page_to_text(string $html, int $maxChars): array
{
    if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8, ISO-8859-1');
    $title = ''; $desc = '';
    if (preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m)) $title = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (preg_match('#<meta[^>]+(?:name|property)=["\'](?:description|og:description)["\'][^>]+content=["\']([^"\']*)#i', $html, $m)) $desc = trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_COMPACT);
    foreach (['script', 'style', 'noscript', 'svg', 'iframe', 'nav', 'footer', 'form', 'template'] as $tag) {
        $nodes = $doc->getElementsByTagName($tag);
        for ($i = $nodes->length - 1; $i >= 0; $i--) { $n = $nodes->item($i); if ($n && $n->parentNode) $n->parentNode->removeChild($n); }
    }
    $body = $doc->getElementsByTagName('body')->item(0);
    $text = $body ? $body->textContent : $doc->textContent;
    $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
    $text = preg_replace('/\s*\n\s*/', "\n", trim($text));
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    if (mb_strlen($text) > $maxChars) $text = mb_substr($text, 0, $maxChars) . "\n[page text cut here]";
    return ['title' => $title, 'description' => $desc, 'text' => $text];
}

// Everything the AI needs about one link.
function read_job_page(string $url, int $maxChars, bool $strict): array
{
    $d = download_page($url, $strict);
    if (!$d['ok']) return ['ok' => false, 'error' => $d['error']];
    $page = page_to_text($d['html'], $maxChars);
    return ['ok' => true, 'final_url' => $d['final_url'], 'page_title' => $page['title'], 'description' => $page['description'],
            'structured' => extract_job_posting($d['html']), 'text' => $page['text'], 'html' => $d['html']];
}


// Turns a link found on a page ("/jobs/12", "../x", "//host/y") into a full address.
function absolute_url(string $base, string $href): string
{
    $href = trim($href);
    if ($href === '' || $href[0] === '#') return '';
    if (preg_match('#^https?://#i', $href)) return $href;
    $b = parse_url($base);
    if (!$b || empty($b['host'])) return '';
    $origin = ($b['scheme'] ?? 'https') . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($href, '//')) return ($b['scheme'] ?? 'https') . ':' . $href;
    if ($href[0] === '/') return $origin . $href;
    if ($href[0] === '?') return $origin . ($b['path'] ?? '/') . $href;
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href)) return '';            // mailto:, tel:, javascript: ...
    $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    $path = $dir . $href;
    $out = [];
    foreach (explode('/', parse_url($path, PHP_URL_PATH) ?: '') as $seg) { if ($seg === '..') array_pop($out); elseif ($seg !== '.') $out[] = $seg; }
    $q = parse_url($path, PHP_URL_QUERY);
    return $origin . implode('/', $out) . ($q ? '?' . $q : '');
}

// Every link on a job-list page that could lead to one job: [['url' => ..., 'text' => ...], ...] (at most $max).
function extract_page_links(string $html, string $pageUrl, int $max = 220): array
{
    if (!mb_check_encoding($html, 'UTF-8')) $html = mb_convert_encoding($html, 'UTF-8', 'UTF-8, ISO-8859-1');
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    @$doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $self = normalize_job_url($pageUrl);
    $junkText = '/^(home|about|contact|login|log in|sign in|sign up|register|pricing|blog|privacy|terms|cookies?|faq|help|post a job|post job|advertise|subscribe|newsletter|next|previous|prev|more|load more|see all|view all|all jobs|jobs|careers|search|filter|rss|menu|skip to content)\b/i';
    $skipHost = '/(^|\.)(twitter|x|facebook|instagram|linkedin|youtube|tiktok|pinterest|reddit|medium|github|apple|google|t)\.(com|co)$/i';
    $found = [];
    foreach ($doc->getElementsByTagName('a') as $a) {
        $url = absolute_url($pageUrl, (string)$a->getAttribute('href'));
        if ($url === '' || !preg_match('#^https?://#i', $url)) continue;
        $p = parse_url($url);
        if (empty($p['host']) || preg_match($skipHost, $p['host'])) continue;
        if (preg_match('/\.(jpe?g|png|gif|svg|webp|css|js|pdf|zip|ico|xml|json)(\?|$)/i', $p['path'] ?? '')) continue;
        $key = normalize_job_url($url);
        if ($key === $self) continue;
        $text = trim(preg_replace('/\s+/u', ' ', $a->textContent));
        if ($text === '') $text = trim((string)($a->getAttribute('title') ?: $a->getAttribute('aria-label')));
        if (mb_strlen($text) < 4 || preg_match($junkText, $text)) continue;
        $text = mb_substr($text, 0, 140);
        if (!isset($found[$key]) || mb_strlen($text) > mb_strlen($found[$key]['text'])) $found[$key] = ['url' => $url, 'text' => $text];
        if (count($found) >= $max) break;
    }
    return array_values($found);
}
