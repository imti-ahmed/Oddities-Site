<?php
// The /admin card for "Portfolio" and "Tools": paste a link, the AI fills in the entry, it is saved, and a screenshot is taken.
// There are no strict rules here (unlike jobs): the AI only names the entry and, for a tool, picks its tag from config/directory.php.
//
//   link -> already listed? -> read the page -> Groq: name (+ tag for a tool) -> save -> screenshot into the Neon bucket
//
// Returns ['status' => 'added' | 'duplicate' | 'error', 'message' => 'plain sentence']

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/jobs.php';          // no_em_dash()
require_once __DIR__ . '/pagefetch.php';
require_once __DIR__ . '/groq.php';
require_once __DIR__ . '/directory.php';
require_once __DIR__ . '/screenshot.php';

function site_system_prompt(string $kind, array $cfg): string
{
    if ($kind === 'tools') {
        $lines = [];
        foreach ($cfg['tool_tags'] as $tag => $means) $lines[] = '- "' . $tag . '": ' . $means;
        $rules = "\"name\": the name of the tool or product, as the site calls it (short, for example \"Coolors\", not \"Coolors - The super fast color palettes generator\").\n"
               . "\"tag\": the kind of tool. Pick EXACTLY ONE of these tags, written exactly like this:\n" . implode("\n", $lines);
        $shape = '{"name": "...", "tag": "..."}';
        $plural = 'tools';
    } else {
        $rules = "\"name\": the name of the person or studio whose portfolio or personal site this is (short, for example \"Jane Doe\" or \"Studio Nord\"). Never write the word \"portfolio\" as the name. If you cannot tell, use the site's own name.";
        $shape = '{"name": "..."}';
        $plural = 'portfolios';
    }
    $text = (string)file_get_contents(__DIR__ . '/../config/groq_site_prompt.txt');
    return str_replace(['{{KIND_PLURAL}}', '{{KIND_RULES}}', '{{JSON_SHAPE}}'], [$plural, $rules, $shape], $text);
}

function intake_site(string $url, string $kind): array
{
    $kind = $kind === 'portfolios' ? 'portfolios' : 'tools';
    $word = $kind === 'tools' ? 'tool' : 'portfolio';
    $cfg  = require __DIR__ . '/../config/directory.php';
    $strict = env('VERCEL') !== '';

    $url = trim($url);
    [$ok, $why] = check_public_url($url, $strict);
    if (!$ok) return ['status' => 'error', 'message' => $why];
    if (!db_enabled()) return ['status' => 'error', 'service_error' => true, 'message' => 'Saving needs the database. Add DATABASE_URL (the Neon connection string).'];
    $link = normalize_job_url($url);

    try {
        db_create_directory_tables();
        $st = db()->prepare("SELECT name, dead_at FROM $kind WHERE link = :l");
        $st->execute([':l' => $link]);
        if ($row = $st->fetch()) return ['status' => 'duplicate', 'message' => 'That link is already in the list (' . $row['name'] . ')' . (!empty($row['dead_at']) ? ', in the Dead Zone.' : '.')];
    } catch (Throwable $e) { return ['status' => 'error', 'service_error' => true, 'message' => 'Could not read the database: ' . db_error_hint($e)]; }

    // read the page. A site that is gone is not added; a site that only blocks our reader (403, 429, 5xx: common for big tools) still is,
    // and the AI then works from the address alone.
    $page = read_job_page($url, 6000, $strict);
    if (!$page['ok']) {
        if (!preg_match('/HTTP (401|403|429|5\d\d|999)\b/', $page['error'])) return ['status' => 'error', 'message' => 'That website did not open, so it was not added. ' . $page['error']];
        $page = ['ok' => true, 'final_url' => $url, 'page_title' => '', 'description' => '', 'text' => '(the site blocked our reader: use the address and what you know about this site)'];
    }

    $ask = groq_json(site_system_prompt($kind, $cfg),
        "Fill in the entry for this $word and reply with the JSON object.\n\nPAGE (JSON):\n" . json_encode([
            'link' => $page['final_url'], 'page_title' => $page['page_title'], 'page_description' => $page['description'], 'page_text' => mb_substr($page['text'], 0, 3500),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 300);
    if (!$ask['ok']) return ['status' => 'error', 'service_error' => empty($ask['soft']), 'message' => $ask['error']];

    $name = trim(no_em_dash(preg_replace('/\s+/u', ' ', strip_tags((string)($ask['answer']['name'] ?? '')))));
    $name = mb_substr($name, 0, 80);
    if ($name === '') $name = mb_substr(trim(no_em_dash($page['page_title'])), 0, 80);
    if ($name === '') $name = preg_replace('/^www\./', '', (string)parse_url($link, PHP_URL_HOST));

    $tag = null;
    if ($kind === 'tools') {
        $given = trim((string)($ask['answer']['tag'] ?? ''));
        $tag = 'Other';
        foreach (array_keys($cfg['tool_tags']) as $t) if (strcasecmp($t, $given) === 0) { $tag = $t; break; }   // only a tag from our list is accepted
    }

    try {
        $sql = $kind === 'tools' ? 'INSERT INTO tools (name, link, tag) VALUES (:n, :l, :t) ON CONFLICT (link) DO NOTHING RETURNING id'
                                 : 'INSERT INTO portfolios (name, link) VALUES (:n, :l) ON CONFLICT (link) DO NOTHING RETURNING id';
        $st = db()->prepare($sql);
        $st->execute($kind === 'tools' ? [':n' => $name, ':l' => $link, ':t' => $tag] : [':n' => $name, ':l' => $link]);
        $id = $st->fetchColumn();
    } catch (Throwable $e) { return ['status' => 'error', 'service_error' => true, 'message' => 'Could not save to the database: ' . db_error_hint($e)]; }
    if (!$id) return ['status' => 'duplicate', 'message' => 'That link is already in the list.'];

    $shot = screenshot_save($kind, (int)$id, $link);     // best effort: if it fails, the card shows a live screenshot instead
    return ['status' => 'added', 'message' => 'Added ' . $word . ': ' . $name . ($tag ? ' (' . $tag . ')' : '') . '. '
        . ($shot ? 'Screenshot saved.' : 'No screenshot yet (a live preview is shown meanwhile); run php scripts/screenshots.php to retry.') . ' The site shows it within a minute.'];
}
