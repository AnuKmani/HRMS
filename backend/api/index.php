<?php

/*
| Vercel entry point. Every request is routed here (see vercel.json).
|
| The deployed code is read-only; only /tmp is writable, and only for the
| life of one instance. So everything Laravel writes — compiled views,
| caches, uploaded files when no S3 bucket is configured — goes under
| /tmp/storage. Logs go to stderr, where Vercel shows them.
*/

$storage = '/tmp/storage';

foreach (['framework/views', 'framework/cache/data', 'app/private', 'app/public', 'logs'] as $dir) {
    if (! is_dir("{$storage}/{$dir}")) {
        @mkdir("{$storage}/{$dir}", 0777, true);
    }
}

$_ENV['LARAVEL_STORAGE_PATH'] = $_SERVER['LARAVEL_STORAGE_PATH'] = $storage;

// This file lives at /api/index.php, so Laravel would treat "/api" as the
// app's base folder and strip it from every URL — /api/v1/auth/login would
// arrive as v1/auth/login. Present the script as the site root instead.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__.'/../public/index.php';

require __DIR__.'/../public/index.php';
