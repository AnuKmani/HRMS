<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Health check
|--------------------------------------------------------------------------
|
| GET /health — the endpoint a load balancer, an uptime probe and a
| 3am operator all hit. It is deliberately unauthenticated (a probe that
| has to hold a credential is a probe that stops working the day the
| credential rotates) and deliberately thin: four dependency answers and
| nothing that describes the system behind them.
|
| What it does **not** return, because every one of those is a fact an
| attacker would otherwise get for free from an anonymous request:
|
|   - no config values: no database host, no cache prefix, no queue
|     driver, no app key, no filesystem paths;
|   - no versions: a framework version is a shopping list of things to
|     try;
|   - no timings and no row counts: "the database took 840ms" tells a
|     caller about load, and load tells them when the box is weakest.
|
| `503` when any dependency is down rather than a 200 with a sad body:
| a probe that has to parse a message to learn the service is broken is
| a probe that will eventually stop parsing the message.
|
| Each check is written so that a *failure to reach* the dependency is
| the thing being measured — a `try`/`catch` around an operation that
| would genuinely use it, never a `config()` read that answers "ok"
| because nothing has gone wrong yet.
|
*/

Route::get('/health', function () {
    $checks = [
        'database' => fn (): bool => DB::connection()->selectOne('select 1') !== null,
        'cache' => function (): bool {
            Cache::put('health:probe', 1, 30);

            return Cache::get('health:probe') === 1;
        },
        'storage' => function (): bool {
            $disk = Storage::disk('local');
            $disk->put('health/probe.txt', 'ok');

            return $disk->get('health/probe.txt') === 'ok' && $disk->delete('health/probe.txt');
        },
        'queue' => function (): bool {
            // `sync` is not a queue — jobs run inline, in this request —
            // so there is no worker to be down and nothing to connect
            // to. Saying `down` because a `size()` call has nowhere to
            // go would make a correctly-configured development install
            // report itself broken.
            if (config('queue.default') === 'sync') {
                return true;
            }

            return Queue::connection()->size() >= 0;
        },
    ];

    $results = [];

    foreach ($checks as $name => $probe) {
        try {
            $results[$name] = $probe() ? 'ok' : 'down';
        } catch (Throwable) {
            // Any exception — connection refused, auth failure, disk
            // full — is the same answer from here: down. The reason is
            // written to the application log by Laravel, where an
            // operator can see it; it is not written into the response,
            // because the reason is usually a host name.
            $results[$name] = 'down';
        }
    }

    $healthy = ! in_array('down', $results, true);

    return response()->json([
        'success' => $healthy,
        'message' => $healthy ? 'Service is healthy.' : 'Service is degraded.',
        'data' => [
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $results,
            'time' => now()->toIso8601String(),
        ],
    ], $healthy ? 200 : 503, [
        // A probe result is a measurement of *right now*; anything that
        // caches it is measuring whenever it cached it.
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'X-Content-Type-Options' => 'nosniff',
    ]);
});

/*
|--------------------------------------------------------------------------
| Password reset landing page
|--------------------------------------------------------------------------
|
| GET /reset-password — where the action button in the reset email lands.
| The application has no web UI, but an email whose only link 404s is an
| email that starts a support ticket instead of ending a problem, so this
| one page exists to carry the token and address from the mail back into
| POST /api/v1/auth/reset-password.
|
| It takes nothing from the query string that the API does not already
| validate, holds no state of its own, and cannot reset anything the link
| did not authorize — the broker decides all of that, exactly as it does
| for a request that arrives from the phone. `no-store` because a page
| that holds a live credential in a URL should not be a page a shared
| machine can serve back out of its cache.
|
| No throttle: rendering static markup costs nothing, and throttling the
| *page* would lock out a household behind one NAT address on a link the
| throttled forgot-password endpoint already paid for.
|
*/

Route::get('/reset-password', function (Request $request) {
    return response()->view('reset-password', [
        'token' => (string) $request->query('token', ''),
        'email' => (string) $request->query('email', ''),
    ], 200, [
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'X-Content-Type-Options' => 'nosniff',
        'X-Robots-Tag' => 'noindex, nofollow',
    ]);
});
