<?php
// The Portfolios and Tools lists. Each has its own table in the Neon database:
//   tools      : name, link, image (a screenshot of the site), tag (the kind of tool), pinned
//   portfolios : name, link, image (a screenshot of the site), pinned          (no tag here)
// "pinned" = pinned by YOU for everyone (pinned items come first). A visitor's own pins are different: they stay in the visitor's browser.
// A site that stops working gets dead_at + dead_reason (set by scripts/check_sites.php): it leaves the gallery and shows in the Dead Zone.
// The tables are created by themselves the first time they are needed (safe to run again and again).

require_once __DIR__ . '/db.php';

// Lists that have a picture (screenshots): Tools and Portfolios.
function directory_kinds(): array { return ['tools', 'portfolios']; }
// Every list whose websites are checked and that can go to the Dead Zone: the two above, and Companies (a plain list, no pictures).
function site_kinds(): array { return ['tools', 'portfolios', 'companies']; }

function db_create_directory_tables(): void
{
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS tools (
        id         BIGSERIAL PRIMARY KEY,
        name       TEXT NOT NULL,
        link       TEXT NOT NULL UNIQUE,
        image      TEXT,
        tag        TEXT,
        pinned     BOOLEAN NOT NULL DEFAULT false,
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS portfolios (
        id         BIGSERIAL PRIMARY KEY,
        name       TEXT NOT NULL,
        link       TEXT NOT NULL UNIQUE,
        image      TEXT,
        pinned     BOOLEAN NOT NULL DEFAULT false,
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id          BIGSERIAL PRIMARY KEY,
        name        TEXT NOT NULL,
        link        TEXT NOT NULL UNIQUE,
        type        TEXT,
        description TEXT,
        created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
    )");
    // the "is the site still working?" check (every 2 weeks) keeps its notes here
    foreach (site_kinds() as $t) {
        $pdo->exec("ALTER TABLE $t ADD COLUMN IF NOT EXISTS dead_at TIMESTAMPTZ");
        $pdo->exec("ALTER TABLE $t ADD COLUMN IF NOT EXISTS dead_reason TEXT");
        $pdo->exec("ALTER TABLE $t ADD COLUMN IF NOT EXISTS last_checked_at TIMESTAMPTZ");
        $pdo->exec("ALTER TABLE $t ADD COLUMN IF NOT EXISTS check_fails SMALLINT NOT NULL DEFAULT 0");
    }
}

// All items of one list ('tools' or 'portfolios'): pinned first, then newest first. Empty when there is no database or it cannot be read.
// Each item has 'dead' => true when its site stopped working (those show in the Dead Zone, not in the gallery).
function directory_items(string $kind): array
{
    if (!in_array($kind, site_kinds(), true) || !db_enabled()) return [];
    $order = $kind === 'companies' ? 'id DESC' : 'pinned DESC, id DESC';   // companies: a plain list, newest first
    $run = fn() => db()->query("SELECT * FROM $kind ORDER BY $order")->fetchAll();
    try { $rows = $run(); }
    catch (Throwable $e) {
        if (!db_missing_table($e)) return [];
        try { db_create_directory_tables(); $rows = $run(); } catch (Throwable $e2) { return []; }
    }
    $items = [];
    foreach ($rows as $r) {
        $items[] = [
            'id'     => ['tools' => 'tool-', 'portfolios' => 'port-', 'companies' => 'co-'][$kind] . $r['id'],
            'kind'   => $kind,
            'name'   => (string)$r['name'],
            'link'   => (string)$r['link'],
            'image'  => (string)($r['image'] ?? ''),
            'tag'    => (string)($r['tag'] ?? ''),
            'type'   => (string)($r['type'] ?? ''),                 // companies only
            'description' => (string)($r['description'] ?? ''),   // companies only
            'pinned' => !empty($r['pinned']),
            'added'  => !empty($r['created_at']) ? (strtotime((string)$r['created_at']) ?: 0) : 0,
            'dead'   => !empty($r['dead_at']),
            'removed' => !empty($r['dead_at']) ? (strtotime((string)$r['dead_at']) ?: 0) : 0,
            'dead_reason' => (string)($r['dead_reason'] ?? ''),
        ];
    }
    return $items;
}

// The picture for a gallery card: the saved screenshot, or else a live one from a free screenshot service (WordPress mShots).
function directory_image(array $item): string
{
    if ($item['image'] !== '') return $item['image'];
    return 'https://s0.wp.com/mshots/v1/' . rawurlencode($item['link']) . '?w=800&h=600';
}
