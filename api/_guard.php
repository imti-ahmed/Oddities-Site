<?php
// Keeps strangers from triggering the finder/tester.
// Vercel's scheduler sends "Authorization: Bearer <CRON_SECRET>" automatically.
// For manual testing in a browser, use:  /api/fetch?key=<CRON_SECRET>
require_once __DIR__ . '/../lib/db.php';

function require_secret(): void
{
    $secret = env('CRON_SECRET');
    header('Content-Type: text/plain; charset=utf-8');
    if ($secret === '') { http_response_code(403); exit("CRON_SECRET is not set. Add it in Vercel > Settings > Environment Variables, then redeploy.\n"); }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $auth = $v;
    $given = (string)($_GET['key'] ?? '');

    if (!hash_equals('Bearer ' . $secret, $auth) && !hash_equals($secret, $given)) {
        http_response_code(401);
        exit("Wrong or missing key.\n");
    }
}
