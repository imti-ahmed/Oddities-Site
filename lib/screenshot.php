<?php
// Takes a screenshot of a website and keeps it in the Neon bucket ("uploads"), so every Portfolio and Tool card has a picture of its own.
//
//   screenshot_capture($url)       -> picture bytes (JPEG) from the free Microlink screenshot service, or null
//   screenshot_save($kind, $id, $url) -> takes the screenshot, stores it in the bucket, saves its address in the table (column "image")
//
// The bucket is private, so the picture reaches visitors through /api/img.php. If anything fails the card simply keeps showing a live
// screenshot from the free WordPress mShots service (see directory_image() in lib/directory.php), so a card never has a hole in it.

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/directory.php';

function screenshot_capture(string $url): ?string
{
    $api = 'https://api.microlink.io/?' . http_build_query([
        'url' => $url, 'screenshot' => 'true', 'meta' => 'false', 'waitForTimeout' => 1500,
        'viewport.width' => 1280, 'viewport.height' => 800, 'viewport.deviceScaleFactor' => 1, 'screenshot.type' => 'jpeg',
    ]);
    $ch = curl_init($api);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => 'OdditiesBot/1.0']);
    $json = json_decode((string)curl_exec($ch), true);
    $img = is_array($json) ? ($json['data']['screenshot']['url'] ?? '') : '';
    if (!is_string($img) || !preg_match('#^https://#', $img)) return null;
    $c2 = curl_init($img);
    curl_setopt_array($c2, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2]);
    $bytes = (string)curl_exec($c2);
    return ($bytes !== '' && (int)curl_getinfo($c2, CURLINFO_RESPONSE_CODE) === 200 && strlen($bytes) > 2000) ? $bytes : null;
}

// $kind = 'tools' | 'portfolios', $id = the row number. Returns the saved picture address, or null when it did not work.
function screenshot_save(string $kind, int $id, string $url): ?string
{
    if (!in_array($kind, directory_kinds(), true) || !storage_enabled()) return null;
    $bytes = screenshot_capture($url);
    if ($bytes === null) return null;
    $key = "shots/$kind/$id.jpg";
    if (!storage_put($key, $bytes, 'image/jpeg')) return null;
    $addr = '/api/img.php?k=' . $key;
    $st = db()->prepare("UPDATE $kind SET image = :i WHERE id = :id");
    $st->execute([':i' => $addr, ':id' => $id]);
    return $addr;
}

// Takes screenshots for the entries that have none yet. Returns ['done' => n, 'failed' => n, 'left' => n].
function screenshots_fill_missing(int $max = 3): array
{
    $out = ['done' => 0, 'failed' => 0, 'left' => 0];
    foreach (directory_kinds() as $kind) {
        $rows = db()->query("SELECT id, link FROM $kind WHERE COALESCE(image, '') = '' AND dead_at IS NULL ORDER BY id")->fetchAll();
        foreach ($rows as $r) {
            if ($out['done'] + $out['failed'] >= $max) { $out['left']++; continue; }
            if (screenshot_save($kind, (int)$r['id'], (string)$r['link'])) $out['done']++; else $out['failed']++;
        }
    }
    return $out;
}
