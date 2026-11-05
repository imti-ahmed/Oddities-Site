<?php
// The Neon (Postgres) database. The connection address is a secret, so it is NEVER written in this project:
// it lives only in the Vercel setting DATABASE_URL (Vercel > Settings > Environment Variables).
//
// If DATABASE_URL is not set (for example on your own computer) the site falls back to data/jobs.json, so it still works.

require_once __DIR__ . '/helpers.php';

function db_url(): string
{
    return env('DATABASE_URL') ?: env('POSTGRES_URL');
}

function db_enabled(): bool
{
    return db_url() !== '';
}

// One connection per request.
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $u = parse_url(db_url());
    if (!$u || empty($u['host']) || empty($u['path'])) throw new RuntimeException('DATABASE_URL is not a valid Postgres address.');
    parse_str($u['query'] ?? '', $q);
    $user = rawurldecode($u['user'] ?? ''); $pass = rawurldecode($u['pass'] ?? '');
    $base = ';port=' . ($u['port'] ?? 5432) . ';dbname=' . ltrim(rawurldecode($u['path']), '/') . ';sslmode=' . ($q['sslmode'] ?? 'require') . ';connect_timeout=8';
    // (channel_binding from the Neon address is left out on purpose: it is optional and some PHP builds do not support it)
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => true,      // works with Neon's connection pooler
    ];
    try {
        return $pdo = new PDO('pgsql:host=' . $u['host'] . $base, $user, $pass, $opts);
    } catch (PDOException $e) {
        // Very old database drivers do not tell Neon which database to wake up. Neon then says "Endpoint ID is not specified":
        // try again on the direct (non-pooled) address, naming the endpoint ourselves.
        if (!stripos($e->getMessage(), 'endpoint id') || !preg_match('/^(ep-[a-z0-9-]+?)(-pooler)?\./i', $u['host'], $m)) throw $e;
        $host = $m[1] . substr($u['host'], strlen($m[0]) - 1);
        return $pdo = new PDO("pgsql:host=$host" . $base . ";options='endpoint=" . $m[1] . "'", $user, $pass, $opts);
    }
}

// Is this the "table does not exist yet" error? (Postgres code 42P01)
function db_missing_table(Throwable $e): bool
{
    return $e instanceof PDOException && ($e->getCode() === '42P01' || str_contains($e->getMessage(), '42P01'));
}

// Creates the jobs table the first time, then copies in the jobs from data/jobs.json (only at that moment, never again).
function db_create_and_seed(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
        id            BIGSERIAL PRIMARY KEY,
        url           TEXT NOT NULL,
        url_key       TEXT NOT NULL UNIQUE,
        title         TEXT NOT NULL,
        company       TEXT NOT NULL,
        location      TEXT NOT NULL DEFAULT '',
        posted_at     DATE,
        added_at      DATE,
        updated_at    DATE,
        region        TEXT,
        mode          TEXT,
        tag           TEXT,
        role          TEXT,
        status        TEXT,
        closed_at     DATE,
        closed_reason TEXT,
        source        TEXT,
        last_checked_at TIMESTAMPTZ,
        last_seen_at    TIMESTAMPTZ,
        check_fails     SMALLINT NOT NULL DEFAULT 0,
        highlighted     BOOLEAN NOT NULL DEFAULT false,
        created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
    )");
    $count = (int)$pdo->query('SELECT count(*) FROM jobs')->fetchColumn();
    if ($count > 0) return;

    $seed = json_decode((string)@file_get_contents(__DIR__ . '/../data/jobs.json'), true);
    if (!is_array($seed)) return;
    require_once __DIR__ . '/pagefetch.php';
    // oldest first, so the newest keep the highest ids
    foreach (array_reverse($seed) as $j) {
        if (empty($j['url']) || empty($j['title']) || empty($j['company'])) continue;
        db_insert_job($j);
    }
}

// Date columns: only accept YYYY-MM-DD, anything else becomes NULL.
function db_date($v): ?string
{
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
}

// Adds one job. Returns true if added, false if that link is already there.
function db_insert_job(array $j): bool
{
    require_once __DIR__ . '/pagefetch.php';
    $st = db()->prepare('INSERT INTO jobs (url, url_key, title, company, location, posted_at, added_at, updated_at, region, mode, tag, role, status, closed_at, closed_reason, source)
        VALUES (:url, :key, :title, :company, :location, :posted, :added, :updated, :region, :mode, :tag, :role, :status, :closed, :reason, :source)
        ON CONFLICT (url_key) DO NOTHING');
    $st->execute([
        ':url' => $j['url'], ':key' => normalize_job_url((string)$j['url']), ':title' => $j['title'], ':company' => $j['company'],
        ':location' => (string)($j['location'] ?? ''), ':posted' => db_date($j['posted_at'] ?? null), ':added' => db_date($j['added_at'] ?? null),
        ':updated' => db_date($j['updated_at'] ?? null), ':region' => $j['region'] ?? null, ':mode' => $j['mode'] ?? null,
        ':tag' => !empty($j['tags'][0]) ? $j['tags'][0] : null, ':role' => $j['role'] ?? null, ':status' => $j['status'] ?? null,
        ':closed' => db_date($j['closed_at'] ?? null), ':reason' => $j['closed_reason'] ?? null, ':source' => $j['source'] ?? null,
    ]);
    return $st->rowCount() > 0;
}

// All jobs, newest first, in the same shape the old data/jobs.json had.
function db_all_jobs(): array
{
    $run = fn() => db()->query('SELECT * FROM jobs ORDER BY id DESC')->fetchAll();
    try { $rows = $run(); }
    catch (Throwable $e) {
        if (!db_missing_table($e)) throw $e;
        db_create_and_seed();
        $rows = $run();
    }
    $jobs = [];
    foreach ($rows as $r) {
        $j = ['title' => $r['title'], 'company' => $r['company'], 'location' => $r['location'], 'url' => $r['url'], 'posted_at' => $r['posted_at'],
              'added_at' => $r['added_at'], 'updated_at' => $r['updated_at'], 'region' => $r['region'], 'mode' => $r['mode'],
              'role' => $r['role'], 'highlighted' => !empty($r['highlighted']) ? true : null, 'status' => $r['status'], 'closed_at' => $r['closed_at'], 'closed_reason' => $r['closed_reason'], 'source' => $r['source']];
        if (!empty($r['tag'])) $j['tags'] = [$r['tag']];
        $jobs[] = array_filter($j, fn($v) => $v !== null && $v !== '');
    }
    return $jobs;
}


// Highlight (or un-highlight) one job for everyone. Returns true if a job was found.
function db_set_highlight(string $url, bool $on): bool
{
    require_once __DIR__ . '/pagefetch.php';
    db_add_check_columns();
    $st = db()->prepare('UPDATE jobs SET highlighted = :on WHERE url_key = :k');
    $st->execute([':on' => $on ? 'true' : 'false', ':k' => normalize_job_url($url)]);
    return $st->rowCount() > 0;
}

// Older databases get the columns the "still open?" check and the highlight star need (safe to run again and again).
function db_add_check_columns(): void
{
    static $done = false;
    if ($done) return;
    db()->exec('ALTER TABLE jobs ADD COLUMN IF NOT EXISTS last_checked_at TIMESTAMPTZ');
    db()->exec('ALTER TABLE jobs ADD COLUMN IF NOT EXISTS last_seen_at TIMESTAMPTZ');
    db()->exec('ALTER TABLE jobs ADD COLUMN IF NOT EXISTS check_fails SMALLINT NOT NULL DEFAULT 0');
    db()->exec('ALTER TABLE jobs ADD COLUMN IF NOT EXISTS highlighted BOOLEAN NOT NULL DEFAULT false');
    $done = true;
}
