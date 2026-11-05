<?php
// For testing on your own computer (php -S localhost:8000): open http://localhost:8000/admin.php
// (On Vercel the address is simply /admin, handled by api/admin.php.)
define('IS_ADMIN', true);
require __DIR__ . '/index.php';
