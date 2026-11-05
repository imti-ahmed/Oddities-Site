<?php
// The door the /admin card knocks on (reached through api/index.php?add=1): "here is a job link, please check it and list it".
// No password: anyone who knows the /admin address can use it (by choice).

// Only JSON may leave this file. A PHP notice or "deprecated" warning (newer PHP versions have more of them) must never be
// printed in front of the answer, because the card could not read it. So: no error display, anything printed is thrown away,
// and a crash (out of time, out of memory) is turned into a readable message instead of a blank page.
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();
register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_PARSE], true)) return;
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) { http_response_code(200); header('Content-Type: application/json; charset=utf-8'); }
    $why = preg_match('/memory|time/i', $e['message']) ? 'the server ran out of ' . (stripos($e['message'], 'memory') !== false ? 'memory' : 'time') : 'a server error';
    echo json_encode(['status' => 'stop', 'message' => 'Stopped because ' . $why . ' (' . mb_substr(preg_replace('/\s+/', ' ', $e['message']), 0, 140) . '). Press the button again to continue.']);
});

require_once __DIR__ . '/intake.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $code, array $data): never
{
    while (ob_get_level()) ob_end_clean();      // throw away anything that was printed by accident
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['status' => 'error', 'message' => 'Use the card on the /admin page.']);

$in = json_decode((string)file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];

// the "Collect jobs" button on the card: read our company list and job sites, then check the jobs one at a time
$action = (string)($in['action'] ?? '');
if ($action === 'highlight') {                       // the star on /admin: highlight a job for everyone (the Highlighted tab)
    $u = trim((string)($in['url'] ?? ''));
    if ($u === '') reply(400, ['status' => 'error', 'message' => 'No job given.']);
    try { $found = jobs_set_highlight($u, !empty($in['on'])); }
    catch (Throwable $e) { reply(200, ['status' => 'error', 'message' => $e->getMessage()]); }
    reply(200, $found ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'That job is not in the list any more.']);
}
if ($action === 'delete') {                          // the delete button on /admin
    $u = trim((string)($in['url'] ?? ''));
    if ($u === '') reply(400, ['status' => 'error', 'message' => 'No job given.']);
    try { $found = jobs_delete($u); }
    catch (Throwable $e) { reply(200, ['status' => 'error', 'message' => $e->getMessage()]); }
    reply(200, $found ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'That job is not in the list any more.']);
}
if ($action !== '') {
    set_time_limit(55); ini_set('memory_limit', '256M');
    require_once __DIR__ . '/collector.php';
    reply(200, collector_action($action, $in));
}

$url = trim((string)($in['url'] ?? ''));
if ($url === '' || mb_strlen($url) > 1500) reply(400, ['status' => 'error', 'message' => 'Paste a job link first.']);

set_time_limit(55);
reply(200, intake_job($url, 'admin', empty($in['single'])));   // single = true when the card is checking one job from a list
