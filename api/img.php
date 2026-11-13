<?php
// Hands out a picture from the Neon bucket (the bucket is private, so visitors cannot reach it directly).
// Only files under shots/tools/ and shots/portfolios/ can be asked for: /api/img.php?k=shots/tools/12.jpg
require_once __DIR__ . '/../lib/storage.php';

$k = (string)($_GET['k'] ?? '');
if (!preg_match('#^shots/(tools|portfolios)/\d+\.(jpg|png)$#', $k)) { http_response_code(404); exit; }
$f = storage_get($k);
if (!$f) { http_response_code(404); exit; }
header('Content-Type: ' . (str_ends_with($k, '.png') ? 'image/png' : 'image/jpeg'));
// Short cache plus an ETag: a picture replaced in the bucket shows up within a minute, and unchanged ones still answer 304 (no download).
$etag = '"' . md5($f['body']) . '"';
header('Cache-Control: public, max-age=0, s-maxage=60, stale-while-revalidate=300, must-revalidate');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
echo $f['body'];
