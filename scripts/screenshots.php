<?php
// Takes a screenshot for every Portfolio and Tool that has none yet, and keeps it in the Neon bucket. Needs DATABASE_URL and the
// storage variables (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_ENDPOINT_URL_S3, AWS_REGION) in the environment / .env.
//
//   php scripts/screenshots.php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
chdir(dirname(__DIR__));
set_time_limit(0);
require __DIR__ . '/../lib/screenshot.php';

if (!db_enabled() || !storage_enabled()) { echo "Needs DATABASE_URL and the storage variables (see README).\n"; exit(1); }
db_create_directory_tables();
do {
    $r = screenshots_fill_missing(5);
    echo "{$r['done']} screenshots saved, {$r['failed']} failed, {$r['left']} still to do.\n";
} while ($r['done'] > 0 && $r['left'] > 0);
