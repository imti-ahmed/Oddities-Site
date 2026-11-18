<?php
// Vercel entry point for the website. All the real code is in the main index.php.
// The address /meow shows the extra "Add a job" card (the page also adds it itself in the browser if this check misses).
$path = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if (($_GET['admin'] ?? '') === '1' || $path === '/meow') define('IS_ADMIN', true);
if (($_GET['add'] ?? '') === '1') { require __DIR__ . '/../lib/add_job_endpoint.php'; exit; }   // the /meow card posts here
require __DIR__ . '/../index.php';
