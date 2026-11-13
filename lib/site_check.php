<?php
// "Is this website still working?" for every Portfolio and Tool. NO AI: only web requests. Run by scripts/check_sites.php (every day; each site is only
// asked about every 2 weeks, see 'recheck_days' in config/directory.php).
//
// Careful on purpose, so a hiccup never kills a good site:
//  * Only clear "gone" signals count: the page says 404 / 410, or the website address no longer exists at all.
//    A blocked, slow, broken (5xx) or unreachable site is "unknown": nothing changes, and it is asked again in two weeks.
//  * A site only moves to the Dead Zone after 'dead_after' (2) "gone" answers in a row. After a first "gone" it is asked again the next day.

require_once __DIR__ . '/directory.php';

function sites_cfg(): array { return require __DIR__ . '/../config/directory.php'; }

function sites_due_where(): string
{
    $days = max(1, (int)sites_cfg()['recheck_days']);
    return "dead_at IS NULL AND (last_checked_at IS NULL
        OR (check_fails = 0 AND last_checked_at < now() - interval '$days days')
        OR (check_fails > 0 AND last_checked_at < now() - interval '20 hours'))";
}

function sites_remaining(): int
{
    $n = 0;
    foreach (directory_kinds() as $t) $n += (int)db()->query("SELECT count(*) FROM $t WHERE " . sites_due_where())->fetchColumn();
    return $n;
}

// Asks every address at once. Returns key => ['code' => HTTP status (0 = no answer), 'errno' => curl error number].
function sites_probe_many(array $urls): array
{
    $mh = curl_multi_init(); $hs = [];
    foreach ($urls as $k => $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; OdditiesBot/1.0; checks that a listed website still works)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,*/*;q=0.5', 'Accept-Language: en'],
            CURLOPT_WRITEFUNCTION => function ($c, $chunk) { return 0; },   // the status is all we need: stop after the first bytes
        ]);
        curl_multi_add_handle($mh, $ch); $hs[$k] = $ch;
    }
    $errs = [];                                                       // (with several requests at once, curl reports each one's error number here)
    do {
        $st = curl_multi_exec($mh, $running); if ($running) curl_multi_select($mh, 1.0);
        while ($info = curl_multi_info_read($mh)) $errs[spl_object_id($info['handle'])] = (int)$info['result'];
    } while ($running && $st === CURLM_OK);
    $out = [];
    foreach ($hs as $k => $ch) {
        $out[$k] = ['code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'errno' => $errs[spl_object_id($ch)] ?? 0];
        curl_multi_remove_handle($mh, $ch);
    }
    @curl_multi_close($mh);
    return $out;
}

// One round: checks up to $n due sites. Returns a summary.
function sites_check_batch(int $n = 40): array
{
    db_create_directory_tables();
    $cfg = sites_cfg(); $deadAfter = max(1, (int)$cfg['dead_after']);
    $out = ['checked' => 0, 'alive' => 0, 'gone_signal' => 0, 'unknown' => 0, 'dead' => 0, 'dead_sites' => []];
    $rows = [];
    foreach (directory_kinds() as $t) {
        foreach (db()->query("SELECT id, name, link, check_fails FROM $t WHERE " . sites_due_where() . ' ORDER BY last_checked_at NULLS FIRST, id LIMIT ' . max(1, $n))->fetchAll() as $r) {
            $rows[] = $r + ['table' => $t];
        }
    }
    $rows = array_slice($rows, 0, max(1, $n));
    if (!$rows) return $out + ['remaining' => 0];

    $urls = []; foreach ($rows as $i => $r) $urls[$i] = $r['link'];
    $res = sites_probe_many($urls);

    foreach ($rows as $i => $r) {
        $p = $res[$i]; $verdict = 'unknown'; $why = '';
        if (in_array($p['code'], [404, 410], true)) { $verdict = 'gone'; $why = 'The page is gone (HTTP ' . $p['code'] . ')'; }
        elseif ($p['code'] === 0 && $p['errno'] === 6) { $verdict = 'gone'; $why = 'The website address no longer exists'; }
        elseif ($p['code'] >= 200 && $p['code'] < 400) $verdict = 'alive';                // anything else (403, 429, 5xx, slow) stays "unknown"

        $fails = (int)$r['check_fails']; $dead = false;
        if ($verdict === 'alive') { $fails = 0; $out['alive']++; }
        elseif ($verdict === 'gone') { $fails++; $out['gone_signal']++; if ($fails >= $deadAfter) { $dead = true; $out['dead']++; $out['dead_sites'][] = ['name' => $r['name'], 'kind' => $r['table'], 'reason' => $why]; } }
        else $out['unknown']++;
        $out['checked']++;
        $st = db()->prepare("UPDATE {$r['table']} SET last_checked_at = now(), check_fails = :f,
            dead_at = CASE WHEN :d THEN now() ELSE dead_at END, dead_reason = CASE WHEN :d THEN :why ELSE dead_reason END WHERE id = :id");
        $st->execute([':f' => $fails, ':d' => $dead ? 'true' : 'false', ':why' => $why, ':id' => $r['id']]);
    }
    return $out + ['remaining' => sites_remaining()];
}
