<?php
// Web address that runs the FINDER:  /api/fetch
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../lib/tasks.php';
require_secret();
set_time_limit(58);
run_fetch(40);   // stop starting new sources after 40 seconds (Vercel cuts runs off at 60)
