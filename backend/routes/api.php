<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\RoleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Laravel's api routing group already prefixes this file with /api, and the
| group below adds /v1 — so every endpoint here lives at /api/v1/...
|
| Versioning the path (rather than a header) means an older Flutter build in
| the wild keeps hitting routes that still exist when v2 ships.
|
| Two rules hold for everything in this file:
|
|   1. Success and failure both use the ApiResponse envelope, so the client
|      never has to guess at a response shape.
|   2. Authorization happens here, on the server. Flutter hiding a button is
|      a convenience; `permission:` on the route is the actual boundary.
|
*/

Route::prefix('v1')->group(function () {

    /* ------------------------------------------------------ public */

    // Credential exchange. Throttled by the named limiter configured in
    // config/rate_limiting.php - 5 attempts per client IP per minute. Keyed
    // on IP rather than email so an attacker cannot lock a known account out
    // by hammering it; a single address can still only guess so fast.
    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:login');

    // Built, validated and routed — but answered 501 until a real mailer is
    // configured and PASSWORD_RESET_ENABLED=true. See PasswordResetController.
    Route::post('auth/forgot-password', [PasswordResetController::class, 'forgotPassword'])
        ->middleware('throttle:password_reset');

    Route::post('auth/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:password_reset');

    /* ------------------------------------------- bearer-token only */

    Route::middleware('auth:sanctum')->group(function () {

        // Who am I / sign out / change password.
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/change-password', [AuthController::class, 'changePassword']);

        // Device & session management foundation.
        Route::get('auth/sessions', [AuthController::class, 'sessions']);
        Route::delete('auth/sessions/{id}', [AuthController::class, 'revokeSession'])
            ->whereNumber('id');

        // The Phase 3 proof that RBAC is enforced server-side: without
        // roles.view this 403s no matter what the Flutter UI chooses to show.
        Route::get('roles', [RoleController::class, 'index'])
            ->middleware('permission:roles.view');
    });
});
