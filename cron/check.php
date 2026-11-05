<?php
// TESTER (command line):  php cron/check.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this from the command line.'); }
require_once __DIR__ . '/../lib/tasks.php';
$settings = require __DIR__ . '/../config/settings.php';
run_check($settings['check_batch_size'], $settings['check_delay_ms']);
