<?php
// Opens (and creates, if needed) the little database file that stores all the jobs.

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $pdo = new PDO('sqlite:' . __DIR__ . '/../data/jobs.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS jobs (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            source       TEXT NOT NULL,           -- which entry in config/sources.php it came from
            title        TEXT NOT NULL,
            company      TEXT NOT NULL,
            location     TEXT DEFAULT '',
            url          TEXT NOT NULL UNIQUE,    -- link to the real job posting
            posted_at    INTEGER,                 -- when the company posted it (unix time)
            first_seen   INTEGER NOT NULL,        -- when we first found it
            last_seen    INTEGER NOT NULL,        -- last time the source still listed it
            last_checked INTEGER,                 -- last time the tester visited the link
            status       TEXT NOT NULL DEFAULT 'open',  -- 'open' or 'closed'
            fail_count   INTEGER NOT NULL DEFAULT 0,    -- times the tester couldn't reach the page
            closed_at    INTEGER,
            closed_reason TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_jobs_status ON jobs(status, posted_at);
    ");

    return $pdo;
}
