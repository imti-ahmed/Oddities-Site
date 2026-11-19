<?php
// The door the /meow card knocks on (reached through api/index.php?add=1): "here is a job link, please check it and list it".
// No password: anyone who knows the /meow address can use it (by choice).

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['status' => 'error', 'message' => 'Use the card on the /meow page.']);

$in = json_decode((string)file_get_contents('php://input'), true);
$in = is_array($in) ? $in : [];

// buttons on the /meow card (highlight star, delete, check open jobs)
$action = (string)($in['action'] ?? '');
if ($action === 'highlight') {                       // the star on /meow: highlight a job for everyone (the Highlighted tab)
    $u = trim((string)($in['url'] ?? ''));
    if ($u === '') reply(400, ['status' => 'error', 'message' => 'No job given.']);
    try { $found = jobs_set_highlight($u, !empty($in['on'])); }
    catch (Throwable $e) { reply(200, ['status' => 'error', 'message' => $e->getMessage()]); }
    reply(200, $found ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'That job is not in the list any more.']);
}
if ($action === 'delete') {                          // the delete button on /meow
    $u = trim((string)($in['url'] ?? ''));
    if ($u === '') reply(400, ['status' => 'error', 'message' => 'No job given.']);
    try { $found = jobs_delete($u); }
    catch (Throwable $e) { reply(200, ['status' => 'error', 'message' => $e->getMessage()]); }
    reply(200, $found ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'That job is not in the list any more.']);
}
if ($action === 'delete_site') {                     // the delete button on a portfolio, tool or company (card, row or Dead Zone)
    require_once __DIR__ . '/directory.php';
    $id = trim((string)($in['id'] ?? ''));
    try { $found = directory_delete($id); }
    catch (Throwable $e) { reply(200, ['status' => 'error', 'message' => $e->getMessage()]); }
    reply(200, $found ? ['status' => 'ok'] : ['status' => 'error', 'message' => 'That entry is not in the list any more.']);
}
if ($action === 'shots') {                           // "Take missing screenshots": a few per press (each one takes several seconds)
    set_time_limit(55); ini_set('memory_limit', '256M');
    require_once __DIR__ . '/screenshot.php';
    if (!db_enabled() || !storage_enabled()) reply(200, ['status' => 'stop', 'message' => 'Screenshots need DATABASE_URL and the storage variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_ENDPOINT_URL_S3, AWS_REGION) in Vercel.']);
    try { db_create_directory_tables(); reply(200, ['status' => 'ok'] + screenshots_fill_missing(3)); }
    catch (Throwable $e) { reply(200, ['status' => 'stop', 'message' => 'Could not take screenshots: ' . $e->getMessage()]); }
}
if ($action !== '') {                                // the "Check open jobs" button (no AI). Collecting new jobs is NOT possible from here: only the scheduled GitHub job does that.
    set_time_limit(55); ini_set('memory_limit', '256M');
    require_once __DIR__ . '/liveness.php';
    reply(200, liveness_action($action, $in));
}

if (($in['kind'] ?? '') === 'manual_job') {          // "Add manually" on the /meow card: the details are typed by hand, so no page is read and no AI is asked
    $roles = require __DIR__ . '/../config/roles.php';
    $settings = require __DIR__ . '/../config/settings.php';
    $url = trim((string)($in['url'] ?? ''));
    [$ok, $why] = check_public_url($url, false);
    if ($url === '' || mb_strlen($url) > 1500 || !$ok) reply(200, ['status' => 'error', 'message' => $url === '' ? 'Enter the job link.' : ($why ?: 'That link does not look right.')]);
    $title = clean_line((string)($in['title'] ?? ''), 200);
    $company = clean_line((string)($in['company'] ?? ''), 120);
    if ($title === '' || $company === '') reply(200, ['status' => 'error', 'message' => 'Job title and company are both needed.']);
    $role = (string)($in['role'] ?? '');
    $roleCategory = array_column($roles['tags'], 'category', 'tag');
    if (!isset($roleCategory[$role]) || $role === 'Internship') reply(200, ['status' => 'error', 'message' => 'Pick a role from the list.']);
    $location = format_location(clean_line((string)($in['location'] ?? ''), 120));
    $region = strtolower((string)($in['region'] ?? ''));
    if (!in_array($region, ['india', 'abroad'], true)) reply(200, ['status' => 'error', 'message' => 'Pick India or Abroad.']);
    $mode = strtolower((string)($in['mode'] ?? ''));
    $today = date('Y-m-d');
    $posted = (string)($in['posted_at'] ?? '');
    if ($posted === '') $posted = $today;
    elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $posted) || !strtotime($posted)) reply(200, ['status' => 'error', 'message' => 'The posted date does not look right.']);
    if ($posted > $today) $posted = $today;
    if ((strtotime($today) - strtotime($posted)) / 86400 > (int)($settings['stale_days'] ?? 90)) reply(200, ['status' => 'error', 'message' => 'That job was posted more than ' . (int)($settings['stale_days'] ?? 90) . ' days ago, so it would go straight to the Dead Zone.']);
    $job = ['title' => $title, 'company' => $company, 'location' => $location, 'url' => normalize_job_url($url), 'posted_at' => $posted, 'added_at' => $today,
            'region' => $region, 'tags' => [$roleCategory[$role] . (!empty($in['intern']) ? ' · Intern' : '')], 'role' => $role, 'source' => 'admin'];
    if (in_array($mode, ['remote', 'hybrid', 'in-office'], true)) $job['mode'] = $mode;
    try { $existing = jobs_read_fresh(); } catch (Throwable $e) { reply(200, ['status' => 'error', 'message' => $e->getMessage()]); }
    reply(200, intake_save($job, $existing));
}

$url = trim((string)($in['url'] ?? ''));
if ($url === '' || mb_strlen($url) > 1500) reply(400, ['status' => 'error', 'message' => 'Paste a job link first.']);

set_time_limit(55);
$kind = (string)($in['kind'] ?? 'job');
if (in_array($kind, ['tools', 'portfolios', 'companies'], true)) {   // a Portfolio, a Tool or a Company: the AI only fills in the entry (name, and the tag for a tool)
    require_once __DIR__ . '/site_intake.php';
    reply(200, intake_site($url, $kind));
}
reply(200, intake_job($url, 'admin', empty($in['single'])));   // single = true when the card is checking one job from a list
