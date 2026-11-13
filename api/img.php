<?php
// Hands out a picture from the Neon bucket (the bucket is private, so visitors cannot reach it directly).
// Only files under shots/tools/ and shots/portfolios/ can be asked for: /api/img.php?k=shots/tools/12.jpg
require_once __DIR__ . '/../lib/storage.php';

$k = (string)($_GET['k'] ?? '');
if (!preg_match('#^shots/(tools|portfolios)/\d+\.(jpg|png)$#', $k)) { http_response_code(404); exit; }
$f = storage_get($k);
if (!$f) { http_response_code(404); exit; }
header('Content-Type: ' . (str_ends_with($k, '.png') ? 'image/png' : 'image/jpeg'));
header('Cache-Control: public, max-age=86400, s-maxage=86400');
echo $f['body'];
