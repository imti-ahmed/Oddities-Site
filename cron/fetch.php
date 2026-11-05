<?php
// FINDER (command line):  php cron/fetch.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }
require_once __DIR__ . '/../lib/tasks.php';
run_fetch();
