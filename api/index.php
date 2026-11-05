<?php
// Vercel entry point for the website. All the real code is in the main index.php.
// The address /admin is sent here too (see vercel.json) with ?admin=1, and shows the extra "Add a job" card.
$path = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
if (($_GET['admin'] ?? '') === '1' || $path === '/admin') define('IS_ADMIN', true);
require __DIR__ . '/../index.php';
