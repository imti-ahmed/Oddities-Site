<?php
// Serves the site icons (the favicon). On Vercel plain files are not served automatically, so this little file hands them out.
// Only the three names below can be asked for: nothing else in the project can be reached through it.

$allowed = [
    'favicon.ico'          => 'image/x-icon',
    'favicon-32.png'       => 'image/png',
    'apple-touch-icon.png' => 'image/png',
];

// The icon name comes from "?f=name" (the Vercel route adds it), or else from the last part of the address (/favicon.ico).
$name = (string)($_GET['f'] ?? basename((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)));
$file = __DIR__ . '/../assets/icons/' . $name;

if (!isset($allowed[$name]) || !is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Not found\n");
}

header('Content-Type: ' . $allowed[$name]);
header('Content-Length: ' . filesize($file));
header('Cache-Control: public, max-age=604800');        // browsers keep it for a week
readfile($file);
