<?php

use App\Http\Responses\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // /api/* always gets JSON, even from a client that sent Accept: */*.
        // Without this a browser-ish request would receive a redirect to '/'.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $throwable) => $request->is('api/*') || $request->expectsJson()
        );

        /*
        |----------------------------------------------------------------------
        | One envelope, one place
        |----------------------------------------------------------------------
        |
        | Laravel matches these callbacks in registration order and returns the
        | first non-null response, so the most specific exception is registered
        | first and the catch-all last. Everything outside /api/* returns null
        | and falls through to framework behaviour untouched.
        |
        | Nothing below ever surfaces a stack trace, a SQL statement, a model
        | class name or an internal path. Technical detail is still written
        | server-side by Laravel's reporter into storage/logs — the client is
        | simply not shown any of it, and request payloads are never logged
        | (a login body contains a password; an API request carries a bearer
        | token).
        |
        */

        // 422 — FormRequest / Validator failures.
        // ValidationException's message is a summary of the validator: the
        // first field error followed by "(and N more errors)". So `message`
        // works as a banner and `errors` carries the full field map the form
        // draws under each input.
        $exceptions->render(function (ValidationException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($e->getMessage(), $e->errors(), 422);
        });

        // 401 — bad credentials, missing/expired token, deactivated account.
        $exceptions->render(function (AuthenticationException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error($e->getMessage() ?: 'Your session has expired. Please sign in again.', null, 401);
        });

        // 404 — unknown route, missing record, abort(404, '…').
        // prepareException() has already wrapped ModelNotFoundException into
        // this, and its message would name the model class, so that case is
        // replaced with a generic one.
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $message = $e->getPrevious() instanceof ModelNotFoundException
                ? null
                : ($e->getMessage() ?: null);

            return ApiResponse::error($message ?? 'The requested resource was not found.', null, 404, $e->getHeaders());
        });

        // 429 — the named limiters in AppServiceProvider. Carries Retry-After
        // and the rate-limit headers straight through to the client.
        $exceptions->render(function (ThrottleRequestsException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return ApiResponse::error(
                'Too many attempts. Please wait a moment and try again.',
                null,
                429,
                $e->getHeaders()
            );
        });

        // 403 and every other HTTP-level failure: spatie's UnauthorizedException
        // from `permission:` / `role:`, AccessDeniedHttpException from a policy,
        // abort(403), 405 from a mismatched verb, 419, and so on.
        $exceptions->render(function (HttpException $e, $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $status = $e->getStatusCode();

            // Fallback only when the exception carries no message of its own
            // (abort(403) with no text, a bare 405, …).
            $fallback = match ($status) {
                401 => 'Your session has expired. Please sign in again.',
                403 => 'You do not have permission to perform this action.',
                404 => 'The requested resource was not found.',
                405 => 'This request method is not supported for this endpoint.',
                419 => 'Your session expired. Please refresh the page and try again.',
                429 => 'Too many attempts. Please wait a moment and try again.',
                default => 'The request could not be completed.',
            };

            return ApiResponse::error(
                $e->getMessage() ?: $fallback,
                null,
                $status,
                $e->getHeaders()
            );
        });

        // Last resort — anything not handled above (a TypeError, a failed DB
        // connection, a null dereference). Fixed, non-technical copy; the real
        // exception is logged server-side only.
        $exceptions->render(function (Throwable $e, $request) {
            if (! $request->is('api/*') || $e instanceof HttpResponseException) {
                return null;
            }

            return ApiResponse::error('Something went wrong on our end. Please try again later.', null, 500);
        });
    })->create();
