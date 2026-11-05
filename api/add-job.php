<?php
// The door the /admin card knocks on: "here is a job link, please check it and list it".
// It only opens for someone who knows ADMIN_PASSWORD, because it spends AI time and saves to your repository.

require_once __DIR__ . '/../lib/intake.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['status' => 'error', 'message' => 'Use the card on the /admin page.']);

$secret = env('ADMIN_PASSWORD');
if ($secret === '') reply(503, ['status' => 'error', 'message' => 'The admin password is not set yet. Add ADMIN_PASSWORD in Vercel > Settings > Environment Variables, then redeploy.']);

$in = json_decode((string)file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];
$given = (string)($_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? ($in['password'] ?? ''));
if (!hash_equals($secret, $given)) { usleep(800000); reply(401, ['status' => 'error', 'message' => 'Wrong password.']); }   // the pause slows down password guessing

$url = trim((string)($in['url'] ?? ''));
if ($url === '' || mb_strlen($url) > 1500) reply(400, ['status' => 'error', 'message' => 'Paste a job link first.']);

set_time_limit(55);
reply(200, intake_job($url, 'admin'));
