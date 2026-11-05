<?php
// Web address that runs the TESTER:  /api/check
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../lib/tasks.php';
require_secret();
set_time_limit(58);
run_check(60, 150, 40);   // up to 60 links per run, pause 0.15s between, stop after 40 seconds
