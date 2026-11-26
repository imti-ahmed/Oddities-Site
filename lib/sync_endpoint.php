<?php
// Pin sync without accounts. A computer makes a 6-character code (letters and numbers); a phone types it in ONCE and from then on the two
// share the same pins. One code pairs exactly ONE computer and ONE phone. The page posts here (/api/index.php?sync=1) with a small JSON message
// that always carries {device: a random id kept in that browser, role: 'desktop' | 'mobile'}:
//   {action:'create', pins}          computer: makes a new code and saves the pins under it
//   {action:'join',   code, pins}    phone: pairs with the code (merging the pins on both) - refused when another phone already has it
//   {action:'load',   code}          returns the pins (and whether a phone has paired)
//   {action:'save',   code, pins}    replaces the pins saved under the code
//   {action:'revert', code}          phone: unpairs, so the code can be used by a phone again
// Only the pins (id, title, company, link) and two random device ids are stored. No names, emails or accounts. Wrong codes and new codes are rate limited per visitor.

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function sync_out(array $a, int $code = 200)
{
    http_response_code($code);
    echo json_encode($a, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sync_out(['status' => 'error', 'message' => 'Use POST.'], 405);
if (!db_enabled()) sync_out(['status' => 'error', 'message' => 'Sync is not available right now.'], 503);
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) sync_out(['status' => 'error', 'message' => 'Bad request.'], 400);

const SYNC_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // no 0 / O / 1 / I, so a code is easy to read out and type
const SYNC_MAX_PINS = 300;
const SYNC_FAIL_LIMIT = 15;      // wrong codes per visitor per hour
const SYNC_CREATE_LIMIT = 20;    // new codes per visitor per hour

try {
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_pins (
        code       TEXT PRIMARY KEY,
        pins       JSONB NOT NULL DEFAULT '[]',
        created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
        updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )");
    $pdo->exec("ALTER TABLE sync_pins ADD COLUMN IF NOT EXISTS desktop_id TEXT");   // the one computer that owns the code
    $pdo->exec("ALTER TABLE sync_pins ADD COLUMN IF NOT EXISTS mobile_id TEXT");    // the one phone that has paired with it (empty until then)
    $pdo->exec("CREATE TABLE IF NOT EXISTS sync_limits (
        k     TEXT PRIMARY KEY,
        hits  INT NOT NULL DEFAULT 0,
        since TIMESTAMPTZ NOT NULL DEFAULT now()
    )");
} catch (Throwable $e) {
    sync_out(['status' => 'error', 'message' => 'Sync is not available right now.'], 503);
}

function sync_visitor(): string
{
    $ip = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    return hash('sha256', 'dj-sync|' . $ip);   // only a hash is kept, never the address
}

function sync_over(PDO $pdo, string $key, int $limit): bool
{
    $s = $pdo->prepare("SELECT hits FROM sync_limits WHERE k = ? AND since > now() - interval '1 hour'");
    $s->execute([$key]);
    $hits = $s->fetchColumn();
    return $hits !== false && (int)$hits >= $limit;
}

function sync_bump(PDO $pdo, string $key): void
{
    $pdo->prepare("INSERT INTO sync_limits (k, hits, since) VALUES (?, 1, now())
        ON CONFLICT (k) DO UPDATE SET
            hits  = CASE WHEN sync_limits.since < now() - interval '1 hour' THEN 1 ELSE sync_limits.hits + 1 END,
            since = CASE WHEN sync_limits.since < now() - interval '1 hour' THEN now() ELSE sync_limits.since END")
        ->execute([$key]);
}

function sync_clean_pins($raw): array
{
    if (!is_array($raw)) return [];
    $out = []; $seen = [];
    foreach ($raw as $p) {
        if (!is_array($p)) continue;
        $id  = mb_substr((string)($p['id'] ?? ''), 0, 120);
        $url = mb_substr((string)($p['url'] ?? ''), 0, 1000);
        if ($id === '' || !preg_match('~^https?://~i', $url)) continue;
        if (isset($seen['i' . $id]) || isset($seen['u' . $url])) continue;
        $seen['i' . $id] = $seen['u' . $url] = true;
        $out[] = ['id' => $id, 'title' => mb_substr(trim((string)($p['title'] ?? '')), 0, 300), 'company' => mb_substr(trim((string)($p['company'] ?? '')), 0, 200), 'url' => $url];
        if (count($out) >= SYNC_MAX_PINS) break;
    }
    return $out;
}

function sync_new_code(): string
{
    do {
        $c = '';
        for ($i = 0; $i < 6; $i++) $c .= SYNC_ALPHABET[random_int(0, strlen(SYNC_ALPHABET) - 1)];
    } while (!preg_match('/[2-9]/', $c) || !preg_match('/[A-Z]/', $c));   // always a mix of numbers and letters
    return $c;
}

$device = (string)($in['device'] ?? '');
if (!preg_match('/^[A-Za-z0-9_-]{8,64}$/', $device)) sync_out(['status' => 'error', 'message' => 'Bad request.'], 400);
$role    = ($in['role'] ?? '') === 'mobile' ? 'mobile' : 'desktop';
$visitor = sync_visitor();
$action  = (string)($in['action'] ?? '');
$code    = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['code'] ?? '')));
$validCode = (bool)preg_match('/^[2-9A-HJ-NP-Z]{6}$/', $code);

if ($action === 'create') {
    if ($role !== 'desktop') sync_out(['status' => 'error', 'message' => 'Codes are made on the computer. Enter the code from there.'], 400);
    if (sync_over($pdo, $visitor . '|c', SYNC_CREATE_LIMIT)) sync_out(['status' => 'error', 'reason' => 'limit', 'message' => 'Too many new codes. Please try again in an hour.'], 429);
    sync_bump($pdo, $visitor . '|c');
    try { $pdo->exec("DELETE FROM sync_pins WHERE pins = '[]'::jsonb AND mobile_id IS NULL AND updated_at < now() - interval '30 days'"); } catch (Throwable $e) { /* tidying is optional */ }   // codes that never got any pins and were never paired
    $pins = sync_clean_pins($in['pins'] ?? []);
    for ($try = 0; $try < 10; $try++) {
        $new = sync_new_code();
        $s = $pdo->prepare("INSERT INTO sync_pins (code, pins, desktop_id) VALUES (?, ?::jsonb, ?) ON CONFLICT (code) DO NOTHING");
        $s->execute([$new, json_encode($pins, JSON_UNESCAPED_UNICODE), $device]);
        if ($s->rowCount() === 1) sync_out(['status' => 'ok', 'code' => $new, 'pins' => $pins, 'synced' => false]);
    }
    sync_out(['status' => 'error', 'message' => 'Could not make a code. Please try again.'], 500);
}

if ($action === 'revert') {                                      // the phone unpairs: the code is free for a phone again, the pins stay where they are
    if ($role === 'mobile' && $validCode) $pdo->prepare("UPDATE sync_pins SET mobile_id = NULL, updated_at = now() WHERE code = ? AND mobile_id = ?")->execute([$code, $device]);
    sync_out(['status' => 'ok']);
}

if (!in_array($action, ['load', 'join', 'save'], true)) sync_out(['status' => 'error', 'message' => 'Unknown action.'], 400);
if (sync_over($pdo, $visitor, SYNC_FAIL_LIMIT)) sync_out(['status' => 'error', 'reason' => 'limit', 'message' => 'Too many wrong codes. Please try again in an hour.'], 429);

$row = false;
if ($validCode) {
    $s = $pdo->prepare("SELECT pins, desktop_id, mobile_id FROM sync_pins WHERE code = ?");
    $s->execute([$code]);
    $row = $s->fetch();
}
$notFound = function () use ($pdo, $visitor) {
    sync_bump($pdo, $visitor);
    sync_out(['status' => 'error', 'reason' => 'notfound', 'message' => 'That code was not found. Check it and try again.'], 404);
};
if (!$row) $notFound();

$saved  = sync_clean_pins(json_decode((string)$row['pins'], true));
$synced = $row['mobile_id'] !== null;

if ($action === 'join') {
    if ($role !== 'mobile') sync_out(['status' => 'error', 'message' => 'Enter this code on your phone.'], 400);
    if ($synced && $row['mobile_id'] !== $device) sync_out(['status' => 'error', 'reason' => 'taken', 'message' => 'That code is already used by another phone.'], 409);
    $merged = sync_clean_pins(array_merge($saved, sync_clean_pins($in['pins'] ?? [])));   // the saved pins first, then any this phone had that are new
    $u = $pdo->prepare("UPDATE sync_pins SET pins = ?::jsonb, mobile_id = ?, updated_at = now() WHERE code = ? AND (mobile_id IS NULL OR mobile_id = ?)");
    $u->execute([json_encode($merged, JSON_UNESCAPED_UNICODE), $device, $code, $device]);
    if ($u->rowCount() !== 1) sync_out(['status' => 'error', 'reason' => 'taken', 'message' => 'That code is already used by another phone.'], 409);   // another phone got there first
    sync_out(['status' => 'ok', 'pins' => $merged, 'synced' => true]);
}

// load and save: only the computer that made the code, or the phone that paired with it
if ($role === 'desktop' ? $row['desktop_id'] !== $device : $row['mobile_id'] !== $device) $notFound();

if ($action === 'load') sync_out(['status' => 'ok', 'pins' => $saved, 'synced' => $synced]);

$mine = sync_clean_pins($in['pins'] ?? []);
$pdo->prepare("UPDATE sync_pins SET pins = ?::jsonb, updated_at = now() WHERE code = ?")->execute([json_encode($mine, JSON_UNESCAPED_UNICODE), $code]);   // save
sync_out(['status' => 'ok', 'pins' => $mine, 'synced' => $synced]);
