<?php
// Opens the database that stores all the jobs.
//
//  * On your own computer: a small file, data/jobs.sqlite (nothing to install).
//  * On Vercel (or anywhere with DATABASE_URL set): an online Postgres database,
//    because Vercel cannot keep files between visits.

function env(string $name): string
{
    $v = getenv($name);
    if ($v === false || $v === '') $v = $_ENV[$name] ?? $_SERVER[$name] ?? '';
    return (string)$v;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $url  = env('DATABASE_URL') ?: env('POSTGRES_URL');
    $isPg = $url !== '';

    if ($isPg) {
        $p = parse_url($url);
        if (!$p || empty($p['host'])) throw new RuntimeException('DATABASE_URL is not a valid database address.');
        parse_str($p['query'] ?? '', $q);
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            $p['host'], $p['port'] ?? 5432, ltrim($p['path'] ?? '/postgres', '/'), $q['sslmode'] ?? 'require');
        $pdo = new PDO($dsn, urldecode($p['user'] ?? ''), urldecode($p['pass'] ?? ''));
    } elseif (env('VERCEL')) {
        // On Vercel a file database would silently lose everything, so refuse and explain.
        throw new RuntimeException('No database connected. In Vercel, add a Postgres database (Storage tab) so DATABASE_URL is set.');
    } else {
        $pdo = new PDO('sqlite:' . __DIR__ . '/../data/jobs.sqlite');
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // The two kinds of database spell a couple of things differently.
    $key = $isPg ? 'SERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $int = $isPg ? 'BIGINT' : 'INTEGER';

    // jobs: status is 'open' or 'closed'. fail_count = times the tester could not reach the page.
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jobs (
            id            $key,
            source        TEXT NOT NULL,
            title         TEXT NOT NULL,
            company       TEXT NOT NULL,
            location      TEXT DEFAULT '',
            url           TEXT NOT NULL UNIQUE,
            posted_at     $int,
            first_seen    $int NOT NULL,
            last_seen     $int NOT NULL,
            last_checked  $int,
            status        TEXT NOT NULL DEFAULT 'open',
            fail_count    INTEGER NOT NULL DEFAULT 0,
            closed_at     $int,
            closed_reason TEXT
        )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status, posted_at)");
    // Remembers when each source was last visited, so big lists get worked through in turns.
    $pdo->exec("CREATE TABLE IF NOT EXISTS source_state (source TEXT PRIMARY KEY, last_fetched $int NOT NULL)");

    return $pdo;
}
