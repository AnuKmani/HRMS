<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // This is a stateless REST API — there is no web login page, so the
        // framework default (route('login')) would throw RouteNotFoundException
        // for any unauthenticated non-JSON request. Point the redirect at a
        // harmless path instead; API callers still receive a JSON 401 below.
        $middleware->redirectGuestsTo(fn () => '/');

        // Force JSON responses for every /api/* route regardless of the
        // Accept header, so clients always get {success, message, errors}
        // instead of an HTML redirect.
        //
        // NOTE: statefulApi() is deliberately NOT called here. That would
        // enable Sanctum's cookie/SPA authentication; this project uses
        // bearer tokens issued to the Flutter app instead.

        // spatie/laravel-permission does not register these aliases itself on
        // Laravel 11+, so wire them up explicitly. Routes then enforce
        // authorization server-side:
        //
        //     Route::get(...)->middleware('permission:employees.view');
        //     Route::post(...)->middleware('role:HR Admin|Super Admin');
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $throwable) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
