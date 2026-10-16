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

require __DIR__.'/../public/index.php';
