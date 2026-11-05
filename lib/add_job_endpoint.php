<?php
// The door the /admin card knocks on (reached through api/index.php?add=1): "here is a job link, please check it and list it".
// No password: anyone who knows the /admin address can use it (by choice).

require_once __DIR__ . '/intake.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['status' => 'error', 'message' => 'Use the card on the /admin page.']);

$in = json_decode((string)file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];

$url = trim((string)($in['url'] ?? ''));
if ($url === '' || mb_strlen($url) > 1500) reply(400, ['status' => 'error', 'message' => 'Paste a job link first.']);

set_time_limit(55);
reply(200, intake_job($url, 'admin', empty($in['single'])));   // single = true when the card is checking one job from a list
